<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $subtotal = fake()->numberBetween(10, 80) * 1000;

        return [
            'customer_id' => null,
            'customer_name' => fake()->name(),
            'customer_email' => fake()->safeEmail(),
            'customer_phone' => '+965'.fake()->numberBetween(50_000_000, 99_999_999),
            'status' => OrderStatus::New,
            'payment_method' => Order::PAYMENT_COD,
            'payment_status' => PaymentStatus::Unpaid,
            'currency' => 'KWD',
            'subtotal_fils' => $subtotal,
            'discount_fils' => 0,
            'delivery_fee_fils' => 2_000,
            'addons_total_fils' => 0,
            'cod_fee_fils' => 0,
            'total_fils' => $subtotal + 2_000,
            'voucher_code' => null,
            'city_name_ar' => 'حولي',
            'city_name_en' => 'Hawalli',
            'area_name_ar' => 'السالمية',
            'area_name_en' => 'Salmiya',
            'block' => '4',
            'street' => 'Street 12',
            'building' => '7',
            'locale' => 'ar',
            'placed_at' => now(),
        ];
    }

    public function forCustomer(Customer $customer): static
    {
        return $this->state([
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'customer_email' => $customer->email,
            'customer_phone' => $customer->phone ?? '+96550000000',
        ]);
    }

    public function totalling(int $subtotalFils, int $deliveryFils = 2_000, int $discountFils = 0): static
    {
        return $this->state([
            'subtotal_fils' => $subtotalFils,
            'delivery_fee_fils' => $deliveryFils,
            'discount_fils' => $discountFils,
            'total_fils' => $subtotalFils - $discountFils + $deliveryFils,
        ]);
    }

    public function online(): static
    {
        return $this->state(['payment_method' => Order::PAYMENT_ONLINE]);
    }

    public function pendingPayment(): static
    {
        return $this->online()->state([
            'status' => OrderStatus::PendingPayment,
            'payment_status' => PaymentStatus::Unpaid,
        ]);
    }

    public function paid(): static
    {
        return $this->online()->state([
            'status' => OrderStatus::New,
            'payment_status' => PaymentStatus::Paid,
            'paid_at' => now(),
        ]);
    }

    public function withStatus(OrderStatus $status): static
    {
        return $this->state(['status' => $status]);
    }

    public function usingVoucher(string $code): static
    {
        return $this->state(['voucher_code' => $code]);
    }
}
