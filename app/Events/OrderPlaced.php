<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An order has been created. Fired after the transaction that made it has
 * committed, so a listener never sees an order that is then rolled back.
 */
class OrderPlaced
{
    use Dispatchable;

    public function __construct(public readonly Order $order) {}
}
