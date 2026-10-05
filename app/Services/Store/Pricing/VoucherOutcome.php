<?php

namespace App\Services\Store\Pricing;

use App\Models\Voucher;

/**
 * What a voucher code did to a basket.
 */
final class VoucherOutcome
{
    public function __construct(
        public readonly ?Voucher $voucher,
        public readonly bool $accepted,
        public readonly ?string $message,
        public readonly int $discountFils = 0,
        public readonly bool $freeShipping = false,
    ) {}

    /** No code was entered — nothing to apply and nothing to complain about. */
    public static function none(): self
    {
        return new self(null, true, null);
    }

    public static function rejected(string $message): self
    {
        return new self(null, false, $message);
    }
}
