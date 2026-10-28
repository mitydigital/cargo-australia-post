<?php

use MityDigital\CargoAustraliaPost\Data\Parcel;
use MityDigital\CargoAustraliaPost\Services\PackageBuilder;

it('builds a parcel from a Cargo product using depth as length', function (): void {
    $cart = $this->cartWithProducts([[]]);

    $parcels = app(PackageBuilder::class)->build($cart);

    expect($parcels)->toHaveCount(1)
        ->and($parcels[0])->toBeInstanceOf(Parcel::class)
        ->and($parcels[0]->toArray())->toBe([
            'width' => 10.0, 'height' => 2.0, 'length' => 20.0, 'weight' => 1.0,
        ]);
});

it('expands quantities and combines compatible items', function (): void {
    $cart = $this->cartWithProducts([['quantity' => 3]]);

    $parcels = app(PackageBuilder::class)->build($cart);

    expect($parcels)->toHaveCount(1)
        ->and($parcels[0]->toArray())->toBe([
            'width' => 10.0, 'height' => 6.0, 'length' => 20.0, 'weight' => 3.0,
        ]);
});

it('stacks different products along the incoming shortest dimension', function (array $dimensions, array $expected): void {
    $cart = $this->cartWithProducts([[], ['data' => $dimensions]]);

    $parcels = app(PackageBuilder::class)->build($cart);

    expect($parcels)->toHaveCount(1)
        ->and($parcels[0]->toArray())->toBe($expected);
})->with([
    'height' => [['width' => 30, 'height' => 3, 'depth' => 40], ['width' => 30.0, 'height' => 5.0, 'length' => 40.0, 'weight' => 2.0]],
    'length' => [['width' => 30, 'height' => 5, 'depth' => 3], ['width' => 30.0, 'height' => 5.0, 'length' => 23.0, 'weight' => 2.0]],
    'width' => [['width' => 3, 'height' => 5, 'depth' => 30], ['width' => 13.0, 'height' => 5.0, 'length' => 30.0, 'weight' => 2.0]],
    'height wins ties' => [['width' => 3, 'height' => 3, 'depth' => 3], ['width' => 10.0, 'height' => 5.0, 'length' => 20.0, 'weight' => 2.0]],
    'length wins width tie' => [['width' => 3, 'height' => 5, 'depth' => 3], ['width' => 10.0, 'height' => 5.0, 'length' => 23.0, 'weight' => 2.0]],
]);

it('sorts fractional heights numerically before packing', function (): void {
    $cart = $this->cartWithProducts([
        ['data' => ['height' => 0.8, 'width' => 20, 'weight' => 12]],
        ['data' => ['height' => 0.2, 'width' => 10, 'weight' => 12]],
    ]);

    $parcels = app(PackageBuilder::class)->build($cart);

    expect(array_column(array_map(fn (Parcel $parcel): array => $parcel->toArray(), $parcels), 'width'))
        ->toBe([10.0, 20.0]);
});

it('preserves input order for equal heights', function (): void {
    $cart = $this->cartWithProducts([
        ['data' => ['width' => 20, 'weight' => 12]],
        ['data' => ['width' => 10, 'weight' => 12]],
    ]);

    $parcels = app(PackageBuilder::class)->build($cart);

    expect($parcels[0]->width)->toBe(20.0)
        ->and($parcels[1]->width)->toBe(10.0);
});

it('tries later parcels when the first cannot accept an item', function (): void {
    $cart = $this->cartWithProducts([
        ['data' => ['height' => 2, 'weight' => 15]],
        ['data' => ['height' => 3, 'weight' => 10]],
        ['data' => ['height' => 4, 'weight' => 11]],
    ]);

    $parcels = app(PackageBuilder::class)->build($cart);

    expect($parcels)->toHaveCount(2)
        ->and($parcels[0]->weight)->toBe(15.0)
        ->and($parcels[1]->toArray())->toBe([
            'width' => 10.0, 'height' => 7.0, 'length' => 20.0, 'weight' => 21.0,
        ]);
});

it('uses the first compatible parcel', function (): void {
    $cart = $this->cartWithProducts([
        ['data' => ['height' => 2, 'weight' => 12]],
        ['data' => ['height' => 3, 'weight' => 12]],
        ['data' => ['height' => 4, 'weight' => 5]],
    ]);

    $parcels = app(PackageBuilder::class)->build($cart);

    expect($parcels)->toHaveCount(2)
        ->and($parcels[0]->weight)->toBe(17.0)
        ->and($parcels[1]->weight)->toBe(12.0);
});

it('splits quantities that exceed the combined actual weight limit', function (string $country): void {
    $cart = $this->cartWithProducts([['quantity' => 2, 'data' => ['weight' => 12]]], $country);

    $parcels = app(PackageBuilder::class)->build($cart);

    expect($parcels)->toHaveCount(2)
        ->and($parcels[0]->weight)->toBe(12.0)
        ->and($parcels[1]->weight)->toBe(12.0);
})->with(['domestic' => 'AUS', 'international' => 'NZL']);

it('validates the expanded footprint before merging domestic parcels', function (): void {
    $cart = $this->cartWithProducts([
        ['data' => ['width' => 40, 'height' => 10, 'depth' => 40]],
        ['data' => ['width' => 80, 'height' => 30, 'depth' => 80]],
    ]);

    $parcels = app(PackageBuilder::class)->build($cart);

    expect($parcels)->toHaveCount(2)
        ->and($parcels[0]->height)->toBe(10.0)
        ->and($parcels[1]->height)->toBe(30.0);
});

it('creates another parcel when stacking would exceed the maximum dimension', function (): void {
    $cart = $this->cartWithProducts([
        ['data' => ['width' => 100, 'height' => 1, 'depth' => 100]],
        ['data' => ['width' => 6, 'height' => 10, 'depth' => 40]],
    ]);

    $parcels = app(PackageBuilder::class)->build($cart);

    expect($parcels)->toHaveCount(2)
        ->and($parcels[0]->width)->toBe(100.0)
        ->and($parcels[1]->width)->toBe(6.0);
});

it('checks international girth after every merge', function (): void {
    $cart = $this->cartWithProducts([
        ['quantity' => 3, 'data' => ['width' => 20, 'height' => 20, 'depth' => 105]],
    ], 'NZL');

    $parcels = app(PackageBuilder::class)->build($cart);

    expect($parcels)->toHaveCount(2)
        ->and($parcels[0]->height)->toBe(40.0)
        ->and($parcels[1]->height)->toBe(20.0);
});

it('checks international girth using the two shortest final dimensions', function (): void {
    $cart = $this->cartWithProducts([
        ['data' => ['width' => 35, 'height' => 30, 'depth' => 100]],
        ['data' => ['width' => 35, 'height' => 30, 'depth' => 100]],
    ], 'NZL');

    expect(app(PackageBuilder::class)->build($cart))->toHaveCount(2);
});

it('accepts exact destination limits', function (string $country, array $data): void {
    $cart = $this->cartWithProducts([['data' => $data]], $country);

    expect(app(PackageBuilder::class)->build($cart))->toHaveCount(1);
})->with([
    'domestic weight' => ['AUS', ['weight' => 22]],
    'international weight' => ['NZL', ['weight' => 20]],
    'domestic length' => ['AUS', ['depth' => 105]],
    'international length' => ['NZL', ['depth' => 105]],
    'domestic volume' => ['AUS', ['width' => 50, 'height' => 50, 'depth' => 100]],
    'international girth' => ['NZL', ['width' => 35, 'height' => 35, 'depth' => 100]],
]);

it('rejects individually unshippable items', function (string $country, array $data): void {
    $cart = $this->cartWithProducts([['data' => $data]], $country);

    expect(app(PackageBuilder::class)->build($cart))->toBeNull();
})->with([
    'domestic overweight' => ['AUS', ['weight' => 22.01]],
    'international overweight' => ['NZL', ['weight' => 20.01]],
    'oversized width' => ['AUS', ['width' => 105.01]],
    'oversized height' => ['AUS', ['height' => 105.01]],
    'oversized length' => ['AUS', ['depth' => 105.01]],
    'international oversized' => ['NZL', ['depth' => 105.01]],
    'domestic volume' => ['AUS', ['width' => 50, 'height' => 50.01, 'depth' => 100]],
    'international girth' => ['NZL', ['width' => 35, 'height' => 35.01, 'depth' => 100]],
]);

it('does not use cubic weight as an actual weight ceiling', function (string $country): void {
    $cart = $this->cartWithProducts([
        ['data' => ['width' => 35, 'height' => 35, 'depth' => 100, 'weight' => 1]],
    ], $country);

    $parcels = app(PackageBuilder::class)->build($cart);

    expect($parcels)->toHaveCount(1)
        ->and($parcels[0]->weight)->toBe(1.0);
})->with(['domestic' => 'AUS', 'international' => 'NZL']);

it('applies minimum dimensions and weight to completed parcels', function (): void {
    $cart = $this->cartWithProducts([
        ['data' => ['width' => 0.2, 'height' => 0.3, 'depth' => 0.4, 'weight' => 0.001]],
    ]);

    $parcels = app(PackageBuilder::class)->build($cart);

    expect($parcels[0]->toArray())->toBe([
        'width' => 1.0, 'height' => 1.0, 'length' => 1.0, 'weight' => 0.01,
    ]);
});

it('applies minimums to parcels rather than every individual item', function (): void {
    $cart = $this->cartWithProducts([
        ['quantity' => 2, 'data' => ['height' => 0.6, 'weight' => 0.006]],
    ]);

    $parcels = app(PackageBuilder::class)->build($cart);

    expect($parcels[0]->height)->toBe(1.2)
        ->and($parcels[0]->weight)->toBe(0.012);
});

it('accepts numeric strings', function (): void {
    $cart = $this->cartWithProducts([
        ['data' => ['width' => '10', 'height' => '2', 'depth' => '20', 'weight' => '1.5']],
    ]);

    $parcels = app(PackageBuilder::class)->build($cart);

    expect($parcels[0]->weight)->toBe(1.5);
});

it('rejects invalid measurements', function (string $field, mixed $value): void {
    $cart = $this->cartWithProducts([['data' => [$field => $value]]]);

    expect(app(PackageBuilder::class)->build($cart))->toBeNull();
})->with(['width', 'height', 'depth', 'weight'])->with([
    'missing' => [null],
    'zero' => [0],
    'negative' => [-1],
    'text' => ['unknown'],
    'empty' => [''],
    'boolean' => [true],
    'array' => [[]],
    'infinite' => [INF],
    'not a number' => [NAN],
    'overflow' => ['1e999'],
]);

it('honours configurable measurement mappings', function (): void {
    config()->set('cargo-australia-post.mappings', [
        'width' => 'box_width', 'height' => 'box_height', 'length' => 'box_depth',
        'weight' => 'box_weight',
    ]);
    $cart = $this->cartWithProducts([
        ['data' => ['box_width' => 4, 'box_height' => 5, 'box_depth' => 6, 'box_weight' => 7]],
        ['data' => ['type' => 'digital']],
    ]);

    $parcels = app(PackageBuilder::class)->build($cart);

    expect($parcels)->toHaveCount(1)
        ->and($parcels[0]->toArray())->toBe([
            'width' => 4.0, 'height' => 5.0, 'length' => 6.0, 'weight' => 7.0,
        ]);
});

it('ignores digital products before validating their measurements', function (): void {
    $cart = $this->cartWithProducts([
        ['data' => ['type' => 'digital', 'weight' => null]],
        [],
    ]);

    $parcels = app(PackageBuilder::class)->build($cart);

    expect($parcels)->toHaveCount(1)->and($parcels[0]->weight)->toBe(1.0);
});

it('returns no parcels for a digital-only cart', function (): void {
    $cart = $this->cartWithProducts([['quantity' => 3, 'data' => ['type' => 'digital', 'weight' => null]]]);

    expect(app(PackageBuilder::class)->build($cart))->toBe([]);
});

it('does not omit physical products because of legacy exclusions or service restrictions', function (array $data): void {
    $cart = $this->cartWithProducts([['data' => $data]]);

    expect(app(PackageBuilder::class)->build($cart))->toHaveCount(1);
})->with([
    'legacy toggle' => [['exclude_from_calculations' => true]],
    'no permitted services' => [['australia_post_services' => []]],
]);

it('rejects an empty cart', function (): void {
    $cart = $this->cartWithProducts([]);

    expect(app(PackageBuilder::class)->build($cart))->toBeNull();
});

it('rejects missing malformed or unknown destinations', function (?string $country): void {
    $cart = $this->cartWithProducts([[]], $country);

    expect(app(PackageBuilder::class)->build($cart))->toBeNull();
})->with([null, '', 'Australia', 'aus', 'ZZZ', 'AU', 'NZ']);

it('rejects invalid quantities', function (mixed $quantity): void {
    $cart = $this->cartWithProducts([[]]);
    $cart->lineItems()->first()->quantity($quantity);

    expect(app(PackageBuilder::class)->build($cart))->toBeNull();
})->with([0, -1, 1.5, '2']);

it('returns no partial parcels when a later product is unshippable', function (): void {
    $cart = $this->cartWithProducts([[], ['data' => ['weight' => 23]]]);

    expect(app(PackageBuilder::class)->build($cart))->toBeNull();
});

it('does not reuse parcels between builds', function (): void {
    $builder = app(PackageBuilder::class);
    $digital = $this->cartWithProducts([['data' => ['type' => 'digital']]]);
    expect($builder->build($digital))->toBe([]);
    $domestic = $this->cartWithProducts([['data' => ['weight' => 21]]]);
    expect($builder->build($domestic))->toHaveCount(1);
    $domestic->set('shipping_address', ['country' => 'NZL']);

    expect($builder->build($domestic))->toBeNull();
});

it('uses parent product measurements for variants', function (): void {
    $cart = $this->cartWithProducts([
        [
            'variant' => 'small',
            'data' => ['product_variants' => ['options' => [[
                'key' => 'small', 'variant' => 'Small', 'price' => 100,
                'width' => 100, 'height' => 100, 'depth' => 100, 'weight' => 100,
            ]]]],
        ],
    ]);

    $parcels = app(PackageBuilder::class)->build($cart);

    expect($parcels)->toHaveCount(1)
        ->and($parcels[0]->toArray())->toBe([
            'width' => 10.0, 'height' => 2.0, 'length' => 20.0, 'weight' => 1.0,
        ]);
});

it('accepts a merged parcel at the exact destination weight limit', function (string $country, float $itemWeight, float $total): void {
    $cart = $this->cartWithProducts([['quantity' => 2, 'data' => ['weight' => $itemWeight]]], $country);

    $parcels = app(PackageBuilder::class)->build($cart);

    expect($parcels)->toHaveCount(1)
        ->and($parcels[0]->weight)->toBe($total);
})->with(['domestic' => ['AUS', 11.0, 22.0], 'international' => ['NZL', 10.0, 20.0]]);

it('accepts a merged parcel at the exact international girth limit', function (): void {
    $cart = $this->cartWithProducts([
        ['quantity' => 2, 'data' => ['width' => 30, 'height' => 20, 'depth' => 100]],
    ], 'NZL');

    $parcels = app(PackageBuilder::class)->build($cart);

    expect($parcels)->toHaveCount(1)
        ->and($parcels[0]->height)->toBe(40.0);
});

it('accepts a merged parcel at the exact domestic volume limit', function (): void {
    $cart = $this->cartWithProducts([
        ['quantity' => 2, 'data' => ['width' => 50, 'height' => 25, 'depth' => 100]],
    ]);

    $parcels = app(PackageBuilder::class)->build($cart);

    expect($parcels)->toHaveCount(1)
        ->and($parcels[0]->height)->toBe(50.0);
});

it('rejects an invalid physical product even when another product is digital', function (): void {
    $cart = $this->cartWithProducts([
        ['data' => ['type' => 'digital']],
        ['data' => ['weight' => null]],
    ]);

    expect(app(PackageBuilder::class)->build($cart))->toBeNull();
});
