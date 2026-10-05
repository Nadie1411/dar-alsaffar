<?php

namespace App\Services\Store\Payments;

use App\Enums\OrderStatus;
use App\Enums\PaymentState;
use App\Enums\PaymentStatus;
use App\Events\OrderPlaced;
use App\Http\Middleware\SetLocale;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Store\OrderLifecycle;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Takes an order's payment through MyFatoorah and settles the order from it.
 *
 * Three rules govern everything here:
 *
 *  1. An order is marked paid only on MyFatoorah's own word. A redirect back
 *     to the site, or a webhook, only says "go and look": the payment is then
 *     fetched from MyFatoorah's API, and that answer is what counts.
 *  2. The amount MyFatoorah says was paid must equal what the order costs.
 *  3. Doing any of it twice does nothing more. The redirect, the webhook and
 *     the reconcile job can all arrive for the same payment, in any order,
 *     even at once; whichever gets there first settles it and the rest see it
 *     is already done.
 */
class PaymentService
{
    /** @var array<int,string> the payment method names MyFatoorah's create call accepts */
    protected const METHODS = ['CARD', 'APPLE_PAY', 'GOOGLE_PAY', 'KNET'];

    public function __construct(
        protected MyFatoorahClient $client,
        protected OrderLifecycle $lifecycle,
    ) {}

    // ------------------------------------------------------------------ start

    /**
     * Open an invoice for the order and return the attempt, with the hosted
     * page to send the shopper to.
     *
     * @param  string|null  $method  KNET, CARD, APPLE_PAY, GOOGLE_PAY — or null to let MyFatoorah's page offer every enabled method
     *
     * @throws MyFatoorahException when the invoice could not be created
     */
    public function start(Order $order, ?string $method = null): Payment
    {
        $method = $method !== null && in_array(strtoupper($method), self::METHODS, true) ? strtoupper($method) : null;

        $payment = Payment::query()->create([
            'order_id' => $order->id,
            'reference' => (string) Str::ulid(),
            'state' => PaymentState::Pending,
            'amount_fils' => $order->total_fils,
            'currency' => $order->currency,
            'method' => $method,
            'expires_at' => now()->addMinutes((int) config('myfatoorah.expiry_minutes')),
        ]);

        try {
            $invoice = $this->client->createPayment($this->payload($order, $payment, $method));
        } catch (MyFatoorahException $e) {
            $payment->update(['state' => PaymentState::Failed, 'failure_reason' => 'The payment page could not be created.']);

            throw $e;
        }

        $payment->update(['mf_invoice_id' => $invoice['invoiceId'], 'mf_payment_url' => $invoice['paymentUrl']]);

        return $payment;
    }

    /**
     * The attempt to send the shopper back to: the one they already have if it
     * can still be paid, so a retry never opens a second invoice for an order
     * the first of which might yet be paid.
     */
    public function resume(Order $order, ?string $method = null): Payment
    {
        $open = $order->payments()->where('state', PaymentState::Pending->value)->latest('id')->get()
            ->first(fn (Payment $payment) => $payment->isPayable());

        return $open ?? $this->start($order, $method);
    }

    /**
     * @return array<string,mixed>
     */
    protected function payload(Order $order, Payment $payment, ?string $method): array
    {
        $dial = (string) config('brand.country.dial');
        $digits = ltrim($order->customer_phone, '+');
        $local = str_starts_with($digits, $dial) ? substr($digits, strlen($dial)) : $digits;
        $locale = $order->locale === 'en' ? 'en-KW' : SetLocale::DEFAULT;

        $payload = [
            'Order' => [
                'Amount' => Money::fromFils($payment->amount_fils),
                'Currency' => $payment->currency,
                // Comes back on every status lookup and webhook, and is how a
                // payment is matched to this attempt.
                'ExternalIdentifier' => $payment->reference,
            ],
            'Customer' => array_filter([
                'Name' => $order->customer_name,
                'Mobile' => ['CountryCode' => '+'.$dial, 'Number' => $local],
                'Email' => $order->customer_email,
                'Reference' => $order->number,
            ]),
            'IntegrationUrls' => [
                'Redirection' => $this->callbackUrl('payment.return', ['locale' => $locale]),
                'Webhook' => $this->callbackUrl('webhooks.myfatoorah'),
            ],
            'Language' => $order->locale === 'en' ? 'EN' : 'AR',
            'PaymentExpiry' => $payment->expires_at->clone()->utc()->format('Y-m-d\TH:i:s\Z'),
            'MetaData' => ['UDF1' => $order->number],
        ];

        if ($method !== null) {
            $payload['PaymentMethod'] = $method;
        }

        return $payload;
    }

    /**
     * The public address of one of this shop's routes, for MyFatoorah to call
     * or send the shopper to — on the configured host when there is one.
     *
     * @param  array<string,string>  $parameters
     */
    public function callbackUrl(string $route, array $parameters = []): string
    {
        $base = rtrim((string) config('myfatoorah.callback_base_url'), '/');

        return $base === '' ? route($route, $parameters) : $base.route($route, $parameters, absolute: false);
    }

    // ---------------------------------------------------------------- confirm

    /**
     * Check a payment with MyFatoorah and settle the order if it was paid.
     *
     * @throws MyFatoorahUnavailable when MyFatoorah cannot be asked — nothing is decided, ask again later
     * @throws MyFatoorahException when MyFatoorah refuses to answer (a key that is not allowed to read payments)
     */
    public function confirm(string $paymentId): PaymentOutcome
    {
        $details = $this->client->paymentDetails($paymentId);

        return $details === null ? PaymentOutcome::unknown() : $this->apply($details);
    }

    /**
     * @param  array<string,mixed>  $details  a payment as MyFatoorah's API returned it
     */
    protected function apply(array $details): PaymentOutcome
    {
        $payment = $this->find($details);

        if ($payment === null) {
            // The MyFatoorah account can serve other systems too; their
            // payments are none of ours.
            Log::info('A MyFatoorah payment that is not one of this shop\'s was ignored', [
                'invoice' => data_get($details, 'Invoice.Id'),
            ]);

            return PaymentOutcome::unknown();
        }

        if ($payment->state === PaymentState::Paid) {
            return PaymentOutcome::paid($payment);
        }

        return match ($this->stateOf($details)) {
            PaymentState::Paid => $this->markPaid($payment, $details),
            PaymentState::Failed => $this->markFailed($payment, $details),
            PaymentState::Cancelled => $this->markCancelled($payment),
            default => $this->touch($payment),
        };
    }

    /**
     * @param  array<string,mixed>  $details
     */
    protected function find(array $details): ?Payment
    {
        $reference = (string) data_get($details, 'Invoice.ExternalIdentifier', '');
        $invoiceId = (string) data_get($details, 'Invoice.Id', '');

        $payment = $reference === '' ? null : Payment::query()->where('reference', $reference)->first();

        return $payment ?? ($invoiceId === '' ? null : Payment::query()->where('mf_invoice_id', $invoiceId)->first());
    }

    /**
     * How MyFatoorah's invoice and transaction statuses add up. Only an invoice
     * that is PAID with a SUCCESS transaction is a payment; a FAILED or
     * CANCELED transaction is one failed attempt on an invoice that may still
     * be paid; a CANCELED invoice is over.
     *
     * @param  array<string,mixed>  $details
     */
    protected function stateOf(array $details): PaymentState
    {
        $invoice = strtoupper((string) data_get($details, 'Invoice.Status'));
        $transaction = strtoupper((string) data_get($details, 'Transaction.Status'));

        return match (true) {
            $invoice === 'PAID' && $transaction === 'SUCCESS' => PaymentState::Paid,
            $invoice === 'CANCELED' => PaymentState::Cancelled,
            in_array($transaction, ['FAILED', 'CANCELED'], true) => PaymentState::Failed,
            default => PaymentState::Pending,
        };
    }

    /**
     * @param  array<string,mixed>  $details
     */
    protected function markPaid(Payment $payment, array $details): PaymentOutcome
    {
        if (! $this->amountMatches($payment, $details)) {
            // Money moved, but not the amount this order costs. Nothing is
            // fulfilled automatically: a person decides.
            $payment->update(['anomaly' => Payment::ANOMALY_AMOUNT_MISMATCH, 'verified_at' => now()]);
            Log::critical('A MyFatoorah payment does not match its order amount', [
                'payment' => $payment->reference,
                'expected_fils' => $payment->amount_fils,
                'paid' => data_get($details, 'Amount'),
            ]);

            return PaymentOutcome::pending($payment->refresh());
        }

        // The conditional update is the lock: of any number of simultaneous
        // confirmations, exactly one finds the payment not yet paid.
        $won = Payment::query()
            ->whereKey($payment->id)
            ->where('state', '!=', PaymentState::Paid->value)
            ->update([
                'state' => PaymentState::Paid->value,
                'mf_payment_id' => data_get($details, 'Transaction.PaymentId'),
                'mf_method' => data_get($details, 'Transaction.PaymentMethod'),
                'mf_transaction_id' => data_get($details, 'Transaction.Id'),
                'mf_reference_id' => data_get($details, 'Transaction.ReferenceId'),
                'mf_track_id' => data_get($details, 'Transaction.TrackId'),
                'failure_reason' => null,
                'anomaly' => null,
                'paid_at' => now(),
                'verified_at' => now(),
            ]);

        $payment->refresh();

        if ($won === 1) {
            $this->settleOrder($payment);
        }

        return PaymentOutcome::paid($payment->refresh());
    }

    /**
     * Mark the order paid and let the shop know it has an order to fulfil.
     */
    protected function settleOrder(Payment $payment): void
    {
        $order = Order::query()->findOrFail($payment->order_id);

        if ($order->payment_status === PaymentStatus::Paid) {
            $payment->update(['anomaly' => Payment::ANOMALY_DUPLICATE]);
            Log::critical('A second MyFatoorah payment arrived for an order that is already paid', ['order' => $order->number, 'payment' => $payment->reference]);

            return;
        }

        if ($order->status === OrderStatus::Cancelled) {
            $payment->update(['anomaly' => Payment::ANOMALY_ORDER_CANCELLED]);
            Log::critical('A MyFatoorah payment arrived for an order that was already cancelled', ['order' => $order->number, 'payment' => $payment->reference]);

            return;
        }

        DB::transaction(function () use ($order, $payment): void {
            $order->update(['payment_status' => PaymentStatus::Paid, 'paid_at' => now()]);

            if ($order->status === OrderStatus::PendingPayment) {
                $this->lifecycle->moveTo($order, OrderStatus::New, null, 'Paid online'.($payment->mf_method ? " ({$payment->mf_method})" : ''));
            }
        });

        OrderPlaced::dispatch($order->refresh());
    }

    /**
     * @param  array<string,mixed>  $details
     */
    protected function amountMatches(Payment $payment, array $details): bool
    {
        foreach (['Display', 'Base'] as $kind) {
            $currency = strtoupper((string) data_get($details, "Amount.{$kind}Currency"));
            $value = data_get($details, "Amount.ValueIn{$kind}Currency");

            if ($currency === strtoupper($payment->currency) && is_numeric($value) && Money::toFils($value) === $payment->amount_fils) {
                return true;
            }
        }

        return false;
    }

    /**
     * A failed attempt. The shopper can try again on the same invoice, and a
     * success that follows replaces this, so the order is left waiting.
     *
     * @param  array<string,mixed>  $details
     */
    protected function markFailed(Payment $payment, array $details): PaymentOutcome
    {
        $reason = data_get($details, 'Transaction.Error.Message') ?: data_get($details, 'Transaction.Error.Code') ?: 'The payment was not completed.';

        $payment->update([
            'state' => PaymentState::Failed,
            'failure_reason' => mb_substr((string) $reason, 0, 255),
            'verified_at' => now(),
        ]);

        return PaymentOutcome::failed($payment);
    }

    protected function markCancelled(Payment $payment): PaymentOutcome
    {
        $payment->update(['state' => PaymentState::Cancelled, 'verified_at' => now()]);
        $this->releaseOrderIfNoPaymentIsOpen($payment, 'Payment page cancelled');

        return PaymentOutcome::cancelled($payment);
    }

    protected function touch(Payment $payment): PaymentOutcome
    {
        $payment->update(['verified_at' => now()]);

        return PaymentOutcome::pending($payment);
    }

    /**
     * An order whose payment has fallen through goes back on the shelf —
     * unless the shopper has another attempt that could still be paid.
     */
    protected function releaseOrderIfNoPaymentIsOpen(Payment $payment, string $note): void
    {
        $order = Order::query()->find($payment->order_id);

        if ($order === null || $order->status !== OrderStatus::PendingPayment) {
            return;
        }

        $anotherIsOpen = Payment::query()
            ->where('order_id', $order->id)
            ->whereKeyNot($payment->id)
            ->where('state', PaymentState::Pending->value)
            ->exists();

        if (! $anotherIsOpen) {
            $this->lifecycle->moveTo($order, OrderStatus::Cancelled, null, $note);
        }
    }

    /**
     * Ask MyFatoorah about one payment right now, as "check again" does in the
     * admin panel. The same lookup the scheduled reconciliation makes: an
     * invoice whose payment id was never learned is found from the invoice id.
     *
     * @throws MyFatoorahUnavailable when MyFatoorah cannot be asked
     * @throws MyFatoorahException when MyFatoorah refuses to answer
     */
    public function recheck(Payment $payment): PaymentOutcome
    {
        $paymentId = $payment->mf_payment_id ?? $this->locate($payment);

        return $paymentId === null ? PaymentOutcome::pending($payment) : $this->confirm($paymentId);
    }

    // -------------------------------------------------------------- reconcile

    /**
     * Catch up on payments whose shopper never came back and whose webhook
     * never arrived, and release orders whose invoice has run out.
     *
     * @return array{checked:int,paid:int,expired:int,unreachable:int}
     */
    public function reconcile(): array
    {
        $counts = ['checked' => 0, 'paid' => 0, 'expired' => 0, 'unreachable' => 0];

        Payment::query()
            ->where('state', PaymentState::Pending->value)
            ->where('created_at', '<=', now()->subMinutes(3))
            ->orderBy('id')
            ->chunkById(50, function ($payments) use (&$counts): void {
                foreach ($payments as $payment) {
                    $counts['checked']++;

                    try {
                        $paymentId = $payment->mf_payment_id ?? $this->locate($payment);

                        if ($paymentId !== null && $this->confirm($paymentId)->isPaid()) {
                            $counts['paid']++;

                            continue;
                        }
                    } catch (MyFatoorahUnavailable) {
                        $counts['unreachable']++;

                        continue;
                    } catch (MyFatoorahException $e) {
                        Log::warning('A payment could not be reconciled', ['payment' => $payment->reference, 'error' => $e->getMessage()]);

                        continue;
                    }

                    if ($this->hasRunOut($payment->refresh())) {
                        $payment->update(['state' => PaymentState::Expired]);
                        $this->releaseOrderIfNoPaymentIsOpen($payment, 'Payment not completed in time');
                        $counts['expired']++;
                    }
                }
            });

        return $counts;
    }

    /**
     * The MyFatoorah payment id of an invoice, found from the invoice id.
     * A success is preferred; otherwise the latest attempt, so a failure is seen.
     */
    protected function locate(Payment $payment): ?string
    {
        if ($payment->mf_invoice_id === null) {
            return null;
        }

        $transactions = collect($this->client->invoiceTransactions($payment->mf_invoice_id));

        return ($transactions->first(fn (array $transaction) => in_array(strtolower($transaction['status']), ['succss', 'success'], true))
            ?? $transactions->last())['paymentId'] ?? null;
    }

    /** Whether the invoice, and the grace period after it, are over. */
    protected function hasRunOut(Payment $payment): bool
    {
        return $payment->state === PaymentState::Pending
            && $payment->expires_at !== null
            && $payment->expires_at->clone()->addMinutes((int) config('myfatoorah.expiry_grace_minutes'))->isPast();
    }
}
