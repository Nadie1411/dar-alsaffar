<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\Store\Payments\GatewayConfig;
use App\Services\Store\Payments\MyFatoorahException;
use App\Services\Store\Payments\MyFatoorahUnavailable;
use App\Services\Store\Payments\PaymentService;
use App\Services\Store\Payments\WebhookSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * MyFatoorah's "payment status changed" webhook.
 *
 * A webhook is a hint, not a verdict. When a secret is configured the event's
 * signature must check out before anything is done with it, and either way the
 * payment it names is then fetched from MyFatoorah's API: the event itself is
 * never what marks an order paid.
 */
class MyFatoorahWebhookController extends Controller
{
    /** The event code MyFatoorah uses for "payment status changed". */
    protected const PAYMENT_STATUS_CHANGED = 1;

    public function __construct(
        protected PaymentService $payments,
        protected WebhookSignature $signature,
        protected GatewayConfig $gateway,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $event = json_decode($request->getContent(), true);

        if (! is_array($event)) {
            return response()->json(['ok' => false], 400);
        }

        $secret = $this->gateway->webhookSecret();

        if ($secret !== '' && ! $this->signature->isValid($event, $request->header('MyFatoorah-Signature'), $secret)) {
            Log::warning('A MyFatoorah webhook with an invalid signature was refused', ['ip' => $request->ip()]);

            return response()->json(['ok' => false], 401);
        }

        // Refunds, balance transfers and the rest are not what this reacts to.
        if ((int) data_get($event, 'Event.Code') !== self::PAYMENT_STATUS_CHANGED) {
            return response()->json(['ok' => true]);
        }

        $paymentId = (string) data_get($event, 'Data.Transaction.PaymentId', '');

        if ($paymentId === '') {
            return response()->json(['ok' => true]);
        }

        try {
            $this->payments->confirm($paymentId);
        } catch (MyFatoorahUnavailable) {
            // Could not check it just now: say so, and MyFatoorah will send it again.
            return response()->json(['ok' => false], 503);
        } catch (MyFatoorahException $e) {
            Log::error('A MyFatoorah webhook could not be checked', ['error' => $e->getMessage()]);

            return response()->json(['ok' => false], 500);
        }

        return response()->json(['ok' => true]);
    }
}
