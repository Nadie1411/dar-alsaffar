<?php

namespace Tests\Feature\Http\Controllers\Panel;

use App\Contracts\Store\Promotions;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\Voucher;
use App\Services\Store\Pricing\PricingContext;
use App\Services\Store\Pricing\PricingEngine;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\SignsInStaff;
use Tests\TestCase;

class VoucherManagementTest extends TestCase
{
    use LazilyRefreshDatabase, SignsInStaff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-06 10:00:00', 'UTC'));
        $this->signInAs('manager');
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function form(array $overrides = []): array
    {
        return array_merge([
            'code' => 'Welcome10', 'name_ar' => 'ترحيب', 'name_en' => 'Welcome',
            'type' => 'percentage', 'percent' => '10', 'is_active' => '1',
        ], $overrides);
    }

    public function test_a_percentage_voucher_is_created_with_everything_the_form_describes(): void
    {
        $this->post(route('panel.vouchers.store'), $this->form([
            'max_discount' => '5.000', 'min_subtotal' => '20', 'starts_at' => '2026-10-10T00:00', 'ends_at' => '2026-10-31T23:59',
            'usage_limit' => '100', 'usage_limit_per_customer' => '1', 'is_public' => '1',
        ]))->assertRedirect(route('panel.vouchers.index'));

        $voucher = Voucher::query()->firstOrFail();

        $this->assertSame('WELCOME10', $voucher->code);
        $this->assertSame(Voucher::TYPE_PERCENTAGE, $voucher->type);
        $this->assertSame('10.00', $voucher->percent);
        $this->assertSame(0, $voucher->amount_fils);
        $this->assertSame(5_000, $voucher->max_discount_fils);
        $this->assertSame(20_000, $voucher->min_subtotal_fils);
        $this->assertSame('2026-10-09 21:00:00', $voucher->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-31 20:59:00', $voucher->ends_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(100, $voucher->usage_limit);
        $this->assertSame(1, $voucher->usage_limit_per_customer);
        $this->assertTrue($voucher->is_active && $voucher->is_public);
        $this->assertDatabaseHas('activity_logs', ['action' => 'voucher.created', 'subject_label' => 'WELCOME10']);
    }

    public function test_a_fixed_voucher_keeps_its_amount_and_drops_the_percentage_and_cap(): void
    {
        $this->post(route('panel.vouchers.store'), $this->form([
            'type' => 'fixed', 'amount' => '٣٫٥٠٠', 'percent' => '50', 'max_discount' => '9',
        ]))->assertSessionHasNoErrors();

        $voucher = Voucher::query()->firstOrFail();

        $this->assertSame(3_500, $voucher->amount_fils);
        $this->assertSame('0.00', $voucher->percent);
        $this->assertNull($voucher->max_discount_fils);
    }

    public function test_a_percentage_voucher_ignores_an_amount_left_over_in_the_form(): void
    {
        $this->post(route('panel.vouchers.store'), $this->form(['type' => 'percentage', 'percent' => '10', 'amount' => '9.000']))
            ->assertSessionHasNoErrors();

        $voucher = Voucher::query()->firstOrFail();

        $this->assertSame(0, $voucher->amount_fils);
        $this->assertSame('10.00', $voucher->percent);
    }

    public function test_a_free_delivery_voucher_needs_no_amount(): void
    {
        $this->post(route('panel.vouchers.store'), $this->form(['type' => 'free_shipping', 'percent' => '', 'amount' => '']))
            ->assertSessionHasNoErrors();

        $voucher = Voucher::query()->firstOrFail();

        $this->assertSame(Voucher::TYPE_FREE_SHIPPING, $voucher->type);
        $this->assertSame(0, $voucher->amount_fils);
        $this->assertSame('0.00', $voucher->percent);
    }

    public function test_a_code_is_kept_in_capitals_without_stray_spaces_and_is_unique_whatever_its_case(): void
    {
        $this->post(route('panel.vouchers.store'), $this->form(['code' => '  summer-25 ']));
        $this->assertSame('SUMMER-25', Voucher::query()->firstOrFail()->code);

        $this->post(route('panel.vouchers.store'), $this->form(['code' => 'Summer-25']))->assertSessionHasErrors('code');
        $this->assertSame(1, Voucher::query()->count());
    }

    public function test_a_code_may_hold_letters_digits_dashes_and_underscores_only(): void
    {
        foreach (['HAS SPACE', 'WITH.DOT', 'SLASH/CODE', '<b>X</b>', ''] as $code) {
            $this->post(route('panel.vouchers.store'), $this->form(['code' => $code]))->assertSessionHasErrors('code');
        }

        $this->post(route('panel.vouchers.store'), $this->form(['code' => 'EID_2026-A']))->assertSessionHasNoErrors();
        $this->post(route('panel.vouchers.store'), $this->form(['code' => 'عيد٢٠٢٦']))->assertSessionHasNoErrors();
    }

    /**
     * @return array<string,array{0:array<string,mixed>,1:string}>
     */
    public static function invalidForms(): array
    {
        return [
            'a percentage voucher with no percentage' => [['percent' => ''], 'percent'],
            'a percentage of zero' => [['percent' => '0'], 'percent'],
            'a percentage over a hundred' => [['percent' => '120'], 'percent'],
            'a fixed voucher with no amount' => [['type' => 'fixed', 'amount' => ''], 'amount'],
            'a fixed voucher of nothing' => [['type' => 'fixed', 'amount' => '0'], 'amount'],
            'an amount that is not money' => [['type' => 'fixed', 'amount' => 'lots'], 'amount'],
            'a cap that is not money' => [['max_discount' => 'big'], 'max_discount'],
            'a minimum that is not money' => [['min_subtotal' => '-3'], 'min_subtotal'],
            'an unknown type' => [['type' => 'bogo'], 'type'],
            'an end before the start' => [['starts_at' => '2026-10-10T10:00', 'ends_at' => '2026-10-09T10:00'], 'ends_at'],
            'a usage limit of zero' => [['usage_limit' => '0'], 'usage_limit'],
            'a per-customer limit that is not a number' => [['usage_limit_per_customer' => 'one'], 'usage_limit_per_customer'],
            'no Arabic name' => [['name_ar' => ''], 'name_ar'],
            'no English name' => [['name_en' => ''], 'name_en'],
        ];
    }

    /**
     * @param  array<string,mixed>  $override
     */
    #[DataProvider('invalidForms')]
    public function test_a_voucher_that_does_not_add_up_is_refused(array $override, string $field): void
    {
        $this->post(route('panel.vouchers.store'), $this->form($override))->assertSessionHasErrors($field);

        $this->assertSame(0, Voucher::query()->count());
    }

    public function test_editing_a_voucher_keeps_its_own_code_without_complaining_about_it_being_taken(): void
    {
        $voucher = Voucher::factory()->percentage(10)->create(['code' => 'KEEPME']);

        $this->put(route('panel.vouchers.update', $voucher), $this->form(['code' => 'keepme', 'percent' => '15']))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('panel.vouchers.index'));

        $voucher->refresh();
        $this->assertSame('KEEPME', $voucher->code);
        $this->assertSame('15.00', $voucher->percent);
    }

    public function test_a_voucher_can_be_switched_off_and_deleted(): void
    {
        $voucher = Voucher::factory()->create(['code' => 'ENDME']);

        $this->put(route('panel.vouchers.update', $voucher), $this->form(['code' => 'ENDME', 'is_active' => null]));
        $this->assertFalse($voucher->fresh()->is_active);

        $this->delete(route('panel.vouchers.destroy', $voucher))->assertRedirect(route('panel.vouchers.index'));
        $this->assertNull(Voucher::query()->find($voucher->id));
        $this->assertDatabaseHas('activity_logs', ['action' => 'voucher.deleted', 'subject_label' => 'ENDME']);
    }

    public function test_the_list_filters_by_whether_a_voucher_is_live_scheduled_expired_or_off(): void
    {
        Voucher::factory()->create(['code' => 'LIVENOW']);
        Voucher::factory()->between('2026-12-01 00:00:00', null)->create(['code' => 'COMINGUP']);
        Voucher::factory()->between(null, '2026-09-01 00:00:00')->create(['code' => 'ALLDONE']);
        Voucher::factory()->inactive()->create(['code' => 'SWITCHEDOFF']);

        $codes = fn (array $query) => $this->get(route('panel.vouchers.index', $query))->viewData('vouchers')->pluck('code')->sort()->values()->all();

        $this->assertSame(['LIVENOW'], $codes(['status' => 'live']));
        $this->assertSame(['COMINGUP'], $codes(['status' => 'scheduled']));
        $this->assertSame(['ALLDONE'], $codes(['status' => 'expired']));
        $this->assertSame(['SWITCHEDOFF'], $codes(['status' => 'inactive']));
        $this->assertCount(4, $codes(['status' => 'all']));
        $this->assertCount(4, $codes(['status' => 'bogus']));
    }

    public function test_the_list_shows_how_often_each_code_was_used_and_what_it_gave_away_leaving_out_cancelled_orders(): void
    {
        Voucher::factory()->create(['code' => 'POPULAR', 'usage_limit' => 50]);
        Order::factory()->usingVoucher('POPULAR')->totalling(20_000, 2_000, 2_000)->create();
        Order::factory()->usingVoucher('POPULAR')->totalling(20_000, 2_000, 3_000)->create();
        Order::factory()->usingVoucher('POPULAR')->totalling(20_000, 2_000, 9_000)->create(['status' => OrderStatus::Cancelled]);

        $usage = $this->get(route('panel.vouchers.index'))->viewData('usage')->get('POPULAR');

        $this->assertSame(2, (int) $usage->orders);
        $this->assertSame(5_000, (int) $usage->discount);
        $this->get(route('panel.vouchers.index'))->assertSee('2 / 50');
    }

    public function test_the_edit_page_says_how_many_times_the_code_has_been_used(): void
    {
        $voucher = Voucher::factory()->create(['code' => 'USEDTWICE']);
        Order::factory()->usingVoucher('USEDTWICE')->count(2)->create();

        $this->get(route('panel.vouchers.edit', $voucher))->assertSee('Used 2 times');
    }

    public function test_a_voucher_made_here_discounts_the_basket_exactly_as_the_form_said(): void
    {
        $product = Product::factory()->priced(30_000)->create();
        $this->post(route('panel.vouchers.store'), $this->form(['code' => 'TENOFF', 'percent' => '10', 'max_discount' => '2.500', 'min_subtotal' => '20']));

        $basket = ['line' => ['productId' => (string) $product->id, 'quantity' => 1, 'options' => []]];
        $priced = app(PricingEngine::class)->price($basket, new PricingContext(voucherCode: 'tenoff'));

        // 10% of 30.000 is 3.000, held to the 2.500 cap.
        $this->assertTrue($priced->voucher->accepted);
        $this->assertSame(2_500, $priced->discountFils);

        $cheap = Product::factory()->priced(10_000)->create();
        $below = app(PricingEngine::class)->price(['line' => ['productId' => (string) $cheap->id, 'quantity' => 1, 'options' => []]], new PricingContext(voucherCode: 'TENOFF'));
        $this->assertFalse($below->voucher->accepted, 'under the minimum order');
    }

    public function test_only_a_live_announced_voucher_reaches_the_storefronts_strip(): void
    {
        $this->post(route('panel.vouchers.store'), $this->form(['code' => 'ANNOUNCED', 'is_public' => '1']));
        $this->post(route('panel.vouchers.store'), $this->form(['code' => 'QUIET']));
        $this->post(route('panel.vouchers.store'), $this->form(['code' => 'EXPIRED', 'is_public' => '1', 'ends_at' => '2026-09-01T00:00']));

        $codes = array_column(app(Promotions::class)->public(), 'code');

        $this->assertSame(['ANNOUNCED'], $codes);
    }

    public function test_a_voucher_that_does_not_exist_is_a_404(): void
    {
        $this->get(route('panel.vouchers.edit', 999))->assertNotFound();
    }
}
