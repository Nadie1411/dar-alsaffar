<?php

namespace Tests\Feature\Http\Controllers\Webhooks;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Events\OrderPlaced;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Store\Payments\WebhookSignature;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesMyFatoorah;
use Tests\TestCase;

class MyFatoorahWebhookControllerTest extends TestCase
{
    use FakesMyFatoorah, LazilyRefreshDatabase;

    private const PAYMENT_ID = '07076389491322460173';

    private const SECRET = 'test-secret-key';

    private Order $order;

    private Payment $payment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureMyFatoorah(self::SECRET);
        $this->order = Order::factory()->pendingPayment()->create(['total_fils' => 40_000]);
        $this->payment = Payment::factory()->create(['order_id' => $this->order->id, 'reference' => 'REF-1', 'amount_fils' => 40_000]);
    }

    /**
     * Send a webhook the way MyFatoorah does: JSON, with a signature header.
     *
     * @param  array<string,mixed>  $event
     */
    private function deliver(array $event, ?string $signature = null, bool $signed = true)
    {
        $headers = $signed ? ['MyFatoorah-Signature' => $signature ?? (new WebhookSignature)->sign($event, self::SECRET)] : [];

        return $this->postJson('/api/webhooks/myfatoorah', $event, $headers);
    }

    /**
     * @param  array<string,mixed>  $changes
     */
    private function mfAnswers(array $changes = []): void
    {
        $this->fakeMyFatoorah([self::MF.'/v3/payments/'.self::PAYMENT_ID => Http::response($this->paymentDetails('REF-1', $changes))]);
    }

    public function test_a_signed_paid_event_settles_the_order_after_my_fatoorah_itself_confirms_it(): void
    {
        Event::fake([OrderPlaced::class]);
        $this->mfAnswers();

        $response = $this->deliver($this->webhookEvent('REF-1'));

        $response->assertOk()->assertJson(['ok' => true]);
        $this->assertSame([PaymentStatus::Paid, OrderStatus::New], [$this->order->refresh()->payment_status, $this->order->status]);
        Http::assertSent(fn (Request $request) => $request->method() === 'GET' && str_ends_with($request->url(), '/v3/payments/'.self::PAYMENT_ID));
        Event::assertDispatchedTimes(OrderPlaced::class, 1);
    }

    public function test_a_webhook_with_a_wrong_or_missing_signature_is_refused_and_acted_on_not_at_all(): void
    {
        Http::preventStrayRequests();
        $event = $this->webhookEvent('REF-1');

        $this->deliver($event, 'bm90LXRoZS1yaWdodC1zaWduYXR1cmU=')->assertUnauthorized();
        $this->deliver($event, signed: false)->assertUnauthorized();

        Http::assertNothingSent();
        $this->assertSame(PaymentStatus::Unpaid, $this->order->refresh()->payment_status);
    }

    public function test_an_event_whose_signed_fields_were_changed_after_signing_is_refused(): void
    {
        Http::preventStrayRequests();
        $event = $this->webhookEvent('REF-1', ['Data.Invoice.Status' => 'PENDING', 'Data.Transaction.Status' => 'FAILED']);
        $signatureForTheRealEvent = (new WebhookSignature)->sign($this->webhookEvent('REF-1'), self::SECRET);

        $this->deliver($event, $signatureForTheRealEvent)->assertUnauthorized();
    }

    public function test_the_webhook_is_only_a_hint_what_it_says_about_the_payment_is_never_taken_as_the_verdict(): void
    {
        // A perfectly signed event claims PAID, but MyFatoorah's own record says the attempt failed.
        $this->mfAnswers(['Invoice.Status' => 'PENDING', 'Transaction.Status' => 'FAILED']);

        $this->deliver($this->webhookEvent('REF-1'))->assertOk();

        $this->assertSame([PaymentStatus::Unpaid, OrderStatus::PendingPayment], [$this->order->refresh()->payment_status, $this->order->status]);
    }

    public function test_without_a_configured_secret_an_unsigned_event_is_still_only_a_hint_and_gets_checked(): void
    {
        $this->configureMyFatoorah(null);
        $this->mfAnswers(['Invoice.Status' => 'PENDING', 'Transaction.Status' => 'INPROGRESS']);

        $this->deliver($this->webhookEvent('REF-1'), signed: false)->assertOk();

        $this->assertSame(PaymentStatus::Unpaid, $this->order->refresh()->payment_status);
        Http::assertSentCount(1);
    }

    public function test_events_other_than_a_payment_status_change_are_acknowledged_and_ignored(): void
    {
        Http::preventStrayRequests();
        $event = $this->webhookEvent('REF-1', ['Event.Code' => 2, 'Event.Name' => 'REFUND_STATUS_CHANGED']);

        $this->deliver($event)->assertOk();

        Http::assertNothingSent();
    }

    public function test_an_event_with_no_payment_id_is_acknowledged_without_looking_anything_up(): void
    {
        Http::preventStrayRequests();

        $this->deliver($this->webhookEvent('REF-1', ['Data.Transaction.PaymentId' => '']))->assertOk();

        Http::assertNothingSent();
    }

    public function test_unreadable_bodies_are_rejected(): void
    {
        $this->call('POST', '/api/webhooks/myfatoorah', [], [], [], ['CONTENT_TYPE' => 'application/json'], 'not json at all')->assertStatus(400);
        $this->call('POST', '/api/webhooks/myfatoorah', [], [], [], ['CONTENT_TYPE' => 'application/json'], '"just a string"')->assertStatus(400);
    }

    public function test_when_my_fatoorah_cannot_be_asked_the_webhook_answers_unavailable_so_it_is_sent_again(): void
    {
        $this->fakeMyFatoorah([self::MF.'/v3/payments/*' => Http::failedConnection()]);

        $this->deliver($this->webhookEvent('REF-1'))->assertStatus(503);

        $this->assertSame(PaymentStatus::Unpaid, $this->order->refresh()->payment_status);
    }

    public function test_when_the_key_may_not_read_payments_it_is_an_error_not_a_retry_loop(): void
    {
        $this->fakeMyFatoorah([self::MF.'/v3/payments/*' => Http::response(['IsSuccess' => false, 'Message' => 'The token does not have the required permissions!'], 401)]);

        $this->deliver($this->webhookEvent('REF-1'))->assertStatus(500);
    }

    public function test_a_payment_that_belongs_to_another_system_on_the_same_account_is_acknowledged_and_left_alone(): void
    {
        $this->fakeMyFatoorah([self::MF.'/v3/payments/'.self::PAYMENT_ID => Http::response($this->paymentDetails('a-different-systems-reference'))]);

        $this->deliver($this->webhookEvent('a-different-systems-reference'))->assertOk();

        $this->assertSame(PaymentStatus::Unpaid, $this->order->refresh()->payment_status);
    }

    public function test_the_same_event_delivered_twice_settles_the_order_once(): void
    {
        Event::fake([OrderPlaced::class]);
        $this->mfAnswers();
        $event = $this->webhookEvent('REF-1');

        $this->deliver($event)->assertOk();
        $this->deliver($event)->assertOk();

        $this->assertSame(1, $this->order->statusChanges()->count());
        Event::assertDispatchedTimes(OrderPlaced::class, 1);
    }

    public function test_the_webhook_needs_neither_a_session_nor_a_csrf_token(): void
    {
        $this->mfAnswers();

        $response = $this->deliver($this->webhookEvent('REF-1'));

        $response->assertOk()->assertCookieMissing(config('session.cookie'));
    }

    public function test_the_address_cannot_be_used_to_flood_the_server(): void
    {
        Http::preventStrayRequests();
        $event = $this->webhookEvent('REF-1', ['Event.Code' => 2]);

        foreach (range(1, 120) as $call) {
            $this->deliver($event)->assertOk();
        }

        $this->deliver($event)->assertStatus(429);
    }
}
