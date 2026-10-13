<?php

namespace App\Http\Controllers;

use App\Services\Store\Payments\MyFatoorahException;
use App\Services\Store\Payments\PaymentReturn;
use App\Services\Store\Payments\PaymentService;
use App\Support\Nav;
use Illuminate\Http\Request;

/**
 * Where MyFatoorah sends the shopper back after the payment page.
 *
 * The address carries a paymentId and nothing else, and it is only a pointer:
 * whether the order was paid is decided by asking MyFatoorah, never by the
 * fact that the shopper arrived here.
 */
class PaymentCallbackController extends Controller
{
    public function __construct(
        protected PaymentService $payments,
        protected PaymentReturn $return,
    ) {}

    public function __invoke(Request $request, string $locale)
    {
        $paymentId = (string) $request->query('paymentId', '');

        // Whatever is in the query string ends up in an API call, so only the
        // shape of a real payment id is let through.
        if (! preg_match('/^[A-Za-z0-9_-]{6,64}$/', $paymentId)) {
            return redirect(Nav::url('checkout/failed'));
        }

        try {
            $outcome = $this->payments->confirm($paymentId);
        } catch (MyFatoorahException) {
            // MyFatoorah could not be asked. Nothing is decided; the webhook or
            // the reconcile job will settle it, and the shopper is told so.
            return redirect(Nav::url('checkout/pending/'.session('payment.order')));
        }

        return redirect($this->return->destination($outcome));
    }
}
