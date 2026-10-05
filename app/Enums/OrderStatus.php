<?php

namespace App\Enums;

enum OrderStatus: string
{
    /** Placed, but the online payment has not been confirmed yet. */
    case PendingPayment = 'pending_payment';
    case New = 'new';
    case Accepted = 'accepted';
    case Preparing = 'preparing';
    case OutForDelivery = 'out_for_delivery';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    public function label(?string $locale = null): string
    {
        return __('storefront.order.status.'.$this->value, [], $locale);
    }

    /** The status as the shop team reads it, which is not always how the shopper does ("on its way to you"). */
    public function panelLabel(): string
    {
        return __('panel.orders.status.'.$this->value);
    }

    /** How the storefront colours the status: pending, done or cancelled. */
    public function tone(): string
    {
        return match ($this) {
            self::Cancelled => 'cancelled',
            self::Delivered => 'done',
            default => 'pending',
        };
    }

    /** Whether the order is still on its way to the customer. */
    public function isOpen(): bool
    {
        return ! in_array($this, [self::Delivered, self::Cancelled], true);
    }

    /**
     * Statuses a staff member can move an order to by hand. Payment decides
     * the first step, so an order is never moved out of "pending payment" or
     * back into it from the admin.
     *
     * @return array<int,self>
     */
    public static function manual(): array
    {
        return [self::New, self::Accepted, self::Preparing, self::OutForDelivery, self::Delivered, self::Cancelled];
    }

    /**
     * Where staff may move an order from this status. Delivered and cancelled
     * are final: a cancelled order has already put its stock back, and a
     * delivered one is done. Until its payment is confirmed an order can only
     * be cancelled.
     *
     * @return array<int,self>
     */
    public function transitions(): array
    {
        return match ($this) {
            self::PendingPayment => [self::Cancelled],
            self::New, self::Accepted, self::Preparing, self::OutForDelivery => array_values(array_filter(
                self::manual(),
                fn (self $to) => $to !== $this
            )),
            self::Delivered, self::Cancelled => [],
        };
    }

    public function canMoveTo(self $to): bool
    {
        return in_array($to, $this->transitions(), true);
    }
}
