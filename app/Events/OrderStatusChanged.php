<?php

namespace App\Events;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An order has moved from one status to another. Fired after the change has
 * been committed, so a listener never hears about a move that was rolled back.
 */
class OrderStatusChanged
{
    use Dispatchable;

    /**
     * @param  int|null  $staffId  who made the move; null when the system did, such as a payment that ran out
     */
    public function __construct(
        public readonly Order $order,
        public readonly OrderStatus $from,
        public readonly OrderStatus $to,
        public readonly ?int $staffId = null,
    ) {}
}
