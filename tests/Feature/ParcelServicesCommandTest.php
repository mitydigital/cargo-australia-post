<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

beforeEach(function (): void {
    config()->set('cargo-australia-post.from_postcode', '3000');
    config()->set('cargo-australia-post.api_key', 'test-account');
    Http::preventStrayRequests();
});

it('lists service codes and names', function (string $destination, array $arguments, string $parameter, string $value): void {
    Http::fake(["digitalapi.auspost.com.au/postage/parcel/{$destination}/service*" => Http::response([
        'services' => ['service' => [['code' => 'REGULAR', 'name' => 'Parcel Post']]],
    ])]);

    $this->artisan("cargo-australia-post:{$destination}-parcel-services", $arguments)
        ->expectsTable(['Code', 'Name'], [['REGULAR', 'Parcel Post']])
        ->assertExitCode(0);

    Http::assertSent(fn (Request $request): bool => $request['from_postcode'] === '3000'
        && $request[$parameter] === $value && $request->hasHeader('auth-key', 'test-account'));
})->with('service discovery destinations');

it('reports invalid responses and returns failure', function (string $destination, array $arguments, mixed $body, int $status, string $message, int $attempts): void {
    Sleep::fake();
    Log::spy();
    Http::fake(["digitalapi.auspost.com.au/postage/parcel/{$destination}/service*" => Http::response($body, $status)]);

    $this->artisan("cargo-australia-post:{$destination}-parcel-services", $arguments)
        ->expectsOutput($message)
        ->assertExitCode(1);

    Http::assertSentCount($attempts);
    Log::shouldHaveReceived('error');
})->with('service commands')->with('invalid service responses');

it('reports connection failures', function (string $destination, array $arguments): void {
    Sleep::fake();
    $attempts = 0;
    Http::fake(["digitalapi.auspost.com.au/postage/parcel/{$destination}/service*" => function () use (&$attempts) {
        $attempts++;

        return Http::failedConnection();
    }]);

    $this->artisan("cargo-australia-post:{$destination}-parcel-services", $arguments)
        ->expectsOutput('The Australia Post API could not be reached.')
        ->assertExitCode(1);

    expect($attempts)->toBe(2);
})->with('service commands');

it('accepts a valid empty service list', function (string $destination, array $arguments): void {
    Http::fake(["digitalapi.auspost.com.au/postage/parcel/{$destination}/service*" => Http::response([
        'services' => ['service' => []],
    ])]);

    $this->artisan("cargo-australia-post:{$destination}-parcel-services", $arguments)
        ->expectsTable(['Code', 'Name'], [])
        ->assertExitCode(0);

    Http::assertSentCount(1);
})->with('service commands');

dataset('service discovery destinations', function (): array {
    return [
        'domestic' => ['domestic', ['toPostcode' => '2000'], 'to_postcode', '2000'],
        'international' => ['international', ['countryCode' => 'NZ'], 'country_code', 'NZ'],
    ];
});

dataset('service commands', [
    'domestic' => ['domestic', ['toPostcode' => '2000']],
    'international' => ['international', ['countryCode' => 'NZ']],
]);

dataset('invalid service responses', function (): array {
    $invalidResponse = 'The Australia Post API returned an invalid response.';
    $invalidServices = 'The Australia Post API returned an invalid service list.';

    return [
        'invalid json' => ['not json', 200, $invalidResponse, 1],
        'scalar json' => ['"unexpected"', 200, $invalidResponse, 1],
        'api error' => [['error' => ['errorMessage' => 'Unavailable']], 200, $invalidResponse, 1],
        'missing services' => [[], 200, $invalidServices, 1],
        'invalid services' => [['services' => ['service' => 'unexpected']], 200, $invalidServices, 1],
        'missing code' => [['services' => ['service' => [['name' => 'Parcel Post']]]], 200, $invalidServices, 1],
        'missing name' => [['services' => ['service' => [['code' => 'REGULAR']]]], 200, $invalidServices, 1],
        'invalid row' => [['services' => ['service' => [null]]], 200, $invalidServices, 1],
        'unauthorized' => [[], 401, 'The Australia Post API returned an HTTP error (401).', 1],
        'server failure' => [[], 503, 'The Australia Post API returned an HTTP error (503).', 2],
    ];
});
