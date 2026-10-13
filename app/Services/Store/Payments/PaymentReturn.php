<?php

namespace App\Services\Store\Payments;

use App\Contracts\Store\Cart;
use App\Support\Nav;

/**
 * Where a shopper goes once MyFatoorah has said what became of their payment —
 * the same answer whether they came back from MyFatoorah's page or paid with
 * the Apple Pay button on this shop's own.
 */
class PaymentReturn
{
    public function __construct(protected Cart $cart) {}

    public function destination(PaymentOutcome $outcome): string
    {
        $number = $outcome->payment?->order?->number;

        return match (true) {
            // Paid, with nothing left for a person to look at.
            $outcome->isPaid() && $outcome->payment->anomaly === null => $this->paid($number),
            // Undecided — or paid with something a person must resolve first.
            $outcome->result === PaymentOutcome::PENDING || $outcome->isPaid() => Nav::url('checkout/pending/'.$number),
            default => Nav::url('checkout/failed', $number ? ['order' => $number] : []),
        };
    }

    protected function paid(string $number): string
    {
        // The basket was kept until now, so an abandoned payment page cost the
        // shopper nothing. Cleared only in the session that placed the order.
        if (session('payment.order') === $number) {
            $this->cart->clear();
            session()->forget('payment.order');
        }

        return Nav::url('checkout/thanks/'.$number);
    }
}
