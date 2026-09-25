<?php

/*
|--------------------------------------------------------------------------
| Brand details
|--------------------------------------------------------------------------
|
| Contact points and social handles carried over from the live storefront —
| these are the real, published channels, not placeholders. Anything the
| business has not published (a street address, registration numbers, the
| brand story) is deliberately absent rather than invented.
|
*/

return [

    'name' => [
        'ar' => 'دار الصفار للعطور',
        'en' => 'Dar Alsaffar Perfumes',
    ],

    'domain' => 'dar-alsaffar.net',

    'contact' => [
        'phone' => '+96599785642',
        'whatsapp' => '96555560002',
        'email' => env('BRAND_EMAIL'),
    ],

    'social' => [
        'instagram' => 'https://www.instagram.com/dar_alsaffarperfumes',
        'tiktok' => 'https://www.tiktok.com/@daralsaffarpurfumes',
    ],

    'country' => [
        'code' => 'KW',
        'name_ar' => 'الكويت',
        'name_en' => 'Kuwait',
        'dial' => '965',
        'phone_len' => 8,
    ],

    'logo' => [
        'mark_emerald' => 'assets/brand/logo-mark-emerald.png',
        'mark_cream' => 'assets/brand/logo-mark-cream.png',
        'full_emerald' => 'assets/brand/logo-full-emerald.png',
        'full_cream' => 'assets/brand/logo-full-cream.png',
    ],
];
