<?php

namespace App\Http\Controllers;

use App\Services\Store\Payments\GatewayConfig;
use Illuminate\Http\Response;

/**
 * The file that proves this domain may show Apple Pay.
 *
 * MyFatoorah gives an owner the file; they paste it into the admin panel, and it
 * is handed back here exactly as it was pasted. It is public by design (Apple
 * fetches it) and holds no secret.
 */
class ApplePayDomainFileController extends Controller
{
    public function __invoke(GatewayConfig $gateway): Response
    {
        $file = $gateway->applePayDomainFile();

        abort_if($file === null, 404);

        return response($file, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            // Apple looks again now and then, and a replaced file should be seen at once.
            'Cache-Control' => 'no-cache',
        ]);
    }
}
