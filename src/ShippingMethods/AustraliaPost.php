<?php

namespace MityDigital\CargoAustraliaPost\ShippingMethods;

use DuncanMcClean\Cargo\Contracts\Cart\Cart;
use DuncanMcClean\Cargo\Shipping\ShippingMethod;
use DuncanMcClean\Cargo\Shipping\ShippingOption;
use Illuminate\Support\Collection;
use MityDigital\CargoAustraliaPost\Services\AustraliaPostService;

class AustraliaPost extends ShippingMethod
{
    public function options(Cart $cart): Collection
    {
        $quotes = app(AustraliaPostService::class)->getQuotes($cart);

        return $quotes->map(fn (array $quote): ShippingOption => ShippingOption::make($this)
            ->handle('australia_post_'.$quote['service_code'])
            ->name($quote['label'])
            ->price($quote['price']));
    }
}
