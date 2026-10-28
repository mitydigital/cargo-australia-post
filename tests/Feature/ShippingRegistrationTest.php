<?php

use DuncanMcClean\Cargo\Facades\ShippingMethod;
use Illuminate\Support\Facades\Http;
use MityDigital\CargoAustraliaPost\ShippingMethods\AustraliaPost;

it('exposes Australia Post through Cargo only when enabled in its configuration', function (): void {
    Http::preventStrayRequests();
    config()->set('statamic.cargo.shipping.methods', []);
    expect(ShippingMethod::find('australia_post'))->toBeNull();

    config()->set('statamic.cargo.shipping.methods.australia_post', []);
    config()->set('cargo-australia-post.from_postcode', '3000');
    config()->set('cargo-australia-post.service_codes.domestic', ['AUS_PARCEL_REGULAR' => 'Parcel Post']);
    $cart = $this->cartWithProducts([[]])->set('shipping_address', ['country' => 'AUS', 'postcode' => '2000']);
    Http::fake(['digitalapi.auspost.com.au/postage/parcel/domestic/calculate*' => Http::response([
        'postage_result' => ['total_cost' => '12.34'],
    ])]);

    $method = ShippingMethod::find('australia_post');
    expect($method)->toBeInstanceOf(AustraliaPost::class);
    $option = $method->options($cart)->sole();

    expect($option->shippingMethod())->toBe('australia_post');
    expect($option->handle())->toBe('australia_post_AUS_PARCEL_REGULAR');
    expect($option->name())->toBe('Parcel Post');
    expect($option->price())->toBe(1234);
    Http::assertSentCount(1);
});
