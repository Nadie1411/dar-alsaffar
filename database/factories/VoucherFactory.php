<?php

namespace Database\Factories;

use App\Models\Voucher;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Voucher>
 */
class VoucherFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('????##')),
            'name_ar' => 'قسيمة خصم',
            'name_en' => 'Discount voucher',
            'type' => Voucher::TYPE_FIXED,
            'percent' => 0,
            'amount_fils' => 2_000,
            'max_discount_fils' => null,
            'min_subtotal_fils' => 0,
            'starts_at' => null,
            'ends_at' => null,
            'usage_limit' => null,
            'usage_limit_per_customer' => null,
            'is_active' => true,
            'is_public' => false,
        ];
    }

    public function percentage(float $percent, ?int $maxDiscountFils = null): static
    {
        return $this->state([
            'type' => Voucher::TYPE_PERCENTAGE,
            'percent' => $percent,
            'amount_fils' => 0,
            'max_discount_fils' => $maxDiscountFils,
        ]);
    }

    public function fixed(int $fils): static
    {
        return $this->state(['type' => Voucher::TYPE_FIXED, 'amount_fils' => $fils, 'percent' => 0]);
    }

    public function freeShipping(): static
    {
        return $this->state(['type' => Voucher::TYPE_FREE_SHIPPING, 'amount_fils' => 0, 'percent' => 0]);
    }

    public function minimumSubtotal(int $fils): static
    {
        return $this->state(['min_subtotal_fils' => $fils]);
    }

    public function between(?string $starts, ?string $ends): static
    {
        return $this->state(['starts_at' => $starts, 'ends_at' => $ends]);
    }

    public function limitedTo(?int $total, ?int $perCustomer = null): static
    {
        return $this->state(['usage_limit' => $total, 'usage_limit_per_customer' => $perCustomer]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    public function public(): static
    {
        return $this->state(['is_public' => true]);
    }
}
