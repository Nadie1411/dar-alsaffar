<?php

namespace App\Listeners;

use App\Enums\OrderStatus;
use App\Events\OrderStatusChanged;
use App\Mail\OrderStatusMail;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * An order has moved on. The customer is told when it is out for delivery or
 * delivered, and when a member of staff cancels it. A cancellation the system
 * makes — a payment that ran out of time — is not announced: nobody asked for
 * it, and the customer is already on the payment page's own messages.
 */
class SendOrderStatusEmail
{
    public function handle(OrderStatusChanged $event): void
    {
        $order = $event->order;

        if (blank($order->customer_email) || ! in_array($event->to, OrderStatusMail::NOTIFIED, true)) {
            return;
        }

        if ($event->to === OrderStatus::Cancelled && $event->staffId === null) {
            return;
        }

        try {
            Mail::to($order->customer_email)->send(new OrderStatusMail($order, $event->to));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
