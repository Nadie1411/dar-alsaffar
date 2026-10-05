<?php

use App\Http\Controllers\Webhooks\MyFatoorahWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Server-to-server endpoints
|--------------------------------------------------------------------------
|
| Called by other systems, not by a shopper's browser: no session, no cookies,
| no CSRF token. What stands in for them is the check each one makes itself —
| the webhook's signature, and then asking MyFatoorah directly.
|
*/

Route::post('/webhooks/myfatoorah', MyFatoorahWebhookController::class)
    ->middleware('throttle:payment-webhook')
    ->name('webhooks.myfatoorah');
