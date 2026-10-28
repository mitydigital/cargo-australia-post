<?php

namespace MityDigital\CargoAustraliaPost\Services\AustraliaPost;

use MityDigital\CargoAustraliaPost\Data\Parcel;

class PostageApi
{
    public function __construct(
        protected AustraliaPostApiConnector $connector,
    ) {}

    /** @return array{endpoint: string, query: array<string, mixed>} */
    public function domesticCalculation(string $serviceCode, string $fromPostcode, string $toPostcode, Parcel $parcel): array
    {
        return [
            'endpoint' => 'postage/parcel/domestic/calculate',
            'query' => [
                'service_code' => $serviceCode,
                'from_postcode' => $fromPostcode,
                'to_postcode' => $toPostcode,
                ...$parcel->toArray(),
            ],
        ];
    }

    /** @return array{endpoint: string, query: array<string, mixed>} */
    public function internationalCalculation(string $serviceCode, string $fromPostcode, string $countryCode, Parcel $parcel): array
    {
        return [
            'endpoint' => 'postage/parcel/international/calculate',
            'query' => [
                'service_code' => $serviceCode,
                'from_postcode' => $fromPostcode,
                'country_code' => $countryCode,
                'weight' => $parcel->weight,
            ],
        ];
    }

    /** @param array{endpoint: string, query: array<string, mixed>} $calculation */
    public function cacheKey(array $calculation): string
    {
        return $this->connector->cacheKey($calculation['endpoint'], $calculation['query']);
    }

    /**
     * @param  array<string, array{endpoint: string, query: array<string, mixed>}>  $calculations
     * @return array<string, array>
     */
    public function calculateMany(array $calculations): array
    {
        return $this->connector->pool($calculations);
    }

    public function getDomesticServices(string $fromPostcode, string $toPostcode): array
    {
        return $this->connector->get(
            'postage/parcel/domestic/service',
            [
                'from_postcode' => $fromPostcode,
                'to_postcode' => $toPostcode,
                'length' => 10,
                'width' => 10,
                'height' => 10,
                'weight' => 0.1,
            ]
        );
    }

    public function getInternationalServices(string $fromPostcode, string $countryCode): array
    {
        return $this->connector->get(
            'postage/parcel/international/service',
            [
                'from_postcode' => $fromPostcode,
                'country_code' => $countryCode,
                'weight' => 0.1,
            ]
        );
    }
}
