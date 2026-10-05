<?php

namespace Tests\Feature\Http\Controllers\Panel;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\SignsInStaff;
use Tests\TestCase;

class ReportsPanelTest extends TestCase
{
    use LazilyRefreshDatabase, SignsInStaff;

    protected function setUp(): void
    {
        parent::setUp();

        // 13:00 on Tuesday the 6th of October in Kuwait.
        $this->travelTo(Carbon::parse('2026-10-06 10:00:00', 'UTC'));
    }

    /**
     * @param  array<string,mixed>  $attributes
     */
    private function sale(int $subtotal, string $placedAt, array $attributes = []): Order
    {
        return Order::factory()->totalling($subtotal, 2_000)->create(['placed_at' => $placedAt] + $attributes);
    }

    public function test_only_roles_that_may_see_reports_can_open_them(): void
    {
        $this->signInAs('staff');
        $this->get(route('panel.reports.index'))->assertForbidden();
        $this->get(route('panel.reports.export'))->assertForbidden();

        $this->signInAs('manager');
        $this->get(route('panel.reports.index'))->assertOk();
    }

    public function test_the_default_period_is_the_last_thirty_days(): void
    {
        $this->signInAs('manager');

        $response = $this->get(route('panel.reports.index'))->assertOk();

        $this->assertSame('30d', $response->viewData('preset'));
        $this->assertSame('2026-09-07', $response->viewData('from')->format('Y-m-d'));
        $this->assertSame('2026-10-06', $response->viewData('to')->format('Y-m-d'));
        $this->assertCount(30, $response->viewData('series'));
    }

    public function test_the_summary_adds_up_what_was_sold_in_the_period_and_nothing_outside_it(): void
    {
        $this->signInAs('manager');
        $this->sale(10_000, '2026-10-06 08:00:00', ['discount_fils' => 1_000, 'total_fils' => 11_000]);
        $this->sale(20_000, '2026-10-01 08:00:00');
        $this->sale(90_000, '2026-08-01 08:00:00');
        $this->sale(40_000, '2026-10-02 08:00:00', ['status' => OrderStatus::Cancelled]);

        $summary = $this->get(route('panel.reports.index'))->viewData('summary');

        $this->assertSame(2, $summary['orders']);
        $this->assertSame(11_000 + 22_000, $summary['revenue']);
        $this->assertSame(1_000, $summary['discounts']);
        $this->assertSame(4_000, $summary['delivery']);
        $this->assertSame(16_500, $summary['average']);
    }

    public function test_the_average_order_rounds_half_up_to_a_whole_fils(): void
    {
        $this->signInAs('manager');
        $this->sale(1, '2026-10-06 08:00:00', ['total_fils' => 1]);
        $this->sale(1, '2026-10-06 09:00:00', ['total_fils' => 2]);

        $this->assertSame(2, $this->get(route('panel.reports.index'))->viewData('summary')['average']); // 1.5 → 2
    }

    public function test_the_daily_series_has_a_row_for_every_day_including_the_quiet_ones_and_buckets_by_kuwait_midnight(): void
    {
        $this->signInAs('manager');
        $this->sale(10_000, '2026-10-05 20:59:59'); // 23:59:59 on the 5th in Kuwait
        $this->sale(30_000, '2026-10-05 21:00:00'); // 00:00:00 on the 6th

        $series = collect($this->get(route('panel.reports.index', ['range' => '7d']))->viewData('series'))->keyBy('date');

        $this->assertCount(7, $series);
        $this->assertSame(12_000, $series['2026-10-05']['revenue']);
        $this->assertSame(1, $series['2026-10-05']['orders']);
        $this->assertSame(32_000, $series['2026-10-06']['revenue']);
        $this->assertSame(0, $series['2026-10-02']['orders']);
    }

    public function test_the_presets_choose_the_period(): void
    {
        $this->signInAs('manager');

        $expected = [
            'today' => ['2026-10-06', '2026-10-06'],
            '7d' => ['2026-09-30', '2026-10-06'],
            '30d' => ['2026-09-07', '2026-10-06'],
            'month' => ['2026-10-01', '2026-10-06'],
            'last_month' => ['2026-09-01', '2026-09-30'],
        ];

        foreach ($expected as $preset => [$from, $to]) {
            $response = $this->get(route('panel.reports.index', ['range' => $preset]));

            $this->assertSame($preset, $response->viewData('preset'));
            $this->assertSame($from, $response->viewData('from')->format('Y-m-d'), "$preset starts");
            $this->assertSame($to, $response->viewData('to')->format('Y-m-d'), "$preset ends");
        }
    }

    public function test_a_custom_period_is_honoured_and_a_broken_one_falls_back_to_thirty_days(): void
    {
        $this->signInAs('manager');

        $custom = $this->get(route('panel.reports.index', ['range' => 'custom', 'from' => '2026-09-10', 'to' => '2026-09-12']));
        $this->assertSame('custom', $custom->viewData('preset'));
        $this->assertCount(3, $custom->viewData('series'));

        foreach ([
            ['range' => 'custom', 'from' => 'x', 'to' => '2026-09-12'],
            ['range' => 'custom', 'from' => '2026-09-12', 'to' => '2026-09-10'],
            ['range' => 'custom'],
            ['range' => 'forever'],
        ] as $query) {
            $this->assertSame('30d', $this->get(route('panel.reports.index', $query))->viewData('preset'));
        }
    }

    public function test_a_period_longer_than_a_year_is_cut_to_a_year(): void
    {
        $this->signInAs('manager');

        $response = $this->get(route('panel.reports.index', ['range' => 'custom', 'from' => '2000-01-01', 'to' => '2026-10-06']));

        $this->assertLessThanOrEqual(367, count($response->viewData('series')));
        $this->assertSame('2026-10-06', $response->viewData('to')->format('Y-m-d'));
    }

    public function test_best_sellers_group_a_product_across_orders_and_keep_deleted_products_by_name(): void
    {
        $this->signInAs('manager');
        $product = Product::factory()->create();
        $first = $this->sale(10_000, '2026-10-06 08:00:00');
        $second = $this->sale(10_000, '2026-10-05 08:00:00');
        $cancelled = $this->sale(10_000, '2026-10-05 08:00:00', ['status' => OrderStatus::Cancelled]);

        OrderItem::factory()->for($first)->create(['product_id' => $product->id, 'name_en' => 'Albustan', 'name_ar' => 'البستان', 'quantity' => 2, 'total_fils' => 20_000]);
        OrderItem::factory()->for($second)->create(['product_id' => $product->id, 'name_en' => 'Albustan', 'name_ar' => 'البستان', 'quantity' => 3, 'total_fils' => 30_000]);
        OrderItem::factory()->for($cancelled)->create(['product_id' => $product->id, 'name_en' => 'Albustan', 'name_ar' => 'البستان', 'quantity' => 40, 'total_fils' => 400_000]);
        OrderItem::factory()->for($first)->create(['product_id' => null, 'name_en' => 'Retired Oud', 'name_ar' => 'عود قديم', 'quantity' => 1, 'total_fils' => 8_000]);
        OrderItem::factory()->for($second)->create(['product_id' => null, 'name_en' => 'Retired Oud', 'name_ar' => 'عود قديم', 'quantity' => 1, 'total_fils' => 8_000]);

        $rows = collect($this->get(route('panel.reports.index'))->viewData('products'))->keyBy('name_en');

        $this->assertSame(5, $rows['Albustan']->units);
        $this->assertSame(50_000, $rows['Albustan']->revenue);
        $this->assertSame(2, $rows['Retired Oud']->units);
        $this->assertCount(2, $rows);
    }

    public function test_sales_are_split_by_how_they_were_paid_and_where_they_went(): void
    {
        $this->signInAs('manager');
        $this->sale(10_000, '2026-10-06 08:00:00');
        $this->sale(20_000, '2026-10-06 09:00:00', ['payment_method' => Order::PAYMENT_ONLINE, 'city_name_en' => 'Kuwait City']);

        $response = $this->get(route('panel.reports.index'));

        $this->assertSame(['orders' => 1, 'revenue' => 22_000], $response->viewData('methods')['online']);
        $this->assertSame(['orders' => 1, 'revenue' => 12_000], $response->viewData('methods')['cod']);
        $this->assertSame(['orders' => 1, 'revenue' => 22_000], $response->viewData('cities')['Kuwait City']);
        $response->assertSee('Kuwait City');
    }

    public function test_the_pipeline_counts_every_status_including_cancelled_and_unpaid_orders(): void
    {
        $this->signInAs('manager');
        $this->sale(10_000, '2026-10-06 08:00:00');
        $this->sale(10_000, '2026-10-06 08:00:00', ['status' => OrderStatus::Cancelled]);
        $this->sale(10_000, '2026-10-06 08:00:00', ['status' => OrderStatus::PendingPayment]);

        $statuses = $this->get(route('panel.reports.index'))->viewData('statuses');

        $this->assertSame(1, $statuses['new']);
        $this->assertSame(1, $statuses['cancelled']);
        $this->assertSame(1, $statuses['pending_payment']);
    }

    public function test_the_daily_figures_can_be_downloaded_as_a_spreadsheet(): void
    {
        $this->signInAs('manager');
        $this->sale(10_000, '2026-10-06 08:00:00');

        $response = $this->get(route('panel.reports.export', ['range' => 'today']))->assertOk();

        $this->assertStringContainsString('sales-2026-10-06-to-2026-10-06.csv', $response->headers->get('Content-Disposition'));

        $content = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $this->assertStringContainsString('2026-10-06,1,12.000', $content);
    }

    public function test_a_quiet_period_says_so_instead_of_drawing_an_empty_chart(): void
    {
        $this->signInAs('manager');

        $this->get(route('panel.reports.index'))->assertSee('No sales in this period.')->assertDontSee('<svg class="chart"', false);
    }

    public function test_a_period_with_sales_draws_the_chart_with_a_bar_for_the_day_that_sold(): void
    {
        $this->signInAs('manager');
        $this->sale(10_000, '2026-10-06 08:00:00');

        $this->get(route('panel.reports.index', ['range' => '7d']))
            ->assertSee('<svg class="chart"', false)
            ->assertSee('class="bar"', false)
            ->assertSee('12.000 KWD');
    }
}
