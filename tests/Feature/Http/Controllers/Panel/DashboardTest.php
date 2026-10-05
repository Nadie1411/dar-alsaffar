<?php

namespace Tests\Feature\Http\Controllers\Panel;

use App\Enums\OrderStatus;
use App\Models\ContactMessage;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\SignsInStaff;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use LazilyRefreshDatabase, SignsInStaff;

    protected function setUp(): void
    {
        parent::setUp();

        // 13:00 on the 6th in Kuwait.
        $this->travelTo(Carbon::parse('2026-10-06 10:00:00', 'UTC'));
    }

    public function test_an_empty_shop_greets_the_user_and_says_so_instead_of_showing_zeros_as_a_chart(): void
    {
        $this->signInAs('owner', ['name' => 'Nora']);

        $this->get(route('panel.dashboard'))
            ->assertOk()
            ->assertSee('Welcome back, Nora')
            ->assertSee('No orders yet')
            ->assertSee('All clear')
            ->assertSee('No sales in this period.');
    }

    public function test_todays_sales_count_orders_that_were_placed_and_not_cancelled_or_unpaid(): void
    {
        $this->signInAs('manager');
        Order::factory()->totalling(10_000, 2_000)->create(['placed_at' => now()]);
        Order::factory()->totalling(5_000, 2_000)->create(['placed_at' => now(), 'status' => OrderStatus::Delivered]);
        Order::factory()->totalling(50_000)->create(['placed_at' => now(), 'status' => OrderStatus::Cancelled]);
        Order::factory()->totalling(70_000)->pendingPayment()->create(['placed_at' => now()]);

        $response = $this->get(route('panel.dashboard'))->assertOk();

        $this->assertSame(2, $response->viewData('today')['orders']);
        $this->assertSame(19_000, $response->viewData('today')['revenue']);
        $response->assertSee('19.000');
    }

    public function test_today_means_today_in_kuwait(): void
    {
        $this->signInAs('manager');

        // 21:30 UTC yesterday is half past midnight today in Kuwait; 20:30 UTC is still yesterday there.
        Order::factory()->totalling(10_000, 0)->create(['placed_at' => '2026-10-05 21:30:00']);
        Order::factory()->totalling(30_000, 0)->create(['placed_at' => '2026-10-05 20:30:00']);

        $this->assertSame(10_000, $this->get(route('panel.dashboard'))->viewData('today')['revenue']);
    }

    public function test_the_thirty_day_figures_include_the_whole_window_and_stop_at_its_edge(): void
    {
        $this->signInAs('manager');
        Order::factory()->totalling(10_000, 0)->create(['placed_at' => '2026-10-06 08:00:00']);
        Order::factory()->totalling(20_000, 0)->create(['placed_at' => '2026-09-08 09:00:00']); // day 29 back: inside
        Order::factory()->totalling(40_000, 0)->create(['placed_at' => '2026-09-06 09:00:00']); // outside

        $month = $this->get(route('panel.dashboard'))->viewData('month');

        $this->assertSame(2, $month['orders']);
        $this->assertSame(30_000, $month['revenue']);
        $this->assertSame(15_000, $month['average']);
    }

    public function test_orders_waiting_for_someone_are_flagged_and_link_to_the_filtered_list(): void
    {
        $this->signInAs('staff');
        Order::factory()->count(3)->create();
        Order::factory()->create(['status' => OrderStatus::Preparing]);

        $response = $this->get(route('panel.dashboard'))->assertOk();

        $this->assertSame(3, $response->viewData('awaiting'));
        $response->assertSee('New orders waiting')->assertSee(route('panel.orders.index', ['status' => 'new']), false);
    }

    public function test_the_attention_list_shows_only_what_the_signed_in_role_can_act_on(): void
    {
        ContactMessage::factory()->count(2)->create();
        Payment::factory()->create(['anomaly' => Payment::ANOMALY_AMOUNT_MISMATCH]);
        Product::factory()->withStock(0)->create();
        Product::factory()->withStock(2, 3)->create();

        $this->signInAs('manager');
        $manager = $this->get(route('panel.dashboard'))
            ->assertSee('Unread messages')
            ->assertSee('Payments to review')
            ->assertSee('Products out of stock')
            ->assertSee('Products running low');
        $this->assertCount(4, $manager->viewData('attention'));

        $this->signInAs('staff');
        $staff = $this->get(route('panel.dashboard'))->assertSee('Unread messages');
        $this->assertSame(['unreadMessages'], array_column($staff->viewData('attention'), 'key'));
        $staff->assertDontSee('Payments to review')->assertDontSee('Products out of stock');
    }

    public function test_a_payment_somebody_has_already_looked_at_no_longer_asks_for_attention(): void
    {
        $this->signInAs('manager');
        Payment::factory()->create(['anomaly' => Payment::ANOMALY_DUPLICATE, 'anomaly_reviewed_at' => now()]);

        $this->get(route('panel.dashboard'))->assertDontSee('Payments to review');
    }

    public function test_products_that_are_switched_off_do_not_count_as_out_of_stock(): void
    {
        $this->signInAs('manager');
        Product::factory()->withStock(0)->inactive()->create();
        Product::factory()->create(); // stock not tracked

        $this->get(route('panel.dashboard'))->assertDontSee('Products out of stock');
    }

    public function test_the_best_sellers_rank_by_units_over_the_last_thirty_days(): void
    {
        $this->signInAs('manager');
        $popular = Product::factory()->create(['name_en' => 'Popular One']);
        $quiet = Product::factory()->create(['name_en' => 'Quiet One']);

        $recent = Order::factory()->create(['placed_at' => now()]);
        OrderItem::factory()->for($recent)->create(['product_id' => $popular->id, 'name_en' => 'Popular One', 'quantity' => 5, 'total_fils' => 50_000]);
        OrderItem::factory()->for($recent)->create(['product_id' => $quiet->id, 'name_en' => 'Quiet One', 'quantity' => 1, 'total_fils' => 9_000]);

        $old = Order::factory()->create(['placed_at' => now()->subDays(60)]);
        OrderItem::factory()->for($old)->create(['product_id' => $quiet->id, 'name_en' => 'Quiet One', 'quantity' => 50, 'total_fils' => 500_000]);

        $response = $this->get(route('panel.dashboard'))->assertSeeInOrder(['Popular One', 'Quiet One']);

        $rows = $response->viewData('topProducts');
        $this->assertSame([5, 1], array_column($rows, 'units'));
    }

    public function test_the_latest_orders_are_listed_newest_first_with_a_link_to_each(): void
    {
        $this->signInAs('staff');
        $older = Order::factory()->create(['customer_name' => 'Older Order']);
        $newer = Order::factory()->create(['customer_name' => 'Newer Order']);

        $this->get(route('panel.dashboard'))
            ->assertSeeInOrder(['Newer Order', 'Older Order'])
            ->assertSee(route('panel.orders.show', $newer), false)
            ->assertSee(route('panel.orders.show', $older), false);
    }

    public function test_money_figures_stay_hidden_from_a_member_of_staff_in_the_page_data_too(): void
    {
        $this->signInAs('staff');
        Order::factory()->totalling(10_000, 0)->create(['placed_at' => now()]);

        $response = $this->get(route('panel.dashboard'));

        $this->assertNull($response->viewData('month'));
        $this->assertSame([], $response->viewData('series'));
        $this->assertSame([], $response->viewData('topProducts'));
        $response->assertDontSee('Sales today')->assertDontSee('Sales, last 30 days');
    }

    public function test_a_tile_that_links_somewhere_has_a_properly_quoted_address(): void
    {
        $this->signInAs('staff');
        Order::factory()->create();

        $this->get(route('panel.dashboard'))
            ->assertSee('<a href="'.route('panel.orders.index', ['status' => 'new']).'" class="card stat stat--alert">', false);
    }
}
