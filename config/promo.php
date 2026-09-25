<?php

/*
|--------------------------------------------------------------------------
| Storefront promotion display
|--------------------------------------------------------------------------
|
| The offers themselves live in the Overzaki dashboard — this file only
| controls how the storefront presents them, and lets the shop run a custom
| announcement (a new arrival, a seasonal message) that is not a voucher.
|
| Leave `popup.enabled` on and `popup.title` empty to have the pop-up show
| whatever offer is currently live in the dashboard, with no editing here.
|
*/

return [

    // The thin bar under the header. Hidden automatically when no offer is live.
    'strip' => [
        'enabled' => env('PROMO_STRIP', true),
    ],

    'popup' => [
        'enabled' => env('PROMO_POPUP', true),

        // Seconds to wait before showing it, so it never interrupts the hero.
        'delay' => 6,

        // Days before a dismissed pop-up may appear again for the same visitor.
        'snooze_days' => 7,

        // Leave these null to mirror the live offer from the dashboard.
        'title' => null,
        'body' => null,
        'image' => null,
        'cta' => null,   // ['label' => '...', 'path' => 'offers']

        // Pages the pop-up must never interrupt.
        'except' => ['cart', 'checkout', 'login', 'register', 'account'],
    ],

    'install' => [
        // The add-to-home-screen prompt.
        'enabled' => env('PROMO_INSTALL', true),
        'delay' => 12,
        'snooze_days' => 14,
    ],
];
