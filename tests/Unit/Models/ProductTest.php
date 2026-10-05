<?php

namespace Tests\Unit\Models;

use App\Models\Product;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProductTest extends TestCase
{
    public function test_price_is_the_list_price_when_the_product_has_no_discount(): void
    {
        $product = Product::factory()->priced(35_000)->make();

        $this->assertSame(35_000, $product->priceFils());
        $this->assertFalse($product->discountIsActive());
    }

    public function test_fixed_discount_comes_off_the_list_price(): void
    {
        $product = Product::factory()->priced(35_000)->fixedDiscount(16_000)->make();

        $this->assertSame(19_000, $product->priceFils());
        $this->assertTrue($product->discountIsActive());
    }

    public function test_fixed_discount_larger_than_the_price_never_goes_below_zero(): void
    {
        $product = Product::factory()->priced(5_000)->fixedDiscount(9_000)->make();

        $this->assertSame(0, $product->priceFils());
    }

    public function test_percentage_discount_comes_off_the_list_price(): void
    {
        $product = Product::factory()->priced(35_000)->percentDiscount(12.5)->make();

        $this->assertSame(30_625, $product->priceFils());
    }

    public function test_percentage_discount_rounds_half_a_fils_up(): void
    {
        // 50% of 1,001 fils is 500.5, which rounds up to 501 off.
        $product = Product::factory()->priced(1_001)->percentDiscount(50)->make();

        $this->assertSame(500, $product->priceFils());
    }

    public function test_a_discount_of_zero_is_not_active(): void
    {
        $product = Product::factory()->priced(10_000)->fixedDiscount(0)->make();

        $this->assertFalse($product->discountIsActive());
        $this->assertSame(10_000, $product->priceFils());
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function discountWindowMoments(): array
    {
        return [
            'before it opens' => ['2026-09-30 23:59:59', 35_000],
            'at the moment it opens' => ['2026-10-01 00:00:00', 19_000],
            'while it is open' => ['2026-10-10 12:00:00', 19_000],
            'at the moment it closes' => ['2026-10-19 00:00:00', 19_000],
            'after it closes' => ['2026-10-19 00:00:01', 35_000],
        ];
    }

    #[DataProvider('discountWindowMoments')]
    public function test_discount_applies_only_inside_its_window(string $now, int $expectedFils): void
    {
        $this->travelTo($now);
        $product = Product::factory()
            ->priced(35_000)
            ->fixedDiscount(16_000)
            ->discountWindow('2026-10-01 00:00:00', '2026-10-19 00:00:00')
            ->make();

        $this->assertSame($expectedFils, $product->priceFils());
    }

    public function test_a_discount_with_no_dates_is_always_open(): void
    {
        $this->travelTo('2031-01-01 00:00:00');
        $product = Product::factory()->priced(35_000)->fixedDiscount(16_000)->make();

        $this->assertSame(19_000, $product->priceFils());
    }

    public function test_untracked_stock_is_always_available(): void
    {
        $product = Product::factory()->make(['track_stock' => false, 'stock' => 0]);

        $this->assertTrue($product->inStock());
        $this->assertFalse($product->isLowStock());
    }

    public function test_tracked_stock_runs_out_at_zero(): void
    {
        $product = Product::factory()->withStock(0)->make();

        $this->assertFalse($product->inStock());
    }

    public function test_tracked_stock_is_low_at_or_below_its_threshold(): void
    {
        $atThreshold = Product::factory()->withStock(2, lowAt: 2)->make();
        $aboveThreshold = Product::factory()->withStock(3, lowAt: 2)->make();

        $this->assertTrue($atThreshold->isLowStock());
        $this->assertFalse($aboveThreshold->isLowStock());
    }
}
