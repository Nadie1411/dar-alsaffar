<?php

namespace App\Mail;

use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells the shop that an order has come in. Written in Arabic, the language
 * the shop works in.
 */
class NewOrderAlertMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Order $order)
    {
        $this->locale('ar');
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('panel.orders.alert', [
            'number' => $this->order->number,
            'total' => Money::format(Money::fromFils($this->order->total_fils), Money::KWD),
        ]));
    }

    public function content(): Content
    {
        $order = $this->order->loadMissing('items');
        $paid = $order->payment_status === PaymentStatus::Paid;

        return new Content(
            view: 'emails.order',
            with: [
                'order' => $order,
                'lang' => 'ar',
                'headline' => __('panel.mail.headline'),
                'lead' => __('panel.mail.lead', ['name' => $order->customer_name, 'phone' => $order->customer_phone]),
                'paymentLine' => $paid
                    ? __('panel.mail.paidOnline')
                    : __('panel.mail.cashOnDelivery', ['amount' => Money::format(Money::fromFils($order->total_fils), Money::KWD)]),
                'button' => ['label' => __('panel.mail.open'), 'url' => route('panel.orders.show', $order)],
                'audience' => 'staff',
            ],
        );
    }
}
