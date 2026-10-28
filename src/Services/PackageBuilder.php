<?php

namespace MityDigital\CargoAustraliaPost\Services;

use DuncanMcClean\Cargo\Contracts\Cart\Cart;
use MityDigital\CargoAustraliaPost\Data\Parcel;

class PackageBuilder
{
    protected const DOMESTIC_MAX_WEIGHT = 22;

    protected const INTERNATIONAL_MAX_WEIGHT = 20;

    protected const MAX_DIMENSION = 105;

    protected const MAX_CUBIC_METRES = 0.25;

    protected const MAX_GIRTH = 140;

    protected const MIN_DIMENSION = 1;

    protected const MIN_WEIGHT = 0.01;

    /**
     * An empty list means there are no physical products. Null means the cart
     * is empty, invalid, or cannot be shipped. Dimensions are cm; weight is kg.
     *
     * @return list<Parcel>|null
     */
    public function build(Cart $cart): ?array
    {
        $country = $cart->shippingAddress()->country()?->extra()['iso2'] ?? null;

        if ($country === null) {
            return null;
        }

        $isDomestic = $country === 'AU';

        $items = [];
        $mappings = config('cargo-australia-post.mappings', []);

        foreach ($cart->lineItems() as $lineItem) {
            $product = $lineItem->product();
            $quantity = $lineItem->quantity();

            if (! $product || ! is_int($quantity) || $quantity < 1) {
                return null;
            }

            if ($product->get('type', 'physical') === 'digital') {
                continue;
            }

            $measurements = [];

            foreach (['width' => 'width', 'height' => 'height', 'length' => 'depth', 'weight' => 'weight'] as $dimension => $defaultField) {
                $value = $product->get($mappings[$dimension] ?? $defaultField);

                if (! is_numeric($value) || ! is_finite((float) $value) || (float) $value <= 0) {
                    return null;
                }

                $measurements[$dimension] = (float) $value;
            }

            $item = new Parcel(...$measurements);

            if (! $this->isShippable($item, $isDomestic)) {
                return null;
            }

            for ($index = 0; $index < $quantity; $index++) {
                $items[] = $item;
            }
        }

        if ($items === []) {
            return $cart->lineItems()->isEmpty() ? null : [];
        }

        usort($items, fn (Parcel $first, Parcel $second): int => $first->height <=> $second->height);

        $parcels = [];

        foreach ($items as $item) {
            foreach ($parcels as $index => $parcel) {
                $candidate = $this->combine($parcel, $item);

                if ($this->isShippable($candidate, $isDomestic)) {
                    $parcels[$index] = $candidate;

                    continue 2;
                }
            }

            $parcels[] = $item;
        }

        return array_map(fn (Parcel $parcel): Parcel => $this->applyMinimums($parcel), $parcels);
    }

    protected function combine(Parcel $parcel, Parcel $item): Parcel
    {
        $shortest = min($item->height, $item->length, $item->width);
        $stackAlong = match ($shortest) {
            $item->height => 'height',
            $item->length => 'length',
            default => 'width',
        };

        return new Parcel(
            width: $stackAlong === 'width' ? $parcel->width + $item->width : max($parcel->width, $item->width),
            height: $stackAlong === 'height' ? $parcel->height + $item->height : max($parcel->height, $item->height),
            length: $stackAlong === 'length' ? $parcel->length + $item->length : max($parcel->length, $item->length),
            weight: $parcel->weight + $item->weight,
        );
    }

    protected function isShippable(Parcel $parcel, bool $isDomestic): bool
    {
        $parcel = $this->applyMinimums($parcel);

        if ($parcel->weight > ($isDomestic ? self::DOMESTIC_MAX_WEIGHT : self::INTERNATIONAL_MAX_WEIGHT)
            || max($parcel->height, $parcel->length, $parcel->width) > self::MAX_DIMENSION) {
            return false;
        }

        if ($isDomestic) {
            return $this->cubicMetres($parcel) <= self::MAX_CUBIC_METRES;
        }

        $dimensions = [$parcel->height, $parcel->length, $parcel->width];
        sort($dimensions);

        return ($dimensions[0] + $dimensions[1]) * 2 <= self::MAX_GIRTH;
    }

    protected function cubicMetres(Parcel $parcel): float
    {
        return ($parcel->width / 100) * ($parcel->height / 100) * ($parcel->length / 100);
    }

    protected function applyMinimums(Parcel $parcel): Parcel
    {
        return new Parcel(
            width: max(self::MIN_DIMENSION, $parcel->width),
            height: max(self::MIN_DIMENSION, $parcel->height),
            length: max(self::MIN_DIMENSION, $parcel->length),
            weight: max(self::MIN_WEIGHT, $parcel->weight),
        );
    }
}
