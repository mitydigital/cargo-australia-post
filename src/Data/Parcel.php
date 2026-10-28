<?php

namespace MityDigital\CargoAustraliaPost\Data;

/**
 * Represents a parcel for Australia Post postage calculations.
 *
 * Dimensions are measured in centimetres (cm).
 * Weight is measured in kilograms (kg).
 */
readonly class Parcel
{
    public function __construct(
        public float $width,
        public float $height,
        public float $length,
        public float $weight,
    ) {}

    /**
     * Convert the parcel to Australia Post API parameters.
     *
     * @return array<string, float>
     */
    public function toArray(): array
    {
        return [
            'width' => $this->width,
            'height' => $this->height,
            'length' => $this->length,
            'weight' => $this->weight,
        ];
    }
}
