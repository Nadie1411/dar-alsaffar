<?php

namespace Tests\Feature\Console\Commands;

use App\Enums\PaymentState;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesMyFatoorah;
use Tests\TestCase;

class ReconcilePaymentsTest extends TestCase
{
    use FakesMyFatoorah, LazilyRefreshDatabase;

    public function test_it_does_nothing_and_says_so_while_online_payment_is_not_configured(): void
    {
        Http::preventStrayRequests();

        $this->artisan('payments:reconcile')
            ->expectsOutputToContain('Online payment is not configured')
            ->assertExitCode(0);
    }

    public function test_it_reports_what_it_checked_found_and_released(): void
    {
        $this->configureMyFatoorah();
        $this->travelTo('2026-10-05 12:00:00');
        $order = Order::factory()->pendingPayment()->create();
        Payment::factory()->create([
            'order_id' => $order->id, 'created_at' => now()->subHours(3), 'expires_at' => now()->subHours(2),
            'mf_invoice_id' => '6148108',
        ]);
        $this->fakeMyFatoorah([self::MF.'/v2/GetPaymentStatus' => Http::response(['IsSuccess' => true, 'Data' => ['InvoiceTransactions' => []]])]);

        $this->artisan('payments:reconcile')
            ->expectsOutputToContain('Payments checked')
            ->expectsOutputToContain('Expired, order released')
            ->assertExitCode(0);

        $this->assertSame(PaymentState::Expired, Payment::query()->firstOrFail()->state);
    }

    public function test_it_is_scheduled_every_five_minutes(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('payments:reconcile')->assertExitCode(0);
    }
}
