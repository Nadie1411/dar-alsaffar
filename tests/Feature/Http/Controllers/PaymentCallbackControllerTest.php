<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\FakesMyFatoorah;
use Tests\Concerns\UsesLocalStore;
use Tests\TestCase;

class PaymentCallbackControllerTest extends TestCase
{
    use FakesMyFatoorah, LazilyRefreshDatabase, UsesLocalStore;

    private const PAYMENT_ID = '07076389491322460173';

    private Order $order;

    private Payment $payment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureMyFatoorah();
        $this->order = Order::factory()->pendingPayment()->create(['total_fils' => 40_000]);
        $this->payment = Payment::factory()->create(['order_id' => $this->order->id, 'reference' => 'REF-1', 'amount_fils' => 40_000]);
    }

    /**
     * @param  array<string,mixed>  $changes
     */
    private function mfAnswers(array $changes = []): void
    {
        $this->fakeMyFatoorah([self::MF.'/v3/payments/'.self::PAYMENT_ID => Http::response($this->paymentDetails('REF-1', $changes))]);
    }

    private function basket(): array
    {
        return ['cart.items' => ['line' => ['productId' => '1', 'quantity' => 1, 'varientId' => null, 'options' => []]], 'payment.order' => $this->order->number];
    }

    public function test_a_paid_return_settles_the_order_shows_the_thank_you_page_and_empties_the_basket(): void
    {
        $this->mfAnswers();

        $response = $this->withSession($this->basket())->get('/en-KW/payment/return?paymentId='.self::PAYMENT_ID);

        $response->assertRedirect('/en-KW/checkout/thanks/'.$this->order->number)->assertSessionMissing('cart.items')->assertSessionMissing('payment.order');
        $this->assertSame([PaymentStatus::Paid, OrderStatus::New], [$this->order->refresh()->payment_status, $this->order->status]);
    }

    public function test_the_basket_is_left_alone_when_it_is_another_shoppers_payment_that_came_back(): void
    {
        $this->mfAnswers();

        $response = $this->withSession(['cart.items' => ['line' => ['productId' => '9', 'quantity' => 1]], 'payment.order' => 'DS-SOMEONE-ELSE'])
            ->get('/ar-KW/payment/return?paymentId='.self::PAYMENT_ID);

        $response->assertRedirect('/ar-KW/checkout/thanks/'.$this->order->number)->assertSessionHas('cart.items');
    }

    public function test_arriving_proves_nothing_the_payment_is_checked_with_my_fatoorah_first(): void
    {
        // Someone opens the return address by hand; MyFatoorah says it is still unpaid.
        $this->mfAnswers(['Invoice.Status' => 'PENDING', 'Transaction.Status' => 'INPROGRESS']);

        $response = $this->withSession($this->basket())->get('/en-KW/payment/return?paymentId='.self::PAYMENT_ID);

        $response->assertRedirect('/en-KW/checkout/pending/'.$this->order->number)->assertSessionHas('cart.items');
        $this->assertSame([PaymentStatus::Unpaid, OrderStatus::PendingPayment], [$this->order->refresh()->payment_status, $this->order->status]);
    }

    public function test_a_failed_payment_leads_to_the_failed_page_with_the_way_back_and_keeps_the_basket(): void
    {
        $this->mfAnswers(['Invoice.Status' => 'PENDING', 'Transaction.Status' => 'FAILED']);

        $response = $this->withSession($this->basket())->get('/en-KW/payment/return?paymentId='.self::PAYMENT_ID);

        $response->assertRedirect('/en-KW/checkout/failed?order='.$this->order->number)->assertSessionHas('cart.items');
        $this->withSession($this->basket())->get('/en-KW/checkout/failed?order='.$this->order->number)
            ->assertOk()->assertSee('Try the payment again')->assertSee('/en-KW/checkout/pay/'.$this->order->number, false);
    }

    public function test_a_cancelled_invoice_leads_to_the_failed_page(): void
    {
        $this->mfAnswers(['Invoice.Status' => 'CANCELED', 'Transaction.Status' => 'CANCELED']);

        $this->get('/en-KW/payment/return?paymentId='.self::PAYMENT_ID)
            ->assertRedirect('/en-KW/checkout/failed?order='.$this->order->number);
    }

    public function test_a_payment_that_is_not_ours_leads_to_the_failed_page_without_an_order_number(): void
    {
        $this->fakeMyFatoorah([self::MF.'/v3/payments/'.self::PAYMENT_ID => Http::response($this->paymentDetails('someone-elses'))]);

        $this->get('/en-KW/payment/return?paymentId='.self::PAYMENT_ID)->assertRedirect('/en-KW/checkout/failed');
    }

    public function test_when_my_fatoorah_cannot_be_asked_the_shopper_is_told_it_is_being_confirmed_and_nothing_is_decided(): void
    {
        $this->fakeMyFatoorah([self::MF.'/v3/payments/*' => Http::failedConnection()]);

        $response = $this->withSession($this->basket())->get('/en-KW/payment/return?paymentId='.self::PAYMENT_ID);

        $response->assertRedirect('/en-KW/checkout/pending/'.$this->order->number)->assertSessionHas('cart.items');
        $this->assertSame(PaymentStatus::Unpaid, $this->order->refresh()->payment_status);
        $this->get('/en-KW/checkout/pending/'.$this->order->number)->assertOk()->assertSee('Confirming your payment')->assertSee($this->order->number);
    }

    public function test_a_payment_for_the_wrong_amount_is_not_celebrated(): void
    {
        $this->mfAnswers(['Amount.ValueInDisplayCurrency' => '1', 'Amount.ValueInBaseCurrency' => '1']);

        $this->get('/en-KW/payment/return?paymentId='.self::PAYMENT_ID)->assertRedirect('/en-KW/checkout/pending/'.$this->order->number);
    }

    public function test_money_that_arrived_for_a_cancelled_order_is_not_called_a_success(): void
    {
        $this->order->update(['status' => OrderStatus::Cancelled]);
        $this->mfAnswers();

        $this->get('/en-KW/payment/return?paymentId='.self::PAYMENT_ID)->assertRedirect('/en-KW/checkout/pending/'.$this->order->number);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function badPaymentIds(): array
    {
        return [
            'none at all' => [''],
            'too short' => ['abc'],
            'a path traversal' => ['../../v3/refunds'],
            'a query injection' => ['123456?x=1'],
            'spaces' => ['0707 6389 4913'],
        ];
    }

    #[DataProvider('badPaymentIds')]
    public function test_something_that_is_not_shaped_like_a_payment_id_never_reaches_my_fatoorah(string $paymentId): void
    {
        Http::preventStrayRequests();

        $response = $this->get('/en-KW/payment/return?paymentId='.rawurlencode($paymentId));

        $response->assertRedirect('/en-KW/checkout/failed');
        Http::assertNothingSent();
    }
}
