<?php

namespace App\Listeners;

use App\Events\OrderPlaced;
use App\Mail\NewOrderAlertMail;
use App\Mail\OrderConfirmationMail;
use App\Services\Settings;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * An order has been placed: the customer gets a confirmation, if they gave an
 * address, and the shop gets an alert, if it has said where to send one.
 *
 * The mails themselves are queued. And whatever goes wrong here — a mail
 * server down, a queue that cannot be reached — is reported and left at that:
 * an order that was placed and paid for is never undone by an email.
 */
class SendOrderPlacedEmails
{
    public function __construct(protected Settings $settings) {}

    public function handle(OrderPlaced $event): void
    {
        $order = $event->order;

        if (filled($order->customer_email)) {
            $this->send($order->customer_email, new OrderConfirmationMail($order));
        }

        $staff = trim((string) $this->settings->get('orders.notify_email', ''));

        if ($staff !== '') {
            $this->send($staff, new NewOrderAlertMail($order));
        }
    }

    protected function send(string $to, object $mail): void
    {
        try {
            Mail::to($to)->send($mail);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
