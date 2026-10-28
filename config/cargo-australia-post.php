<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Australia Post Services
    |--------------------------------------------------------------------------
    |
    | Map Australia Post service codes to the labels shown at checkout and in
    | the Australia Post Services fieldtype. Enable the examples below or add
    | your own codes. An empty group disables shipping for that destination.
    |
    | Domestic services apply to Australian addresses; international services
    | apply to overseas addresses. A service must be permitted by every physical
    | product in the cart and return a valid quote to be offered at checkout.
    |
    | Find service codes using these commands with a sample destination:
    |   php please cargo-australia-post:domestic-parcel-services 5000
    |   php please cargo-australia-post:international-parcel-services NZ
    |
    | Labels can be changed without affecting saved product service selections.
    |
    */

    'service_codes' => [
        'domestic' => [
            /*'AUS_PARCEL_REGULAR' => 'Parcel Post',
            'AUS_PARCEL_EXPRESS' => 'Express Post',*/
        ],

        'international' => [
            /*'INT_PARCEL_STD_OWN_PACKAGING' => 'Standard',
            'INT_PARCEL_EXP_OWN_PACKAGING' => 'Express',
            'INT_PARCEL_COR_OWN_PACKAGING' => 'Courier',*/
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Dimension Field Mappings
    |--------------------------------------------------------------------------
    |
    | Australia Post's API requires the dimensions and weight of each item.
    | to be able to calculate anything.
    |
    | The mapping keys must remain - but the values for each are the field handle
    | to collect the dimension attributes.
    |
    | The default config looks for a field called "height". If you had a different
    | field, such as "package_height", you would change the config below to be:
    |    'height' => 'package_height'
    |
    | The available services field stores permitted Australia Post service codes.
    | Missing or null allows all configured services; an empty array allows none.
    | Every physical product must permit a service for it to be offered.
    |
    */

    'mappings' => [
        /* dimensions */
        'height' => 'height',
        'length' => 'depth',
        'width' => 'width',

        /* weight */
        'weight' => 'weight',

        /* available shipping services */
        'available_services' => 'australia_post_services',
    ],

    /*
    |--------------------------------------------------------------------------
    | From Postcode
    |--------------------------------------------------------------------------
    |
    | The "from" postcode used for all Australia Post requests.
    |
    */

    'from_postcode' => env('AUSTRALIA_POST_FROM_POSTCODE'),

    /*
    |--------------------------------------------------------------------------
    | Australia Post Postage Assessment Calculator (PAC) API Key
    |--------------------------------------------------------------------------
    |
    | You need an API key to access the Australia Post Postage Assessment
    | Calculator (PAC). You can register for a key at:
    |
    | https://developers.auspost.com.au/apis/pacpcs-registration
    |
    */

    'api_key' => env('AUSTRALIA_POST_PAC_KEY', ''),

    /*
    |--------------------------------------------------------------------------
    | Postage Calculation Cache
    |--------------------------------------------------------------------------
    |
    | Cache successful parcel calculations using Laravel's default cache store
    | to reduce API requests. Matching calculations can be reused across carts;
    | the cache key includes the API account, endpoint and request parameters.
    | Failed calculations are not cached.
    |
    | The TTL is measured in seconds: 300 keeps prices for five minutes. Set it
    | to 0 to disable cache reads and writes. Cached prices may remain unchanged
    | until the TTL expires, even if Australia Post updates its rates.
    |
    */

    'cache' => [
        'ttl' => 300,
    ],
];
