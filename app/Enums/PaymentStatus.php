<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Unpaid = 'unpaid';
    case Paid = 'paid';
    case Failed = 'failed';
    case Refunded = 'refunded';

    public function label(?string $locale = null): string
    {
        return __('storefront.order.payment.'.$this->value, [], $locale);
    }
}
