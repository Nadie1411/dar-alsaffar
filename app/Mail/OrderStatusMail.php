<?php

namespace App\Mail;

use App\Enums\OrderStatus;
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
 * Tells a customer that their order is on its way, has been delivered or has
 * been cancelled — the moves worth interrupting someone for.
 */
class OrderStatusMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** The statuses a customer is emailed about. */
    public const NOTIFIED = [OrderStatus::OutForDelivery, OrderStatus::Delivered, OrderStatus::Cancelled];

    public function __construct(public Order $order, public OrderStatus $status)
    {
        $this->locale($order->locale === 'en' ? 'en' : 'ar');
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('storefront.mail.statusSubject.'.$this->status->value, ['number' => $this->order->number]));
    }

    public function content(): Content
    {
        $order = $this->order->loadMissing('items');
        $lang = $this->locale;
        $paid = $order->payment_status === PaymentStatus::Paid;

        return new Content(
            view: 'emails.order',
            with: [
                'order' => $order,
                'lang' => $lang,
                'greeting' => __('storefront.mail.greeting', ['name' => $order->customer_name]),
                'headline' => __('storefront.mail.statusHeadline.'.$this->status->value),
                'lead' => __('storefront.mail.statusLead.'.$this->status->value),
                // A paid order that is cancelled needs a word about the money — an invitation to ask, never a promise.
                'note' => $this->status === OrderStatus::Cancelled && $paid && ! $order->isCashOnDelivery() ? __('storefront.mail.cancelledPaid') : null,
                'paymentLine' => $paid
                    ? __('storefront.mail.paidOnline')
                    : __('storefront.mail.payOnDelivery', ['amount' => Money::format(Money::fromFils($order->total_fils), Money::KWD)]),
                'button' => $order->customer_id === null ? null : [
                    'label' => __('storefront.mail.viewOrder'),
                    'url' => url('/'.($lang === 'en' ? 'en-KW' : 'ar-KW').'/account/orders/'.$order->number),
                ],
                'audience' => 'customer',
            ],
        );
    }
}
