<?php

namespace App\Listeners;

use App\Events\OrderPlaced;
use App\Services\Store\Push\OrderPushNotifier;

/**
 * An order has been placed: wake the staff's phones.
 *
 * Deferred until the shopper's response has gone out, so contacting the push
 * services never makes anyone wait at checkout.
 */
class SendOrderPushNotifications
{
    public function __construct(protected OrderPushNotifier $notifier) {}

    public function handle(OrderPlaced $event): void
    {
        defer(fn () => $this->notifier->newOrder());
    }
}
