<?php

namespace Tests\Feature\Services\Store;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Store\OrderLifecycle;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class OrderLifecycleTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function lifecycle(): OrderLifecycle
    {
        return $this->app->make(OrderLifecycle::class);
    }

    public function test_moving_an_order_changes_its_status_and_records_who_did_it_and_why(): void
    {
        $order = Order::factory()->create();
        $staff = User::factory()->create();

        $this->lifecycle()->moveTo($order, OrderStatus::Preparing, $staff, 'Wrapping the gift box');

        $this->assertSame(OrderStatus::Preparing, $order->fresh()->status);
        $change = $order->statusChanges()->firstOrFail();
        $this->assertSame([OrderStatus::New, OrderStatus::Preparing, $staff->id, 'Wrapping the gift box'], [
            $change->from_status, $change->to_status, $change->user_id, $change->note,
        ]);
    }

    public function test_moving_to_the_status_it_already_has_does_nothing(): void
    {
        $order = Order::factory()->create();

        $this->lifecycle()->moveTo($order, OrderStatus::New);

        $this->assertDatabaseCount('order_status_changes', 0);
    }

    public function test_delivering_an_order_stamps_the_time(): void
    {
        $this->travelTo('2026-10-05 15:30:00');
        $order = Order::factory()->withStatus(OrderStatus::OutForDelivery)->create();

        $this->lifecycle()->moveTo($order, OrderStatus::Delivered);

        $this->assertSame('2026-10-05 15:30:00', $order->fresh()->delivered_at->toDateTimeString());
        $this->assertNull($order->fresh()->cancelled_at);
    }

    public function test_cancelling_an_order_stamps_the_time_and_puts_its_stock_back(): void
    {
        $this->travelTo('2026-10-05 15:30:00');
        $tracked = Product::factory()->withStock(7)->create();
        $untracked = Product::factory()->create(['stock' => 0]);
        $order = Order::factory()->create();
        $order->items()->create(['product_id' => $tracked->id, 'name_ar' => 'أ', 'name_en' => 'A', 'quantity' => 3, 'unit_price_fils' => 1_000, 'total_fils' => 3_000]);
        $order->items()->create(['product_id' => $untracked->id, 'name_ar' => 'ب', 'name_en' => 'B', 'quantity' => 2, 'unit_price_fils' => 1_000, 'total_fils' => 2_000]);
        $order->items()->create(['product_id' => null, 'name_ar' => 'ج', 'name_en' => 'C', 'quantity' => 1, 'unit_price_fils' => 1_000, 'total_fils' => 1_000]);

        $this->lifecycle()->moveTo($order, OrderStatus::Cancelled);

        $this->assertSame('2026-10-05 15:30:00', $order->fresh()->cancelled_at->toDateTimeString());
        $this->assertSame(10, $tracked->fresh()->stock);
        $this->assertSame(0, $untracked->fresh()->stock);
    }

    public function test_cancelling_twice_gives_the_stock_back_only_once(): void
    {
        $product = Product::factory()->withStock(7)->create();
        $order = Order::factory()->create();
        $order->items()->create(['product_id' => $product->id, 'name_ar' => 'أ', 'name_en' => 'A', 'quantity' => 3, 'unit_price_fils' => 1_000, 'total_fils' => 3_000]);

        $this->lifecycle()->moveTo($order, OrderStatus::Cancelled);
        $this->lifecycle()->moveTo($order, OrderStatus::Cancelled);

        $this->assertSame(10, $product->fresh()->stock);
        $this->assertSame(1, $order->statusChanges()->count());
    }

    public function test_the_statuses_a_person_can_set_by_hand_leave_out_pending_payment(): void
    {
        $manual = OrderStatus::manual();

        $this->assertNotContains(OrderStatus::PendingPayment, $manual);
        $this->assertContains(OrderStatus::Cancelled, $manual);
    }

    public function test_each_status_has_a_label_in_both_languages_and_a_tone(): void
    {
        foreach (OrderStatus::cases() as $status) {
            $this->assertNotSame('storefront.order.status.'.$status->value, $status->label('en'), $status->value);
            $this->assertNotSame('storefront.order.status.'.$status->value, $status->label('ar'), $status->value);
        }

        $this->assertSame('cancelled', OrderStatus::Cancelled->tone());
        $this->assertSame('done', OrderStatus::Delivered->tone());
        $this->assertSame('pending', OrderStatus::Preparing->tone());
        $this->assertFalse(OrderStatus::Delivered->isOpen());
        $this->assertTrue(OrderStatus::OutForDelivery->isOpen());
    }
}
