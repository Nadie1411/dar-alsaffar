<?php

namespace App\Enums;

/**
 * Where one payment attempt stands. "Paid" is final — MyFatoorah never takes a
 * success back — but "failed" is not: the shopper can retry on the same
 * invoice, and a late success replaces an earlier failure.
 */
enum PaymentState: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    /** Whether nothing more can change this attempt. */
    public function isFinal(): bool
    {
        return in_array($this, [self::Paid, self::Cancelled, self::Expired], true);
    }
}
