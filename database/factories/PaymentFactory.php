<?php

namespace Database\Factories;

use App\Enums\PaymentState;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory()->pendingPayment(),
            'provider' => 'myfatoorah',
            'reference' => (string) Str::ulid(),
            'state' => PaymentState::Pending,
            'amount_fils' => 40_000,
            'currency' => 'KWD',
            'method' => null,
            'mf_invoice_id' => (string) fake()->unique()->numberBetween(6_000_000, 6_999_999),
            'mf_payment_url' => 'https://demo.myfatoorah.com/KWT/ia/'.fake()->unique()->sha1(),
            'expires_at' => now()->addHour(),
        ];
    }

    public function paid(string $paymentId = '07076409988323998875'): static
    {
        return $this->state([
            'state' => PaymentState::Paid,
            'mf_payment_id' => $paymentId,
            'paid_at' => now(),
            'verified_at' => now(),
        ]);
    }

    public function expiredAt(string $moment): static
    {
        return $this->state(['expires_at' => $moment]);
    }

    public function withState(PaymentState $state): static
    {
        return $this->state(['state' => $state]);
    }
}
