<?php

/*
|--------------------------------------------------------------------------
| Store control panel
|--------------------------------------------------------------------------
|
| A small settings screen for the shop team, covering only what this
| application owns: the announcement strip, the pop-up and the
| add-to-home-screen prompt. Products, prices, offers, delivery and orders
| are all managed in the Overzaki dashboard.
|
| Set the password with:  php artisan store:password
|
| With no hash configured the panel returns 404 rather than exposing a login
| form, so an unconfigured install has no door to knock on.
|
*/

return [
    'password_hash' => env('ADMIN_PASSWORD_HASH'),

    // Failed sign-in attempts allowed per minute, per IP.
    'throttle' => (int) env('ADMIN_THROTTLE', 5),
];
