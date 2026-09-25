<?php

/*
|--------------------------------------------------------------------------
| Overzaki storefront API
|--------------------------------------------------------------------------
|
| Dar Al Saffar's catalogue, cart pricing, customers and orders all live in
| Overzaki (the SaaS that previously served the Next.js storefront too).
| This front end owns presentation only: every price, total and stock figure
| on the site comes back from these endpoints, never from local arithmetic.
|
| The tenant is resolved server side from the domain, which is why
| `tenant_id` is the public hostname rather than an opaque key.
|
*/

return [

    'base_url' => env('OVERZAKI_BASE_URL', 'https://production.overzaki.org/api'),
    'tenant_id' => env('OVERZAKI_TENANT_ID', 'dar-alsaffar.net'),

    // Kuwaiti Dinar. Overzaki keys prices by currency document, not ISO code.
    'currency_id' => env('OVERZAKI_CURRENCY_ID', '68f0ded46277511861ffca2a'),
    'country_id' => env('OVERZAKI_COUNTRY_ID', '660c8364cc6ee3098a612523'),

    'timeout' => (int) env('OVERZAKI_TIMEOUT', 20),
    'retries' => (int) env('OVERZAKI_RETRIES', 2),

    // Catalogue responses are cached briefly so listing pages stay quick
    // without letting stock or price edits go stale for long.
    'cache' => [
        'catalog' => (int) env('OVERZAKI_CACHE_CATALOG', 300),
        'taxonomy' => (int) env('OVERZAKI_CACHE_TAXONOMY', 900),
        'content' => (int) env('OVERZAKI_CACHE_CONTENT', 1800),
    ],

    /*
    | Payment methods configured on the tenant's gateway. The API's own
    | payment-source endpoint is admin-authenticated, so these mirror what the
    | store has enabled; the order endpoint validates them server side, and an
    | ID that has been retired will be rejected there rather than silently
    | taking a payment.
    */
    'payment' => [
        'integration_id' => env('OVERZAKI_PAYMENT_INTEGRATION', '68f9df45a765c34a6233b12e'),
        'methods' => [
            ['id' => '667d56d88bebce07becd4380', 'type' => 'knet',           'label' => ['ar' => 'كي نت',            'en' => 'KNET']],
            ['id' => '667ba70aa79301427f5ac5dc', 'type' => 'card',           'label' => ['ar' => 'فيزا',             'en' => 'Visa']],
            ['id' => '667ba6dda79301427f5ac5d2', 'type' => 'card',           'label' => ['ar' => 'ماستركارد',        'en' => 'Mastercard']],
            ['id' => '667ba7b1a79301427f5ac604', 'type' => 'apple_pay',      'label' => ['ar' => 'أبل بي (فيزا)',    'en' => 'Apple Pay (Visa)']],
            ['id' => '68b610f11c838057aec14424', 'type' => 'apple_pay_kent', 'label' => ['ar' => 'أبل بي (كي نت)',   'en' => 'Apple Pay (KNET)']],
        ],
    ],

    'endpoints' => [
        'products' => '/products/get_products_customer',
        'product' => '/products/v2/slug/',
        'productLegacy' => '/products/slug/',
        'relatedProducts' => '/products/related_products/',
        'productTags' => '/products/tags',
        'categories' => '/categories/all_slug',
        'categoryBySlug' => '/categories/slug/',
        'occasions' => '/occasions/all',
        'brands' => '/brand/all_customer',

        'cartChecker' => '/carts/checker_customer/',
        'buyNowChecker' => '/carts/buy_now_checker/',

        'signIn' => '/auth/login_customer',
        'signUp' => '/auth/signup_customer',
        'refresh' => '/auth/refresh',
        'forgotPassword' => '/code/forgot_password_otp',

        'orderForCustomer' => '/orders/customer/',
        'orderForGuest' => '/orders/guest/',
        'myOrders' => '/orders/my_order',

        'wishlist' => '/customers/wishlist/',
        'wishlistAdd' => '/customers/add_wishlist/',
        'wishlistRemove' => '/customers/delete_wishlist/',

        'addresses' => '/addresses',
        'myAddresses' => '/addresses/mine',
        'activeCountries' => '/country/active',
        'citiesWithAreas' => '/delivery-pickup/domenstic-shipping/country/',

        'pageBySlug' => '/pages/slug_tenant/',
        'contactUs' => '/contactus',
        'newsletter' => '/newsletter/subscribe',
        'publicVouchers' => '/vouchers/public',
    ],
];
