<?php

namespace MityDigital\CargoAustraliaPost\Services;

use DuncanMcClean\Cargo\Contracts\Cart\Cart;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use MityDigital\CargoAustraliaPost\Exceptions\AustraliaPostApiException;
use MityDigital\CargoAustraliaPost\Services\AustraliaPost\PostageApi;

class AustraliaPostService
{
    public function __construct(
        protected PostageApi $postage,
        protected PackageBuilder $packageBuilder,
    ) {}

    protected function fromPostcode(): string
    {
        return (string) config('cargo-australia-post.from_postcode', '');
    }

    /** @return Collection<int, array{service_code: string, label: string, price: int}> */
    public function getQuotes(Cart $cart): Collection
    {
        $address = $cart->shippingAddress();
        $countryCode = $address->country()?->extra()['iso2'] ?? null;

        if ($countryCode === null) {
            return collect();
        }

        $isDomestic = $countryCode === 'AU';
        $services = $this->availableServices($cart, $isDomestic);

        if ($services === []) {
            return collect();
        }

        $parcels = $this->packageBuilder->build($cart);

        if ($parcels === null || $parcels === []) {
            return collect();
        }

        $toPostcode = (string) ($address->postcode ?? '');

        if (! preg_match('/^[0-9]{4}$/', $this->fromPostcode())
            || ($isDomestic && ! preg_match('/^[0-9]{4}$/', $toPostcode))) {
            return collect();
        }

        $calculations = [];
        $serviceCalculations = [];

        foreach ($services as $serviceCode => $label) {
            foreach ($parcels as $parcel) {
                $calculation = $isDomestic
                    ? $this->postage->domesticCalculation($serviceCode, $this->fromPostcode(), $toPostcode, $parcel)
                    : $this->postage->internationalCalculation($serviceCode, $this->fromPostcode(), $countryCode, $parcel);
                $key = $this->postage->cacheKey($calculation);
                $calculations[$key] = $calculation;
                $serviceCalculations[$serviceCode][] = $key;
            }
        }

        $prices = $this->calculationPrices($calculations);

        return $this->buildQuotes($services, $serviceCalculations, $prices);
    }

    /** @return array<string, string> */
    protected function availableServices(Cart $cart, bool $isDomestic): array
    {
        $services = config('cargo-australia-post.service_codes.'.($isDomestic ? 'domestic' : 'international'), []);
        $field = config('cargo-australia-post.mappings.available_services', 'australia_post_services');

        foreach ($cart->lineItems() as $lineItem) {
            $product = $lineItem->product();

            if (! $product) {
                return [];
            }

            if ($product->get('type', 'physical') === 'digital') {
                continue;
            }

            $allowed = $product->get($field);

            if ($allowed === null) {
                continue;
            }

            if (! is_array($allowed) || ! array_is_list($allowed)) {
                return [];
            }

            $services = array_filter($services, fn (string $code): bool => in_array($code, $allowed, true), ARRAY_FILTER_USE_KEY);
        }

        return $services;
    }

    /**
     * @param  array<string, array{endpoint: string, query: array<string, mixed>}>  $calculations
     * @return array<string, int>
     */
    protected function calculationPrices(array $calculations): array
    {
        $prices = [];
        $ttl = (int) config('cargo-australia-post.cache.ttl', 300);
        $uncached = [];

        foreach ($calculations as $key => $calculation) {
            $price = $ttl > 0 ? Cache::get($key) : null;

            if (is_int($price) && $price >= 0) {
                $prices[$key] = $price;
            } else {
                $uncached[$key] = $calculation;
            }
        }

        foreach ($this->postage->calculateMany($uncached) as $key => $response) {
            $price = $this->priceInCents($response['postage_result']['total_cost'] ?? null);

            if ($price === null) {
                Log::error('Australia Post API returned an invalid price', [
                    'url' => $calculations[$key]['endpoint'],
                    'query' => $calculations[$key]['query'],
                    'response' => $response,
                ]);

                continue;
            }

            $prices[$key] = $price;

            if ($ttl > 0) {
                Cache::put($key, $price, $ttl);
            }
        }

        return $prices;
    }

    /**
     * @param  array<string, string>  $services
     * @param  array<string, list<string>>  $serviceCalculations
     * @param  array<string, int>  $prices
     * @return Collection<int, array{service_code: string, label: string, price: int}>
     */
    protected function buildQuotes(array $services, array $serviceCalculations, array $prices): Collection
    {
        $quotes = collect();

        foreach ($serviceCalculations as $serviceCode => $keys) {
            $total = 0;

            foreach ($keys as $key) {
                if (! array_key_exists($key, $prices)) {
                    continue 2;
                }

                if ($prices[$key] > PHP_INT_MAX - $total) {
                    Log::error('Australia Post quote total exceeds the supported amount', ['service_code' => $serviceCode]);

                    continue 2;
                }

                $total += $prices[$key];
            }

            $quotes->push(['service_code' => $serviceCode, 'label' => $services[$serviceCode], 'price' => $total]);
        }

        return $quotes;
    }

    protected function priceInCents(mixed $amount): ?int
    {
        if ((! is_string($amount) && ! is_int($amount) && ! is_float($amount))
            || ! preg_match('/^([0-9]+)(?:\.([0-9]{1,2}))?$/D', (string) $amount, $matches)) {
            return null;
        }

        $cents = ltrim($matches[1].str_pad($matches[2] ?? '', 2, '0'), '0');
        $maximum = (string) PHP_INT_MAX;

        if (strlen($cents) > strlen($maximum)
            || (strlen($cents) === strlen($maximum) && strcmp($cents, $maximum) > 0)) {
            return null;
        }

        return (int) $cents;
    }

    public function getDomesticServices(string $toPostcode): array
    {
        $response = $this->postage->getDomesticServices($this->fromPostcode(), $toPostcode);

        return $this->serviceList($response);
    }

    public function getInternationalServices(string $countryCode): array
    {
        $response = $this->postage->getInternationalServices($this->fromPostcode(), $countryCode);

        return $this->serviceList($response);
    }

    /** @return list<array{code: string, name: string}> */
    protected function serviceList(array $response): array
    {
        $services = $response['services']['service'] ?? null;

        if (is_array($services) && array_is_list($services)) {
            foreach ($services as $service) {
                if (! is_array($service) || ! is_string($service['code'] ?? null) || ! is_string($service['name'] ?? null)) {
                    $services = null;

                    break;
                }
            }

            if ($services !== null) {
                return $services;
            }
        }

        Log::error('Australia Post API returned an invalid service list', ['response' => $response]);

        throw new AustraliaPostApiException('The Australia Post API returned an invalid service list.');
    }
}
