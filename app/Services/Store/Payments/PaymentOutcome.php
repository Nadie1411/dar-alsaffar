<?php

namespace App\Services\Store\Payments;

use App\Models\Payment;

/**
 * What checking a payment with MyFatoorah turned up.
 */
final class PaymentOutcome
{
    public const PAID = 'paid';

    /** Not decided: still in progress, or something needs a person's attention. */
    public const PENDING = 'pending';

    /** This attempt did not go through, though the shopper may try again. */
    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    /** A payment that is not one of ours. */
    public const UNKNOWN = 'unknown';

    private function __construct(
        public readonly string $result,
        public readonly ?Payment $payment = null,
    ) {}

    public static function paid(Payment $payment): self
    {
        return new self(self::PAID, $payment);
    }

    public static function pending(Payment $payment): self
    {
        return new self(self::PENDING, $payment);
    }

    public static function failed(Payment $payment): self
    {
        return new self(self::FAILED, $payment);
    }

    public static function cancelled(Payment $payment): self
    {
        return new self(self::CANCELLED, $payment);
    }

    public static function unknown(): self
    {
        return new self(self::UNKNOWN);
    }

    public function isPaid(): bool
    {
        return $this->result === self::PAID;
    }
}
