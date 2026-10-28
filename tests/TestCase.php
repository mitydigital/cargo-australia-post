<?php

namespace MityDigital\CargoAustraliaPost\Tests;

use Composer\InstalledVersions;
use DuncanMcClean\Cargo\Cart\Cart;
use DuncanMcClean\Cargo\Products\Product;
use DuncanMcClean\Cargo\ServiceProvider as CargoServiceProvider;
use MityDigital\CargoAustraliaPost\ServiceProvider;
use Statamic\Addons\Manifest;
use Statamic\Facades\Collection;
use Statamic\Testing\AddonTestCase;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

abstract class TestCase extends AddonTestCase
{
    use PreventsSavingStacheItemsToDisk;

    protected string $addonServiceProvider = ServiceProvider::class;

    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), CargoServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app->make(Manifest::class)->manifest['duncanmcclean/statamic-cargo'] = [
            'id' => 'duncanmcclean/statamic-cargo',
            'slug' => 'cargo',
            'version' => InstalledVersions::getPrettyVersion('duncanmcclean/statamic-cargo'),
            'namespace' => 'DuncanMcClean\\Cargo',
            'autoload' => 'src',
            'provider' => CargoServiceProvider::class,
        ];
    }

    /**
     * @param  list<array{data?: array<string, mixed>, quantity?: int, variant?: string}>  $products
     */
    protected function cartWithProducts(array $products, ?string $country = 'AUS'): Cart
    {
        Collection::make('products')->save();

        $lineItems = [];

        foreach ($products as $index => $attributes) {
            $product = (new Product)
                ->id('product-'.$index)
                ->collection('products')
                ->slug('product-'.$index)
                ->data(array_merge([
                    'width' => 10,
                    'height' => 2,
                    'depth' => 20,
                    'weight' => 1,
                ], $attributes['data'] ?? []));

            $product->save();

            $lineItems[] = [
                'product' => $product->id(),
                'quantity' => $attributes['quantity'] ?? 1,
                'variant' => $attributes['variant'] ?? null,
            ];
        }

        return (new Cart)->site('default')->lineItems($lineItems)
            ->set('shipping_address', ['country' => $country]);
    }
}
