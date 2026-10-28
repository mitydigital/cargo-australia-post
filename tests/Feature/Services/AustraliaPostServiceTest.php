<?php

use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use MityDigital\CargoAustraliaPost\Services\AustraliaPost\AustraliaPostApiConnector;
use MityDigital\CargoAustraliaPost\Services\AustraliaPostService;
use MityDigital\CargoAustraliaPost\ShippingMethods\AustraliaPost;
use Psr\Http\Message\RequestInterface;

beforeEach(function (): void {
    config()->set('cargo-australia-post.from_postcode', '3000');
    config()->set('cargo-australia-post.api_key', 'test-account');
    config()->set('cargo-australia-post.service_codes.domestic', ['REGULAR' => 'Standard', 'EXPRESS' => 'Express']);
    config()->set('cargo-australia-post.service_codes.international', ['INT_STANDARD' => 'Overseas']);
    Http::preventStrayRequests();
});

it('sums every parcel for each service and reuses individual calculations', function (): void {
    $cart = $this->cartWithProducts([
        ['data' => ['weight' => 12]],
        ['data' => ['weight' => 13]],
    ])->set('shipping_address', ['country' => 'AUS', 'postcode' => '2000']);
    Http::fake(['digitalapi.auspost.com.au/postage/parcel/domestic/calculate*' => function (Request $request) {
        expect($request->hasHeader('auth-key', 'test-account'))->toBeTrue();
        expect($request['from_postcode'])->toBe('3000');
        expect($request['to_postcode'])->toBe('2000');
        expect($request['length'])->toBe(20.0);

        return Http::response(['postage_result' => ['total_cost' => $request['weight'] === 12.0 ? '10.10' : '20.20']]);
    }]);

    $service = app(AustraliaPostService::class);
    $quotes = $service->getQuotes($cart);
    $cart->lineItems()->pop();
    $smallerQuotes = $service->getQuotes($cart);

    expect($quotes->all())->toBe([
        ['service_code' => 'REGULAR', 'label' => 'Standard', 'price' => 3030],
        ['service_code' => 'EXPRESS', 'label' => 'Express', 'price' => 3030],
    ]);
    expect($smallerQuotes->pluck('price')->all())->toBe([1010, 1010]);
    Http::assertSentCount(4);
});

it('deduplicates identical parcels but counts each in the total', function (): void {
    $cart = $this->cartWithProducts([['quantity' => 3, 'data' => ['weight' => 12]]])
        ->set('shipping_address', ['country' => 'AUS', 'postcode' => '2000']);
    Http::fake(['digitalapi.auspost.com.au/postage/parcel/domestic/calculate*' => Http::response(['postage_result' => ['total_cost' => '0.29']])]);

    $quotes = app(AustraliaPostService::class)->getQuotes($cart);

    expect($quotes->pluck('price')->all())->toBe([87, 87]);
    Http::assertSentCount(2);
});

it('uses only international services and sends the iso2 country', function (string $country, string $countryCode): void {
    $cart = $this->cartWithProducts([[]], $country);
    Http::fake(['digitalapi.auspost.com.au/postage/parcel/international/calculate*' => Http::response(['postage_result' => ['total_cost' => 19.95]])]);

    $quotes = app(AustraliaPostService::class)->getQuotes($cart);

    expect($quotes->all())->toBe([['service_code' => 'INT_STANDARD', 'label' => 'Overseas', 'price' => 1995]]);
    expect($cart->shippingAddress()->toArray()['country'])->toBe($country);
    Http::assertSent(fn (Request $request): bool => $request['country_code'] === $countryCode
        && $request['service_code'] === 'INT_STANDARD' && $request['weight'] === 1.0);
})->with('international destinations');

it('unknown country returns no quotes even for digital products', function (): void {
    $cart = $this->cartWithProducts([['data' => ['type' => 'digital']]], 'ZZZ');

    $quotes = app(AustraliaPostService::class)->getQuotes($cart);

    expect($quotes->all())->toBe([]);
    Http::assertNothingSent();
});

it('omits only the incomplete service and caches its successful parcels', function (): void {
    $cart = $this->cartWithProducts([
        ['data' => ['weight' => 12]], ['data' => ['weight' => 13]],
    ])->set('shipping_address', ['country' => 'AUS', 'postcode' => '2000']);
    Http::fake(['digitalapi.auspost.com.au/postage/parcel/domestic/calculate*' => fn (Request $request) => $request['service_code'] === 'REGULAR' && $request['weight'] === 13.0
            ? Http::response(['error' => ['message' => 'Unavailable']], 503)
            : Http::response(['postage_result' => ['total_cost' => '10.00']])]);

    $service = app(AustraliaPostService::class);
    $quotes = $service->getQuotes($cart);
    $service->getQuotes($cart);

    expect($quotes->all())->toBe([['service_code' => 'EXPRESS', 'label' => 'Express', 'price' => 2000]]);
    Http::assertSentCount(7);
});

it('retries transient pooled failures', function (int $status): void {
    config()->set('cargo-australia-post.service_codes.domestic', ['REGULAR' => 'Standard']);
    $cart = $this->cartWithProducts([[]])->set('shipping_address', ['country' => 'AUS', 'postcode' => '2000']);
    Http::fake(['digitalapi.auspost.com.au/postage/parcel/domestic/calculate*' => Http::sequence()
        ->push([], $status)->push(['postage_result' => ['total_cost' => '12.34']])]);

    $quotes = app(AustraliaPostService::class)->getQuotes($cart);

    expect($quotes->first()['price'])->toBe(1234);
    Http::assertSentCount(2);
})->with('transient calculation failures');

it('connection failure does not discard other services or get cached', function (): void {
    $cart = $this->cartWithProducts([[]])->set('shipping_address', ['country' => 'AUS', 'postcode' => '2000']);
    $failedAttempts = 0;
    Http::fake(['digitalapi.auspost.com.au/postage/parcel/domestic/calculate*' => function (Request $request) use (&$failedAttempts) {
        if ($request['service_code'] === 'REGULAR') {
            $failedAttempts++;

            return Http::failedConnection();
        }

        return Http::response(['postage_result' => ['total_cost' => '1.00']]);
    }]);

    $service = app(AustraliaPostService::class);
    $quotes = $service->getQuotes($cart);
    $service->getQuotes($cart);

    expect($quotes->pluck('service_code')->all())->toBe(['EXPRESS']);
    expect($failedAttempts)->toBe(4);
    Http::assertSentCount(1);
});

it('malformed or incomplete prices are not quoted or cached', function (mixed $response): void {
    Log::spy();
    config()->set('cargo-australia-post.service_codes.domestic', ['REGULAR' => 'Standard']);
    $cart = $this->cartWithProducts([[]])->set('shipping_address', ['country' => 'AUS', 'postcode' => '2000']);
    Http::fake(['digitalapi.auspost.com.au/postage/parcel/domestic/calculate*' => Http::response($response)]);

    $service = app(AustraliaPostService::class);
    expect($service->getQuotes($cart)->all())->toBe([]);
    expect($service->getQuotes($cart)->all())->toBe([]);
    Http::assertSentCount(2);
    Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context): bool => str_starts_with($message, 'Australia Post API returned an invalid')
        && $context['url'] === 'postage/parcel/domestic/calculate'
        && $context['query']['service_code'] === 'REGULAR'
        && $context['query']['weight'] === 1.0
        && ! str_contains(json_encode($context), 'test-account'));
})->with('invalid quote responses');

it('expiration and disabled caching', function (): void {
    config()->set('cargo-australia-post.service_codes.domestic', ['REGULAR' => 'Standard']);
    config()->set('cargo-australia-post.cache.ttl', 5);
    $this->freezeTime();
    $cart = $this->cartWithProducts([[]])->set('shipping_address', ['country' => 'AUS', 'postcode' => '2000']);
    Http::fake(['digitalapi.auspost.com.au/postage/parcel/domestic/calculate*' => Http::response(['postage_result' => ['total_cost' => '0.00']])]);
    $service = app(AustraliaPostService::class);

    expect($service->getQuotes($cart)->first()['price'])->toBe(0);
    $service->getQuotes($cart);
    $this->travel(6)->seconds();
    $service->getQuotes($cart);
    config()->set('cargo-australia-post.cache.ttl', 0);
    $service->getQuotes($cart);
    $service->getQuotes($cart);

    Http::assertSentCount(4);
});

it('preserves package builder rejections and ignores digital products', function (): void {
    $service = app(AustraliaPostService::class);
    $invalid = $this->cartWithProducts([['data' => ['weight' => 23]]]);
    expect($service->getQuotes($invalid)->all())->toBe([]);
    $digital = $this->cartWithProducts([['data' => ['type' => 'digital']]]);

    expect($service->getQuotes($digital)->all())->toBe([]);
    Http::assertNothingSent();
});

it('missing postcode and no configured services make no requests', function (): void {
    $cart = $this->cartWithProducts([[]]);
    $service = app(AustraliaPostService::class);
    expect($service->getQuotes($cart)->all())->toBe([]);
    $cart->set('shipping_address', ['country' => 'AUS', 'postcode' => '2000']);
    config()->set('cargo-australia-post.service_codes.domestic', []);

    expect($service->getQuotes($cart)->all())->toBe([]);
    Http::assertNothingSent();
});

it('shipping options keep service identity when labels change', function (): void {
    $cart = $this->cartWithProducts([[]])->set('shipping_address', ['country' => 'AUS', 'postcode' => '2000']);
    Http::fake(['digitalapi.auspost.com.au/postage/parcel/domestic/calculate*' => Http::response(['postage_result' => ['total_cost' => '10.00']])]);
    $method = new AustraliaPost;

    $options = $method->options($cart);
    config()->set('cargo-australia-post.service_codes.domestic.REGULAR', 'Renamed');
    $renamed = $method->options($cart);

    expect($options->first()->handle())->toBe('australia_post_REGULAR');
    expect($renamed->first()->handle())->toBe($options->first()->handle());
    expect($renamed->first()->name())->toBe('Renamed');
    expect($options->first()->price())->toBe(1000);
    expect($options->first()->shippingMethod())->toBe($method->handle());
    Http::assertSentCount(2);
});

it('dispatches all uncached calculations before waiting for responses', function (): void {
    $cart = $this->cartWithProducts([
        ['data' => ['weight' => 12]], ['data' => ['weight' => 13]],
    ])->set('shipping_address', ['country' => 'AUS', 'postcode' => '2000']);
    $started = 0;
    Http::globalMiddleware(function (callable $handler) use (&$started): callable {
        return function (RequestInterface $request, array $options) use (&$started): Promise {
            $started++;
            expect($request->getUri()->getPath())->toBe('/postage/parcel/domestic/calculate');
            expect($options['timeout'])->toBe(10);
            $promise = null;
            $promise = new Promise(function () use (&$promise, &$started): void {
                expect($started)->toBe(4);
                $promise->resolve(new Response(200, ['Content-Type' => 'application/json'], '{"postage_result":{"total_cost":"1.00"}}'));
            });

            return $promise;
        };
    });

    $quotes = app(AustraliaPostService::class)->getQuotes($cart);

    expect($quotes->pluck('price')->all())->toBe([200, 200]);
    expect($started)->toBe(4);
});

it('calculation keys are canonical and include account endpoint and all parameters', function (): void {
    $connector = new AustraliaPostApiConnector('first-account');
    $parameters = ['service_code' => 'REGULAR', 'weight' => 1, 'to_postcode' => '2000'];
    $key = $connector->cacheKey('domestic', $parameters);

    expect($connector->cacheKey('domestic', ['to_postcode' => '2000', 'weight' => 1, 'service_code' => 'REGULAR']))->toBe($key);
    expect((new AustraliaPostApiConnector('second-account'))->cacheKey('domestic', $parameters))->not->toBe($key);
    expect($connector->cacheKey('international', $parameters))->not->toBe($key);
    expect($connector->cacheKey('domestic', [...$parameters, 'extra_cover' => 100]))->not->toBe($key);
    expect($connector->cacheKey('domestic', [...$parameters, 'weight' => 2]))->not->toBe($key);
    expect($key)->toMatch('/:[a-f0-9]{64}$/');
});

it('does not retry permanent errors or discard healthy services', function (int $status): void {
    Log::spy();
    $cart = $this->cartWithProducts([[]])->set('shipping_address', ['country' => 'AUS', 'postcode' => '2000']);
    Http::fake(['digitalapi.auspost.com.au/postage/parcel/domestic/calculate*' => fn (Request $request) => $request['service_code'] === 'REGULAR'
            ? Http::response(['error' => ['errorMessage' => 'Unavailable']], $status)
            : Http::response(['postage_result' => ['total_cost' => '10.00']])]);

    $quotes = app(AustraliaPostService::class)->getQuotes($cart);

    expect($quotes->all())->toBe([['service_code' => 'EXPRESS', 'label' => 'Express', 'price' => 1000]]);
    Http::assertSentCount(2);
    Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context): bool => $message === 'Australia Post API calculation failed'
        && $context['status'] === $status
        && $context['query']['service_code'] === 'REGULAR'
        && str_contains($context['body'], 'Unavailable'));
})->with('permanent calculation failures');

dataset('international destinations', function (): array {
    return [
        'New Zealand' => ['NZL', 'NZ'],
        'Germany' => ['DEU', 'DE'],
        'Austria' => ['AUT', 'AT'],
        'United Kingdom' => ['GBR', 'GB'],
    ];
});

dataset('invalid quote responses', function (): array {
    return [
        'invalid json' => ['not json'],
        'missing result' => [[]],
        'missing price' => [['postage_result' => []]],
        'error' => [['error' => 'Invalid', 'postage_result' => ['total_cost' => '1.00']]],
        'negative' => [['postage_result' => ['total_cost' => '-1.00']]],
        'extra decimals' => [['postage_result' => ['total_cost' => '1.001']]],
        'text' => [['postage_result' => ['total_cost' => 'free']]],
        'boolean' => [['postage_result' => ['total_cost' => true]]],
        'overflow' => [['postage_result' => ['total_cost' => (string) PHP_INT_MAX]]],
    ];
});

dataset('permanent calculation failures', function (): array {
    return ['bad request' => [400], 'unauthorized' => [401], 'forbidden' => [403], 'not found' => [404], 'invalid parameters' => [422]];
});

dataset('transient calculation failures', function (): array {
    return ['rate limited' => [429], 'server failure' => [503]];
});

it('offers no shipping options for digital-only carts', function (string $country): void {
    $cart = $this->cartWithProducts([['data' => ['type' => 'digital', 'weight' => null]]], $country)
        ->set('shipping_address', ['country' => $country, 'postcode' => '2000']);

    expect((new AustraliaPost)->options($cart)->all())->toBe([]);
    Http::assertNothingSent();
})->with(['domestic' => 'AUS', 'international' => 'NZL']);

it('quotes only physical products in a mixed cart', function (): void {
    $cart = $this->cartWithProducts([
        ['quantity' => 3, 'data' => ['type' => 'digital', 'australia_post_services' => [], 'weight' => null]],
        ['data' => ['weight' => 2]],
    ])->set('shipping_address', ['country' => 'AUS', 'postcode' => '2000']);
    Http::fake(['digitalapi.auspost.com.au/postage/parcel/domestic/calculate*' => Http::response([
        'postage_result' => ['total_cost' => '12.34'],
    ])]);

    $quotes = app(AustraliaPostService::class)->getQuotes($cart);

    expect($quotes->all())->toBe([
        ['service_code' => 'REGULAR', 'label' => 'Standard', 'price' => 1234],
        ['service_code' => 'EXPRESS', 'label' => 'Express', 'price' => 1234],
    ]);
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => $request['weight'] === 2.0);
});

it('removes a service for the entire order when one product does not permit it', function (): void {
    $cart = $this->cartWithProducts([
        ['data' => ['australia_post_services' => ['REGULAR'], 'weight' => 12]],
        ['data' => ['weight' => 13]],
    ])->set('shipping_address', ['country' => 'AUS', 'postcode' => '2000']);
    Http::fake(['digitalapi.auspost.com.au/postage/parcel/domestic/calculate*' => Http::response([
        'postage_result' => ['total_cost' => '10.00'],
    ])]);

    $quotes = app(AustraliaPostService::class)->getQuotes($cart);

    expect($quotes->all())->toBe([['service_code' => 'REGULAR', 'label' => 'Standard', 'price' => 2000]]);
    Http::assertSentCount(2);
    Http::assertNotSent(fn (Request $request): bool => $request['service_code'] === 'EXPRESS');
    Http::assertSent(fn (Request $request): bool => $request['weight'] === 12.0);
    Http::assertSent(fn (Request $request): bool => $request['weight'] === 13.0);
});

it('makes no requests when physical products have no common permitted service', function (array $products, string $country): void {
    $cart = $this->cartWithProducts($products, $country)
        ->set('shipping_address', ['country' => $country, 'postcode' => '2000']);

    expect((new AustraliaPost)->options($cart)->all())->toBe([]);
    Http::assertNothingSent();
})->with([
    'none allowed' => [[['data' => ['australia_post_services' => []]]], 'AUS'],
    'no overlap' => [[['data' => ['australia_post_services' => ['REGULAR']]], ['data' => ['australia_post_services' => ['EXPRESS']]]], 'AUS'],
    'domestic-only overseas' => [[['data' => ['australia_post_services' => ['REGULAR', 'EXPRESS']]]], 'NZL'],
    'international-only domestic' => [[['data' => ['australia_post_services' => ['INT_STANDARD']]]], 'AUS'],
    'removed service' => [[['data' => ['australia_post_services' => ['REMOVED']]]], 'AUS'],
    'invalid saved value' => [[['data' => ['australia_post_services' => true]]], 'AUS'],
]);

it('uses the configured service mapping and quotes the correct destination group', function (string $country, string $endpoint, string $code): void {
    config()->set('cargo-australia-post.mappings.available_services', 'permitted_postage');
    $cart = $this->cartWithProducts([
        ['data' => ['permitted_postage' => ['REGULAR', 'INT_STANDARD'], 'australia_post_services' => []]],
    ], $country)->set('shipping_address', ['country' => $country, 'postcode' => '2000']);
    Http::fake(["digitalapi.auspost.com.au/postage/parcel/{$endpoint}/calculate*" => Http::response([
        'postage_result' => ['total_cost' => '15.00'],
    ])]);

    $quotes = app(AustraliaPostService::class)->getQuotes($cart);

    expect($quotes->pluck('service_code')->all())->toBe([$code]);
    Http::assertSentCount(1);
})->with(['domestic' => ['AUS', 'domestic', 'REGULAR'], 'international' => ['NZL', 'international', 'INT_STANDARD']]);

it('adds new configured services only to products using the default and keeps labels current', function (): void {
    $defaultCart = $this->cartWithProducts([['data' => ['australia_post_services' => null]]])
        ->set('shipping_address', ['country' => 'AUS', 'postcode' => '2000']);
    Http::fake(['digitalapi.auspost.com.au/postage/parcel/domestic/calculate*' => Http::response([
        'postage_result' => ['total_cost' => '10.00'],
    ])]);
    $service = app(AustraliaPostService::class);
    expect($service->getQuotes($defaultCart)->pluck('service_code')->all())->toBe(['REGULAR', 'EXPRESS']);

    config()->set('cargo-australia-post.service_codes.domestic.NEW', 'New service');
    config()->set('cargo-australia-post.service_codes.domestic.REGULAR', 'Renamed');
    $defaultQuotes = $service->getQuotes($defaultCart);
    $defaultCart->lineItems()->first()->product()->set('australia_post_services', ['REGULAR'])->save();
    $restrictedQuotes = $service->getQuotes($defaultCart);

    expect($defaultQuotes->pluck('service_code')->all())->toBe(['REGULAR', 'EXPRESS', 'NEW'])
        ->and($restrictedQuotes->all())->toBe([['service_code' => 'REGULAR', 'label' => 'Renamed', 'price' => 1000]]);
    Http::assertSentCount(3);
});

it('does not omit physical products with old calculation-exclusion values', function (): void {
    $cart = $this->cartWithProducts([
        ['data' => ['exclude_from_calculations' => true, 'weight' => 12]],
        ['data' => ['weight' => 13]],
    ])->set('shipping_address', ['country' => 'AUS', 'postcode' => '2000']);
    Http::fake(['digitalapi.auspost.com.au/postage/parcel/domestic/calculate*' => Http::response([
        'postage_result' => ['total_cost' => '10.00'],
    ])]);

    expect(app(AustraliaPostService::class)->getQuotes($cart)->pluck('price')->all())->toBe([2000, 2000]);
    Http::assertSentCount(4);
});
