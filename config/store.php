<?php

/*
|--------------------------------------------------------------------------
| The store's own backend
|--------------------------------------------------------------------------
|
| The storefront can run against Overzaki (the original setup) or against this
| application's own database. `backend` is the switch: it stays on "overzaki"
| until the owner decides to cut over, and flipping it back is the rollback.
|
*/

return [

    'backend' => env('STORE_BACKEND', 'overzaki'),

    // The shop's own clock. Orders are stored in UTC; a day in the panel's
    // reports and order lists is a day in Kuwait.
    'timezone' => env('STORE_TIMEZONE', 'Asia/Kuwait'),

    // Catalogue images copied from Overzaki's CDN are kept under public/, in
    // the same uploads volume the admin panel already writes to.
    'catalog_images' => 'uploads/catalog',

    /*
    | What checkout charges and requires, until the shop sets its own values
    | in the admin panel. Whole fils (1,000 fils = 1 KWD). The delivery fee
    | matches what the shop charged while it ran on Overzaki: every one of
    | its 217 delivery areas was KWD 2.
    */
    'commerce' => [
        'delivery_fee_fils' => (int) env('STORE_DELIVERY_FEE_FILS', 2000),
        'free_shipping_fils' => (int) env('STORE_FREE_SHIPPING_FILS', 0),
        'minimum_order_fils' => (int) env('STORE_MINIMUM_ORDER_FILS', 0),
        'cod_fee_fils' => (int) env('STORE_COD_FEE_FILS', 0),
    ],

    /*
    | Push notifications to the staff's phones. The server posts to an address a
    | browser handed it, so it only ever posts to the push services the browsers
    | themselves use: an address on any other host is refused when it is saved.
    | An entry matches that host and every subdomain of it.
    */
    'push' => [
        'hosts' => [
            'fcm.googleapis.com',         // Chrome, Edge and other Chromium browsers, Android
            'push.services.mozilla.com',  // Firefox
            'push.apple.com',             // Safari, and the installed web app on iPhone
            'notify.windows.com',         // Edge on Windows
        ],
        'timeout' => 4,
    ],

];
