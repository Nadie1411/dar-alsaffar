<?php

/*
|--------------------------------------------------------------------------
| MyFatoorah payments
|--------------------------------------------------------------------------
|
| Online payments are taken on MyFatoorah's hosted page. The API key is a
| secret: it lives only in the server's environment, is read here and nowhere
| else, and is never sent to the browser, logged or committed.
|
| API_URL picks the environment and, for the live one, the country:
|   Kuwait, Bahrain, Jordan, Oman   https://api.myfatoorah.com
|   UAE                              https://api-ae.myfatoorah.com
|   Saudi Arabia                     https://api-sa.myfatoorah.com
|   Qatar                            https://api-qa.myfatoorah.com
|   Egypt                            https://api-eg.myfatoorah.com
|   Sandbox (no real money)          https://apitest.myfatoorah.com
|
*/

return [

    // A switch to turn online payment off without removing the key.
    'enabled' => (bool) env('MYFATOORAH_ENABLED', true),

    'api_key' => env('MYFATOORAH_API_KEY'),

    'api_url' => env('MYFATOORAH_API_URL', 'https://api.myfatoorah.com'),

    // The only addresses the admin panel lets an owner pick. The API key is sent
    // to whichever one is chosen, so it is a list to choose from — never a box
    // to type an address into.
    'endpoints' => [
        'kuwait' => 'https://api.myfatoorah.com',
        'uae' => 'https://api-ae.myfatoorah.com',
        'saudi' => 'https://api-sa.myfatoorah.com',
        'qatar' => 'https://api-qa.myfatoorah.com',
        'egypt' => 'https://api-eg.myfatoorah.com',
        'sandbox' => 'https://apitest.myfatoorah.com',
    ],

    // The secret key from the portal's Webhook Settings. When set, a webhook
    // without a valid signature is refused.
    'webhook_secret' => env('MYFATOORAH_WEBHOOK_SECRET'),

    // MyFatoorah only accepts public https addresses for where the shopper
    // returns to and where it posts webhooks. Normally they are built from the
    // site's own address; set this to override the host, e.g. a tunnel while
    // developing against the sandbox. No trailing slash.
    'callback_base_url' => env('MYFATOORAH_CALLBACK_URL'),

    'timeout' => (int) env('MYFATOORAH_TIMEOUT', 30),

    'connect_timeout' => 5,

    // How long a shopper has to pay before the invoice expires and the order
    // is released. The order is held a little longer than the invoice, so a
    // payment made at the last moment still finds its order.
    'expiry_minutes' => (int) env('MYFATOORAH_EXPIRY_MINUTES', 60),

    'expiry_grace_minutes' => 10,

    // The list of enabled methods barely changes, so it is read now and then.
    'methods_cache_minutes' => 360,

];
