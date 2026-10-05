<?php

namespace Tests\Feature\Services\Store\Payments;

use App\Enums\OrderStatus;
use App\Enums\PaymentState;
use App\Enums\PaymentStatus;
use App\Events\OrderPlaced;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Services\Store\Payments\MyFatoorahException;
use App\Services\Store\Payments\MyFatoorahUnavailable;
use App\Services\Store\Payments\PaymentOutcome;
use App\Services\Store\Payments\PaymentService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\FakesMyFatoorah;
use Tests\TestCase;

class PaymentServiceTest extends TestCase
{
    use FakesMyFatoorah, LazilyRefreshDatabase;

    private const PAYMENT_ID = '07076389491322460173';

    private function payments(): PaymentService
    {
        return $this->app->make(PaymentService::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureMyFatoorah();
    }

    /**
     * An order waiting for its KWD 40 payment, and the attempt made for it.
     *
     * @param  array<string,mixed>  $paymentOverrides
     * @return array{0:Order,1:Payment}
     */
    private function orderWithPayment(array $paymentOverrides = []): array
    {
        $order = Order::factory()->pendingPayment()->create(['total_fils' => 40_000]);
        $payment = Payment::factory()->create(array_merge(['order_id' => $order->id, 'reference' => 'REF-'.$order->id, 'amount_fils' => 40_000], $paymentOverrides));

        return [$order, $payment];
    }

    /**
     * Have MyFatoorah answer the details lookup for the test payment.
     *
     * @param  array<string,mixed>  $changes
     */
    private function answerWith(Payment $payment, array $changes = []): void
    {
        $this->fakeMyFatoorah([self::MF.'/v3/payments/'.self::PAYMENT_ID => Http::response($this->paymentDetails($payment->reference, $changes))]);
    }

    // ------------------------------------------------------------------ start

    public function test_starting_a_payment_makes_an_attempt_and_tells_my_fatoorah_the_order_the_customer_and_where_to_come_back(): void
    {
        $this->freezeTime();
        $this->fakeMyFatoorah();
        $order = Order::factory()->pendingPayment()->create([
            'total_fils' => 40_000, 'customer_name' => 'Sara Al-Ahmad', 'customer_email' => 'sara@example.com',
            'customer_phone' => '+96551234567', 'locale' => 'ar',
        ]);

        $payment = $this->payments()->start($order);

        $this->assertSame([PaymentState::Pending, 40_000, 'KWD', '6148108'], [$payment->state, $payment->amount_fils, $payment->currency, $payment->mf_invoice_id]);
        $this->assertSame('https://demo.MyFatoorah.com/KWT/ie/050754719614810863-ce9138bf', $payment->mf_payment_url);
        $this->assertSame(now()->addMinutes(60)->toDateTimeString(), $payment->expires_at->toDateTimeString());
        Http::assertSent(fn (Request $request) => $request->url() === 'https://apitest.myfatoorah.com/v3/payments' && $request->data() === [
            'Order' => ['Amount' => 40, 'Currency' => 'KWD', 'ExternalIdentifier' => $payment->reference],
            'Customer' => [
                'Name' => 'Sara Al-Ahmad',
                'Mobile' => ['CountryCode' => '+965', 'Number' => '51234567'],
                'Email' => 'sara@example.com',
                'Reference' => $order->number,
            ],
            'IntegrationUrls' => [
                'Redirection' => url('/ar-KW/payment/return'),
                'Webhook' => url('/api/webhooks/myfatoorah'),
            ],
            'Language' => 'AR',
            'PaymentExpiry' => now()->addMinutes(60)->utc()->format('Y-m-d\TH:i:s\Z'),
            'MetaData' => ['UDF1' => $order->number],
        ]);
    }

    public function test_the_return_and_webhook_addresses_can_be_set_to_another_public_host(): void
    {
        config(['myfatoorah.callback_base_url' => 'https://shop.example.com/']);
        $this->fakeMyFatoorah();
        $order = Order::factory()->pendingPayment()->create(['locale' => 'en']);

        $this->payments()->start($order);

        Http::assertSent(fn (Request $request) => $request['IntegrationUrls'] === [
            'Redirection' => 'https://shop.example.com/en-KW/payment/return',
            'Webhook' => 'https://shop.example.com/api/webhooks/myfatoorah',
        ]);
    }

    public function test_the_reference_sent_to_my_fatoorah_is_unique_to_each_attempt(): void
    {
        $this->fakeMyFatoorah([self::MF.'/v3/payments' => Http::sequence()
            ->push($this->createdInvoice('7000001'), 201)
            ->push($this->createdInvoice('7000002'), 201)]);
        $order = Order::factory()->pendingPayment()->create();

        $first = $this->payments()->start($order);
        $second = $this->payments()->start($order);

        $this->assertNotSame($first->reference, $second->reference);
        $this->assertSame(26, strlen($first->reference));
    }

    public function test_a_chosen_method_is_sent_and_one_my_fatoorah_does_not_accept_is_left_out(): void
    {
        $this->fakeMyFatoorah([self::MF.'/v3/payments' => Http::sequence()
            ->push($this->createdInvoice('7000001'), 201)
            ->push($this->createdInvoice('7000002'), 201)
            ->push($this->createdInvoice('7000003'), 201)]);
        $order = Order::factory()->pendingPayment()->create();

        $knet = $this->payments()->start($order, 'knet');
        $odd = $this->payments()->start($order, 'bitcoin');
        $any = $this->payments()->start($order, null);

        $this->assertSame(['KNET', null, null], [$knet->method, $odd->method, $any->method]);
        $sent = Http::recorded()->map(fn (array $pair) => $pair[0]->data());
        $this->assertSame(['KNET', null, null], $sent->map(fn (array $data) => $data['PaymentMethod'] ?? null)->all());
    }

    public function test_an_english_order_with_no_email_comes_back_to_the_english_site_in_english(): void
    {
        $this->fakeMyFatoorah();
        $order = Order::factory()->pendingPayment()->create(['locale' => 'en', 'customer_email' => null, 'total_fils' => 5_125]);

        $this->payments()->start($order);

        Http::assertSent(fn (Request $request) => $request['Language'] === 'EN'
            && $request['IntegrationUrls']['Redirection'] === url('/en-KW/payment/return')
            && ! array_key_exists('Email', $request['Customer'])
            && $request['Order']['Amount'] === 5.125);
    }

    public function test_when_the_payment_page_cannot_be_created_the_attempt_is_marked_failed_and_the_error_surfaces(): void
    {
        $this->fakeMyFatoorah([self::MF.'/v3/payments' => Http::response(['IsSuccess' => false, 'Message' => 'no'], 400)]);
        $order = Order::factory()->pendingPayment()->create();

        try {
            $this->payments()->start($order);
            $this->fail('The refusal should have surfaced.');
        } catch (MyFatoorahException) {
            $payment = $order->payments()->firstOrFail();
            $this->assertSame([PaymentState::Failed, null, null], [$payment->state, $payment->mf_invoice_id, $payment->mf_payment_url]);
        }
    }

    // ------------------------------------------------------------------ resume

    public function test_resuming_returns_the_attempt_that_can_still_be_paid_without_opening_another_invoice(): void
    {
        Http::preventStrayRequests();
        [$order, $open] = $this->orderWithPayment();

        $resumed = $this->payments()->resume($order);

        $this->assertTrue($resumed->is($open));
        Http::assertNothingSent();
    }

    public function test_resuming_opens_a_new_invoice_when_the_old_one_has_run_out_or_failed(): void
    {
        $this->fakeMyFatoorah();
        [$order] = $this->orderWithPayment(['expires_at' => now()->subMinute()]);
        Payment::factory()->create(['order_id' => $order->id, 'state' => PaymentState::Failed]);

        $resumed = $this->payments()->resume($order);

        $this->assertSame(3, $order->payments()->count());
        $this->assertSame('6148108', $resumed->mf_invoice_id);
        Http::assertSentCount(1);
    }

    // ----------------------------------------------------- confirming: success

    public function test_a_paid_payment_settles_the_order_records_it_and_announces_the_new_order(): void
    {
        Event::fake([OrderPlaced::class]);
        [$order, $payment] = $this->orderWithPayment();
        $this->answerWith($payment);

        $outcome = $this->payments()->confirm(self::PAYMENT_ID);

        $this->assertSame(PaymentOutcome::PAID, $outcome->result);
        $payment->refresh();
        $this->assertSame([PaymentState::Paid, self::PAYMENT_ID, 'VISA/MASTER', '102585', '535814102585', '24-12-2025_3224601'], [
            $payment->state, $payment->mf_payment_id, $payment->mf_method, $payment->mf_transaction_id, $payment->mf_reference_id, $payment->mf_track_id,
        ]);
        $this->assertNotNull($payment->paid_at);
        $this->assertNotNull($payment->verified_at);
        $this->assertNull($payment->anomaly);
        $order->refresh();
        $this->assertSame([PaymentStatus::Paid, OrderStatus::New], [$order->payment_status, $order->status]);
        $this->assertNotNull($order->paid_at);
        $change = $order->statusChanges()->firstOrFail();
        $this->assertSame([OrderStatus::PendingPayment, OrderStatus::New, 'Paid online (VISA/MASTER)'], [$change->from_status, $change->to_status, $change->note]);
        Event::assertDispatchedTimes(OrderPlaced::class, 1);
    }

    public function test_confirming_the_same_payment_again_changes_nothing_more(): void
    {
        Event::fake([OrderPlaced::class]);
        [$order, $payment] = $this->orderWithPayment();
        $this->answerWith($payment);

        $first = $this->payments()->confirm(self::PAYMENT_ID);
        $paidAt = $payment->refresh()->paid_at;
        $this->travel(5)->minutes();
        $second = $this->payments()->confirm(self::PAYMENT_ID);

        $this->assertTrue($first->isPaid());
        $this->assertTrue($second->isPaid());
        $this->assertSame(1, $order->statusChanges()->count());
        $this->assertTrue($paidAt->equalTo($payment->refresh()->paid_at));
        Event::assertDispatchedTimes(OrderPlaced::class, 1);
    }

    public function test_two_confirmations_that_overlap_settle_the_order_once(): void
    {
        Event::fake([OrderPlaced::class]);
        [$order, $payment] = $this->orderWithPayment();
        $this->answerWith($payment);
        // The redirect and the webhook arrive together. Just after the first
        // has read the payment as unpaid, the second runs to the end.
        $raced = false;
        DB::listen(function ($query) use (&$raced): void {
            if (! $raced && str_contains($query->sql, 'from "payments" where "reference" = ?')) {
                $raced = true;
                $this->payments()->confirm(self::PAYMENT_ID);
            }
        });

        $outcome = $this->payments()->confirm(self::PAYMENT_ID);

        $this->assertTrue($raced);
        $this->assertTrue($outcome->isPaid());
        // Settled once, cleanly: the loser of the race must not be mistaken for a second payment.
        $this->assertNull($payment->refresh()->anomaly);
        $this->assertSame(1, $order->statusChanges()->count());
        $this->assertSame(OrderStatus::New, $order->refresh()->status);
        Event::assertDispatchedTimes(OrderPlaced::class, 1);
    }

    public function test_status_words_are_read_whatever_their_case(): void
    {
        [$order, $payment] = $this->orderWithPayment();
        $this->answerWith($payment, ['Invoice.Status' => 'Paid', 'Transaction.Status' => 'success']);

        $this->assertTrue($this->payments()->confirm(self::PAYMENT_ID)->isPaid());
        $this->assertSame(PaymentStatus::Paid, $order->refresh()->payment_status);
    }

    public function test_a_payment_is_found_by_its_invoice_when_the_reference_did_not_come_back(): void
    {
        [$order, $payment] = $this->orderWithPayment(['mf_invoice_id' => '6148108']);
        $this->answerWith($payment, ['Invoice.ExternalIdentifier' => null, 'Invoice.Id' => '6148108']);

        $this->assertTrue($this->payments()->confirm(self::PAYMENT_ID)->isPaid());
        $this->assertSame(PaymentStatus::Paid, $order->refresh()->payment_status);
    }

    public function test_an_invoice_that_is_paid_but_whose_transaction_is_not_a_success_is_not_a_payment(): void
    {
        [$order, $payment] = $this->orderWithPayment();
        $this->answerWith($payment, ['Transaction.Status' => 'INPROGRESS']);

        $outcome = $this->payments()->confirm(self::PAYMENT_ID);

        $this->assertSame(PaymentOutcome::PENDING, $outcome->result);
        $this->assertSame(PaymentStatus::Unpaid, $order->refresh()->payment_status);
    }

    // ------------------------------------------------- the amount must match

    /**
     * @return array<string, array{array<string,string>}>
     */
    public static function wrongAmounts(): array
    {
        return [
            'less than the order costs' => [['Amount.ValueInDisplayCurrency' => '39', 'Amount.ValueInBaseCurrency' => '39']],
            'more than the order costs' => [['Amount.ValueInDisplayCurrency' => '41', 'Amount.ValueInBaseCurrency' => '41']],
            'the right number in another currency' => [['Amount.DisplayCurrency' => 'SAR', 'Amount.BaseCurrency' => 'SAR']],
            'no amount at all' => [['Amount' => null]],
        ];
    }

    /**
     * @param  array<string,string|null>  $changes
     */
    #[DataProvider('wrongAmounts')]
    public function test_a_payment_for_the_wrong_amount_is_never_fulfilled_and_is_flagged_for_a_person(array $changes): void
    {
        Event::fake([OrderPlaced::class]);
        Log::spy();
        [$order, $payment] = $this->orderWithPayment();
        $this->answerWith($payment, $changes);

        $outcome = $this->payments()->confirm(self::PAYMENT_ID);

        $this->assertSame(PaymentOutcome::PENDING, $outcome->result);
        $this->assertSame([PaymentState::Pending, 'amount_mismatch'], [$payment->refresh()->state, $payment->anomaly]);
        $this->assertSame([OrderStatus::PendingPayment, PaymentStatus::Unpaid], [$order->refresh()->status, $order->payment_status]);
        Event::assertNotDispatched(OrderPlaced::class);
        Log::shouldHaveReceived('critical')->once();
    }

    public function test_the_account_may_show_a_different_display_currency_when_the_base_amount_is_right(): void
    {
        [$order, $payment] = $this->orderWithPayment();
        $this->answerWith($payment, ['Amount.DisplayCurrency' => 'USD', 'Amount.ValueInDisplayCurrency' => '130']);

        $this->assertTrue($this->payments()->confirm(self::PAYMENT_ID)->isPaid());
    }

    public function test_an_amount_with_trailing_zeros_still_matches(): void
    {
        [$order, $payment] = $this->orderWithPayment(['amount_fils' => 19_500]);
        $this->answerWith($payment, ['Amount.ValueInDisplayCurrency' => '19.500', 'Amount.ValueInBaseCurrency' => '19.500']);

        $this->assertTrue($this->payments()->confirm(self::PAYMENT_ID)->isPaid());
    }

    // ------------------------------------------------- failure and in between

    public function test_a_failed_attempt_is_recorded_with_its_reason_and_the_order_keeps_waiting(): void
    {
        [$order, $payment] = $this->orderWithPayment();
        $this->answerWith($payment, [
            'Invoice.Status' => 'PENDING', 'Transaction.Status' => 'FAILED',
            'Transaction.Error.Message' => 'Insufficient funds', 'Transaction.Error.Code' => 'MF002',
        ]);

        $outcome = $this->payments()->confirm(self::PAYMENT_ID);

        $this->assertSame(PaymentOutcome::FAILED, $outcome->result);
        $this->assertSame([PaymentState::Failed, 'Insufficient funds'], [$payment->refresh()->state, $payment->failure_reason]);
        $this->assertSame([OrderStatus::PendingPayment, PaymentStatus::Unpaid], [$order->refresh()->status, $order->payment_status]);
    }

    public function test_a_success_that_follows_a_failure_on_the_same_invoice_settles_the_order(): void
    {
        [$order, $payment] = $this->orderWithPayment(['state' => PaymentState::Failed, 'failure_reason' => 'Declined']);
        $this->answerWith($payment);

        $outcome = $this->payments()->confirm(self::PAYMENT_ID);

        $this->assertTrue($outcome->isPaid());
        $this->assertSame([PaymentState::Paid, null], [$payment->refresh()->state, $payment->failure_reason]);
        $this->assertSame(OrderStatus::New, $order->refresh()->status);
    }

    public function test_a_transaction_the_customer_cancelled_at_the_gateway_is_a_failed_attempt_not_the_end_of_the_order(): void
    {
        [$order, $payment] = $this->orderWithPayment();
        $this->answerWith($payment, ['Invoice.Status' => 'PENDING', 'Transaction.Status' => 'CANCELED']);

        $outcome = $this->payments()->confirm(self::PAYMENT_ID);

        $this->assertSame(PaymentOutcome::FAILED, $outcome->result);
        $this->assertSame(OrderStatus::PendingPayment, $order->refresh()->status);
    }

    public function test_a_payment_still_in_progress_stays_pending(): void
    {
        [$order, $payment] = $this->orderWithPayment();
        $this->answerWith($payment, ['Invoice.Status' => 'PENDING', 'Transaction.Status' => 'INPROGRESS']);

        $outcome = $this->payments()->confirm(self::PAYMENT_ID);

        $this->assertSame(PaymentOutcome::PENDING, $outcome->result);
        $this->assertSame(PaymentState::Pending, $payment->refresh()->state);
        $this->assertNotNull($payment->verified_at);
    }

    public function test_a_cancelled_invoice_cancels_the_order_and_puts_its_stock_back(): void
    {
        [$order, $payment] = $this->orderWithPayment();
        $product = Product::factory()->withStock(4)->create();
        $order->items()->create(['product_id' => $product->id, 'name_ar' => 'أ', 'name_en' => 'A', 'quantity' => 3, 'unit_price_fils' => 1_000, 'total_fils' => 3_000]);
        $this->answerWith($payment, ['Invoice.Status' => 'CANCELED', 'Transaction.Status' => 'CANCELED']);

        $outcome = $this->payments()->confirm(self::PAYMENT_ID);

        $this->assertSame(PaymentOutcome::CANCELLED, $outcome->result);
        $this->assertSame(PaymentState::Cancelled, $payment->refresh()->state);
        $this->assertSame(OrderStatus::Cancelled, $order->refresh()->status);
        $this->assertSame(7, $product->fresh()->stock);
    }

    public function test_a_cancelled_invoice_leaves_the_order_alone_while_another_attempt_could_still_be_paid(): void
    {
        [$order, $payment] = $this->orderWithPayment();
        Payment::factory()->create(['order_id' => $order->id]);
        $this->answerWith($payment, ['Invoice.Status' => 'CANCELED', 'Transaction.Status' => 'CANCELED']);

        $this->payments()->confirm(self::PAYMENT_ID);

        $this->assertSame(OrderStatus::PendingPayment, $order->refresh()->status);
    }

    // ----------------------------------------------------------- anomalies

    public function test_a_payment_for_an_order_already_cancelled_is_kept_and_flagged_not_silently_fulfilled(): void
    {
        Event::fake([OrderPlaced::class]);
        Log::spy();
        [$order, $payment] = $this->orderWithPayment();
        $order->update(['status' => OrderStatus::Cancelled]);
        $this->answerWith($payment);

        $outcome = $this->payments()->confirm(self::PAYMENT_ID);

        $this->assertTrue($outcome->isPaid());
        $this->assertSame([PaymentState::Paid, 'order_cancelled'], [$payment->refresh()->state, $payment->anomaly]);
        $this->assertSame([OrderStatus::Cancelled, PaymentStatus::Unpaid], [$order->refresh()->status, $order->payment_status]);
        Event::assertNotDispatched(OrderPlaced::class);
        Log::shouldHaveReceived('critical')->once();
    }

    public function test_a_second_payment_for_an_order_that_is_already_paid_is_flagged_as_a_duplicate(): void
    {
        Event::fake([OrderPlaced::class]);
        [$order, $first] = $this->orderWithPayment();
        $this->answerWith($first);
        $this->payments()->confirm(self::PAYMENT_ID);
        $second = Payment::factory()->create(['order_id' => $order->id, 'reference' => 'REF-SECOND', 'amount_fils' => 40_000]);
        $this->fakeMyFatoorah(['*/v3/payments/07076389491322460999' => Http::response($this->paymentDetails('REF-SECOND', ['Transaction.PaymentId' => '07076389491322460999']))]);

        $outcome = $this->payments()->confirm('07076389491322460999');

        $this->assertTrue($outcome->isPaid());
        $this->assertSame([PaymentState::Paid, 'duplicate_payment'], [$second->refresh()->state, $second->anomaly]);
        $this->assertSame(1, $order->statusChanges()->count());
        Event::assertDispatchedTimes(OrderPlaced::class, 1);
    }

    public function test_paying_never_moves_an_order_backwards_if_staff_already_handled_it(): void
    {
        [$order, $payment] = $this->orderWithPayment();
        $order->update(['status' => OrderStatus::Preparing]);
        $this->answerWith($payment);

        $this->payments()->confirm(self::PAYMENT_ID);

        $this->assertSame([OrderStatus::Preparing, PaymentStatus::Paid], [$order->refresh()->status, $order->payment_status]);
        $this->assertSame(0, $order->statusChanges()->count());
    }

    public function test_a_payment_that_is_not_one_of_ours_is_ignored(): void
    {
        Event::fake([OrderPlaced::class]);
        $this->fakeMyFatoorah([self::MF.'/v3/payments/'.self::PAYMENT_ID => Http::response($this->paymentDetails('someone-elses-reference'))]);

        $outcome = $this->payments()->confirm(self::PAYMENT_ID);

        $this->assertSame(PaymentOutcome::UNKNOWN, $outcome->result);
        Event::assertNotDispatched(OrderPlaced::class);
    }

    public function test_a_payment_id_my_fatoorah_has_never_heard_of_is_unknown(): void
    {
        $this->fakeMyFatoorah([self::MF.'/v3/payments/*' => Http::response(['IsSuccess' => false, 'Message' => 'Invalid data'], 400)]);

        $this->assertSame(PaymentOutcome::UNKNOWN, $this->payments()->confirm('nonexistent1')->result);
    }

    public function test_when_my_fatoorah_cannot_be_asked_nothing_is_decided(): void
    {
        [$order, $payment] = $this->orderWithPayment();
        $this->fakeMyFatoorah([self::MF.'/v3/payments/*' => Http::failedConnection()]);

        try {
            $this->payments()->confirm(self::PAYMENT_ID);
            $this->fail('An outage should surface so the caller can try again.');
        } catch (MyFatoorahUnavailable) {
            $this->assertSame([PaymentState::Pending, OrderStatus::PendingPayment], [$payment->refresh()->state, $order->refresh()->status]);
        }
    }

    // --------------------------------------------------------------- reconcile

    public function test_reconcile_finds_a_payment_whose_shopper_and_webhook_never_arrived(): void
    {
        $this->travelTo('2026-10-05 12:00:00');
        [$order, $payment] = $this->orderWithPayment(['mf_invoice_id' => '6148108', 'created_at' => now()->subMinutes(10)]);
        $this->fakeMyFatoorah([
            self::MF.'/v2/GetPaymentStatus' => Http::response(['IsSuccess' => true, 'Data' => ['InvoiceTransactions' => [
                ['PaymentId' => '07076389491322460111', 'TransactionStatus' => 'Failed'],
                ['PaymentId' => self::PAYMENT_ID, 'TransactionStatus' => 'Succss'],
            ]]]),
            self::MF.'/v3/payments/'.self::PAYMENT_ID => Http::response($this->paymentDetails($payment->reference)),
        ]);

        $counts = $this->payments()->reconcile();

        $this->assertSame(['checked' => 1, 'paid' => 1, 'expired' => 0, 'unreachable' => 0], $counts);
        $this->assertSame(PaymentStatus::Paid, $order->refresh()->payment_status);
    }

    public function test_reconcile_leaves_a_payment_made_moments_ago_to_the_normal_routes(): void
    {
        Http::preventStrayRequests();
        $this->orderWithPayment(['created_at' => now()->subMinute()]);

        $counts = $this->payments()->reconcile();

        $this->assertSame(0, $counts['checked']);
        Http::assertNothingSent();
    }

    public function test_reconcile_expires_an_invoice_that_has_run_out_and_gives_the_orders_stock_back(): void
    {
        $this->travelTo('2026-10-05 12:00:00');
        [$order, $payment] = $this->orderWithPayment([
            'created_at' => now()->subHours(2), 'expires_at' => now()->subMinutes(30), 'mf_invoice_id' => '6148108',
        ]);
        $product = Product::factory()->withStock(2)->create();
        $order->items()->create(['product_id' => $product->id, 'name_ar' => 'أ', 'name_en' => 'A', 'quantity' => 3, 'unit_price_fils' => 1_000, 'total_fils' => 3_000]);
        $this->fakeMyFatoorah([self::MF.'/v2/GetPaymentStatus' => Http::response(['IsSuccess' => true, 'Data' => ['InvoiceTransactions' => []]])]);

        $counts = $this->payments()->reconcile();

        $this->assertSame(['checked' => 1, 'paid' => 0, 'expired' => 1, 'unreachable' => 0], $counts);
        $this->assertSame(PaymentState::Expired, $payment->refresh()->state);
        $this->assertSame(OrderStatus::Cancelled, $order->refresh()->status);
        $this->assertSame(5, $product->fresh()->stock);
    }

    public function test_reconcile_waits_out_the_grace_period_before_expiring_an_invoice(): void
    {
        $this->travelTo('2026-10-05 12:00:00');
        [$order, $payment] = $this->orderWithPayment([
            'created_at' => now()->subMinutes(65), 'expires_at' => now()->subMinutes(5), 'mf_invoice_id' => '6148108',
        ]);
        $this->fakeMyFatoorah([self::MF.'/v2/GetPaymentStatus' => Http::response(['IsSuccess' => true, 'Data' => ['InvoiceTransactions' => []]])]);

        $counts = $this->payments()->reconcile();

        $this->assertSame(0, $counts['expired']);
        $this->assertSame([PaymentState::Pending, OrderStatus::PendingPayment], [$payment->refresh()->state, $order->refresh()->status]);
    }

    public function test_reconcile_catches_a_late_payment_inside_the_grace_period_rather_than_cancelling_over_it(): void
    {
        $this->travelTo('2026-10-05 12:00:00');
        [$order, $payment] = $this->orderWithPayment([
            'created_at' => now()->subMinutes(65), 'expires_at' => now()->subMinutes(5), 'mf_invoice_id' => '6148108',
        ]);
        $this->fakeMyFatoorah([
            self::MF.'/v2/GetPaymentStatus' => Http::response(['IsSuccess' => true, 'Data' => ['InvoiceTransactions' => [['PaymentId' => self::PAYMENT_ID, 'TransactionStatus' => 'Succss']]]]),
            self::MF.'/v3/payments/'.self::PAYMENT_ID => Http::response($this->paymentDetails($payment->reference)),
        ]);

        $this->payments()->reconcile();

        $this->assertSame([PaymentStatus::Paid, OrderStatus::New], [$order->refresh()->payment_status, $order->status]);
    }

    public function test_reconcile_counts_what_it_could_not_reach_and_changes_nothing_about_it(): void
    {
        $this->travelTo('2026-10-05 12:00:00');
        [$order, $payment] = $this->orderWithPayment([
            'created_at' => now()->subHours(2), 'expires_at' => now()->subHour(), 'mf_invoice_id' => '6148108',
        ]);
        $this->fakeMyFatoorah([self::MF.'/v2/GetPaymentStatus' => Http::failedConnection()]);

        $counts = $this->payments()->reconcile();

        $this->assertSame(['checked' => 1, 'paid' => 0, 'expired' => 0, 'unreachable' => 1], $counts);
        // Not being able to ask is no reason to cancel an order that may have been paid.
        $this->assertSame([PaymentState::Pending, OrderStatus::PendingPayment], [$payment->refresh()->state, $order->refresh()->status]);
    }
}
