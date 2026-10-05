<?php

namespace Tests\Feature\Http\Controllers\Panel;

use App\Enums\OrderStatus;
use App\Enums\PaymentState;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusChange;
use App\Models\Payment;
use App\Models\Product;
use App\Services\Store\OrderLifecycle;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Concerns\SignsInStaff;
use Tests\TestCase;

class OrderManagementTest extends TestCase
{
    use LazilyRefreshDatabase, SignsInStaff;

    /**
     * @param  array<string,mixed>  $attributes
     */
    private function order(array $attributes = []): Order
    {
        return Order::factory()->create($attributes);
    }

    /**
     * @return array<string,mixed>
     */
    private function csvRows(string $content): array
    {
        $lines = array_values(array_filter(preg_split('/\R/u', ltrim($content, "\xEF\xBB\xBF")) ?: []));

        return array_map('str_getcsv', $lines);
    }

    // ------------------------------------------------------------------ list

    public function test_a_guest_is_sent_to_the_login_page(): void
    {
        $order = $this->order();

        $this->get(route('panel.orders.index'))->assertRedirect(route('panel.login'));
        $this->get(route('panel.orders.show', $order))->assertRedirect(route('panel.login'));
        $this->post(route('panel.orders.status', $order), ['status' => 'accepted'])->assertRedirect(route('panel.login'));
        $this->assertSame(OrderStatus::New, $order->fresh()->status);
    }

    public function test_orders_are_listed_newest_first_and_each_status_tab_counts_its_own(): void
    {
        $this->signInAs('staff');
        $first = $this->order(['customer_name' => 'First Customer']);
        $second = $this->order(['customer_name' => 'Second Customer']);
        $this->order(['status' => OrderStatus::Delivered]);
        $this->order(['status' => OrderStatus::Cancelled]);

        $response = $this->get(route('panel.orders.index'))->assertOk();

        $response->assertSeeInOrder(['Second Customer', 'First Customer']);
        $this->assertSame(4, $response->viewData('allCount'));
        $this->assertSame(2, $response->viewData('counts')['new']);
        $this->assertSame(1, $response->viewData('counts')['delivered']);
    }

    public function test_the_list_can_be_narrowed_to_one_status(): void
    {
        $this->signInAs('staff');
        $this->order(['customer_name' => 'Still New']);
        $this->order(['customer_name' => 'Already Delivered', 'status' => OrderStatus::Delivered]);

        $this->get(route('panel.orders.index', ['status' => 'delivered']))
            ->assertOk()
            ->assertSee('Already Delivered')
            ->assertDontSee('Still New');
    }

    public function test_a_search_finds_an_order_by_number_name_phone_or_email(): void
    {
        $this->signInAs('staff');
        $target = $this->order([
            'customer_name' => 'Yasmin Al-Kandari', 'customer_phone' => '+96599887766', 'customer_email' => 'yasmin@example.com',
        ]);
        $this->order(['customer_name' => 'Someone Else', 'customer_phone' => '+96551111111', 'customer_email' => 'else@example.com']);

        foreach ([$target->number, 'Yasmin', '99887766', 'yasmin@example'] as $term) {
            $this->get(route('panel.orders.index', ['q' => $term]))
                ->assertOk()
                ->assertSee('Yasmin Al-Kandari')
                ->assertDontSee('Someone Else');
        }
    }

    public function test_the_list_can_be_narrowed_by_how_the_order_is_paid(): void
    {
        $this->signInAs('staff');
        $this->order(['customer_name' => 'Cash Customer']);
        $this->order(['customer_name' => 'Card Customer', 'payment_method' => Order::PAYMENT_ONLINE]);

        $this->get(route('panel.orders.index', ['payment' => 'online']))
            ->assertSee('Card Customer')
            ->assertDontSee('Cash Customer');
    }

    public function test_dates_are_days_in_kuwait_not_days_in_utc(): void
    {
        $this->signInAs('staff');

        // 21:30 UTC on the 5th is half past midnight on the 6th in Kuwait.
        $lateNight = $this->order(['customer_name' => 'Late Night', 'placed_at' => '2026-10-05 21:30:00']);
        $this->order(['customer_name' => 'Earlier', 'placed_at' => '2026-10-05 09:00:00']);

        $this->get(route('panel.orders.index', ['from' => '2026-10-06', 'to' => '2026-10-06']))
            ->assertSee('Late Night')
            ->assertDontSee('Earlier');

        $this->get(route('panel.orders.index', ['from' => '2026-10-05', 'to' => '2026-10-05']))
            ->assertSee('Earlier')
            ->assertDontSee('Late Night');

        $this->assertNotNull($lateNight->number);
    }

    public function test_values_the_list_does_not_recognise_are_ignored_rather_than_rejected(): void
    {
        $this->signInAs('staff');
        $this->order(['customer_name' => 'Still Listed']);

        $this->get(route('panel.orders.index', ['status' => 'bogus', 'payment' => 'barter', 'from' => 'yesterday', 'to' => '2026-13-45']))
            ->assertOk()
            ->assertSee('Still Listed');
    }

    public function test_the_list_pages_at_twenty_orders(): void
    {
        $this->signInAs('staff');
        Order::factory()->count(23)->create();

        $this->assertCount(20, $this->get(route('panel.orders.index'))->viewData('orders'));
        $this->assertCount(3, $this->get(route('panel.orders.index', ['page' => 2]))->viewData('orders'));
    }

    // ---------------------------------------------------------------- detail

    public function test_an_order_page_shows_what_was_bought_what_it_cost_and_where_it_goes(): void
    {
        $this->signInAs('staff');
        $order = $this->order([
            'customer_name' => 'Maha Al-Sabah', 'customer_phone' => '+96555512345',
            'subtotal_fils' => 40_000, 'discount_fils' => 4_000, 'delivery_fee_fils' => 0, 'cod_fee_fils' => 500,
            'addons_total_fils' => 1_500, 'total_fils' => 38_000, 'voucher_code' => 'WELCOME10',
            'notes' => 'Leave with the guard', 'admin_notes' => 'Regular customer',
            'addons' => [['id' => 1, 'name_ar' => 'تغليف', 'name_en' => 'Gift wrapping', 'price_fils' => 1_500]],
        ]);
        OrderItem::factory()->for($order)->create([
            'name_en' => 'Albustan', 'name_ar' => 'البستان', 'sku' => 'ALB-50', 'quantity' => 2,
            'unit_price_fils' => 20_000, 'total_fils' => 40_000,
            'options' => [['group' => ['ar' => 'الحجم', 'en' => 'Size'], 'values' => [['name' => ['ar' => '50 مل', 'en' => '50 ml'], 'quantity' => 1, 'price_fils' => 0]]]],
        ]);

        $this->get(route('panel.orders.show', $order))
            ->assertOk()
            ->assertSee($order->number)
            ->assertSee('Maha Al-Sabah')
            ->assertSee('Albustan')
            ->assertSee('ALB-50')
            ->assertSeeInOrder(['Size:', '50 ml'])
            ->assertSee('40.000 KWD')
            ->assertSee('4.000 KWD')
            ->assertSee('WELCOME10')
            ->assertSee('Free')
            ->assertSee('38.000 KWD')
            ->assertSee('Gift wrapping')
            ->assertSee('Leave with the guard')
            ->assertSee('Regular customer')
            ->assertSee('https://wa.me/96555512345', false);
    }

    public function test_the_customer_card_distinguishes_an_account_from_a_guest(): void
    {
        $this->signInAs('staff');
        $guest = $this->order();
        $member = Customer::factory()->create();
        $account = Order::factory()->forCustomer($member)->create();

        $this->get(route('panel.orders.show', $guest))->assertSee('Guest order');
        $this->get(route('panel.orders.show', $account))->assertSee('Customer account')->assertDontSee('Guest order');
    }

    public function test_the_history_names_who_moved_the_order_and_what_they_said(): void
    {
        $this->signInAs('staff');
        $order = $this->order();
        $packer = $this->staffMember('manager', ['name' => 'Hind Packer']);
        OrderStatusChange::factory()->for($order)->create(['from_status' => OrderStatus::New, 'to_status' => OrderStatus::Preparing, 'user_id' => $packer->id, 'note' => 'Boxing it now']);

        $this->get(route('panel.orders.show', $order))
            ->assertSee('Preparing')
            ->assertSee('by Hind Packer')
            ->assertSee('Boxing it now');
    }

    public function test_an_online_order_lists_its_payment_attempts_and_flags_anything_odd(): void
    {
        $this->signInAs('manager');
        $order = Order::factory()->online()->create();
        Payment::factory()->for($order)->create([
            'state' => PaymentState::Paid, 'mf_invoice_id' => '6123456', 'anomaly' => Payment::ANOMALY_ORDER_CANCELLED,
        ]);

        $this->get(route('panel.orders.show', $order))
            ->assertSee('Payment attempts')
            ->assertSee('6123456')
            ->assertSee('Payment arrived after the order was cancelled');
    }

    public function test_an_order_that_does_not_exist_is_a_404(): void
    {
        $this->signInAs('staff');

        $this->get(route('panel.orders.show', 999))->assertNotFound();
    }

    // ---------------------------------------------------------------- status

    public function test_a_status_move_is_made_recorded_and_logged_with_the_person_who_made_it(): void
    {
        $staff = $this->signInAs('staff');
        $order = $this->order();

        $this->post(route('panel.orders.status', $order), ['status' => 'accepted', 'note' => 'Confirmed by phone'])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame(OrderStatus::Accepted, $order->fresh()->status);
        $this->assertDatabaseHas('order_status_changes', [
            'order_id' => $order->id, 'from_status' => 'new', 'to_status' => 'accepted', 'user_id' => $staff->id, 'note' => 'Confirmed by phone',
        ]);
        $this->assertDatabaseHas('activity_logs', ['user_id' => $staff->id, 'action' => 'order.status_changed', 'subject_label' => $order->number]);
    }

    public function test_delivering_an_order_stamps_when(): void
    {
        $this->signInAs('staff');
        $order = $this->order(['status' => OrderStatus::OutForDelivery]);

        $this->post(route('panel.orders.status', $order), ['status' => 'delivered']);

        $this->assertNotNull($order->fresh()->delivered_at);
    }

    public function test_cancelling_an_order_puts_its_stock_back_exactly_once(): void
    {
        $this->signInAs('staff');
        $product = Product::factory()->withStock(5)->create();
        $order = $this->order();
        OrderItem::factory()->for($order)->create(['product_id' => $product->id, 'quantity' => 2]);

        $this->post(route('panel.orders.status', $order), ['status' => 'cancelled']);
        $this->assertSame(7, $product->fresh()->stock);
        $this->assertNotNull($order->fresh()->cancelled_at);

        // A second cancellation — a double click, a stale page — gives nothing back.
        $this->post(route('panel.orders.status', $order), ['status' => 'cancelled'])->assertSessionHas('warning');
        $this->assertSame(7, $product->fresh()->stock);
    }

    public function test_the_moves_staff_may_make_follow_the_rules(): void
    {
        $this->signInAs('staff');

        $cases = [
            // From            → to the move is refused
            ['delivered', 'preparing'],
            ['delivered', 'cancelled'],
            ['cancelled', 'new'],
            ['cancelled', 'accepted'],
            ['pending_payment', 'new'],
            ['pending_payment', 'preparing'],
            ['pending_payment', 'delivered'],
        ];

        foreach ($cases as [$from, $to]) {
            $order = $this->order(['status' => OrderStatus::from($from)]);

            $this->post(route('panel.orders.status', $order), ['status' => $to])->assertSessionHas('warning');
            $this->assertSame($from, $order->fresh()->status->value, "$from must not move to $to");
        }
    }

    public function test_an_order_waiting_for_payment_can_be_cancelled_and_nothing_else(): void
    {
        $this->signInAs('staff');
        $order = Order::factory()->online()->pendingPayment()->create();

        $this->post(route('panel.orders.status', $order), ['status' => 'cancelled'])->assertSessionHas('status');

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
    }

    public function test_an_order_open_in_two_windows_cannot_be_moved_from_a_stale_page(): void
    {
        $this->signInAs('staff');
        $order = $this->order();
        $page = Order::query()->find($order->id); // what the second window still believes

        // The first window cancels it.
        $this->post(route('panel.orders.status', $order), ['status' => 'cancelled']);
        $this->assertSame(OrderStatus::New, $page->status);

        // The second window, unaware, tries to start preparing it.
        $this->post(route('panel.orders.status', $page), ['status' => 'preparing'])->assertSessionHas('warning');

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
    }

    public function test_a_status_the_panel_does_not_know_is_refused(): void
    {
        $this->signInAs('staff');
        $order = $this->order();

        $this->post(route('panel.orders.status', $order), ['status' => 'teleported'])->assertSessionHasErrors('status');
        $this->post(route('panel.orders.status', $order), [])->assertSessionHasErrors('status');
        $this->assertSame(OrderStatus::New, $order->fresh()->status);
    }

    public function test_the_page_offers_only_the_moves_that_are_allowed(): void
    {
        $this->signInAs('staff');

        $delivered = $this->order(['status' => OrderStatus::Delivered]);
        $this->get(route('panel.orders.show', $delivered))->assertSee('final status');

        $waiting = Order::factory()->online()->pendingPayment()->create();
        $response = $this->get(route('panel.orders.show', $waiting))->assertSee('waiting for its online payment');
        $this->assertSame([OrderStatus::Cancelled], $response->viewData('transitions'));
    }

    // ------------------------------------------------------------- the notes

    public function test_an_internal_note_is_saved_and_can_be_cleared(): void
    {
        $this->signInAs('staff');
        $order = $this->order();

        $this->post(route('panel.orders.note', $order), ['admin_notes' => '  Call before delivering  '])->assertSessionHas('status');
        $this->assertSame('Call before delivering', $order->fresh()->admin_notes);

        $this->post(route('panel.orders.note', $order), ['admin_notes' => ''])->assertSessionHas('status');
        $this->assertNull($order->fresh()->admin_notes);
    }

    public function test_an_internal_note_has_a_limit(): void
    {
        $this->signInAs('staff');
        $order = $this->order();

        $this->post(route('panel.orders.note', $order), ['admin_notes' => str_repeat('x', 2_001)])->assertSessionHasErrors('admin_notes');
    }

    // --------------------------------------------------------------- payment

    public function test_cash_received_on_delivery_can_be_recorded_once(): void
    {
        $this->signInAs('staff');
        $order = $this->order();

        $this->post(route('panel.orders.paid', $order))->assertSessionHas('status');

        $order->refresh();
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertNotNull($order->paid_at);

        $this->post(route('panel.orders.paid', $order))->assertSessionHas('warning');
        $this->assertDatabaseHas('activity_logs', ['action' => 'order.marked_paid', 'subject_label' => $order->number]);
    }

    public function test_an_online_order_can_never_be_marked_paid_by_hand(): void
    {
        $this->signInAs('manager');
        $order = Order::factory()->online()->create();

        $this->post(route('panel.orders.paid', $order))->assertSessionHas('warning');

        $this->assertSame(PaymentStatus::Unpaid, $order->fresh()->payment_status);
    }

    public function test_a_cancelled_order_cannot_be_marked_paid(): void
    {
        $this->signInAs('staff');
        $order = $this->order(['status' => OrderStatus::Cancelled]);

        $this->post(route('panel.orders.paid', $order))->assertSessionHas('warning');
        $this->assertSame(PaymentStatus::Unpaid, $order->fresh()->payment_status);
    }

    public function test_a_refund_can_be_recorded_only_for_an_online_payment_on_a_cancelled_order(): void
    {
        $this->signInAs('manager');

        $refundable = Order::factory()->online()->paid()->create(['status' => OrderStatus::Cancelled]);
        $this->post(route('panel.orders.refunded', $refundable))->assertSessionHas('status');
        $this->assertSame(PaymentStatus::Refunded, $refundable->fresh()->payment_status);

        $stillOpen = Order::factory()->online()->paid()->create();
        $this->post(route('panel.orders.refunded', $stillOpen))->assertSessionHas('warning');
        $this->assertSame(PaymentStatus::Paid, $stillOpen->fresh()->payment_status);

        $unpaid = Order::factory()->online()->create(['status' => OrderStatus::Cancelled]);
        $this->post(route('panel.orders.refunded', $unpaid))->assertSessionHas('warning');
        $this->assertSame(PaymentStatus::Unpaid, $unpaid->fresh()->payment_status);
    }

    public function test_a_paid_online_order_that_was_cancelled_tells_staff_to_refund_it_at_the_gateway(): void
    {
        $this->signInAs('manager');
        $order = Order::factory()->online()->paid()->create(['status' => OrderStatus::Cancelled]);

        $this->get(route('panel.orders.show', $order))->assertSee('Refund the customer from your MyFatoorah dashboard');
    }

    // ----------------------------------------------------------------- export

    public function test_the_list_can_be_downloaded_as_a_spreadsheet_that_opens_correctly_in_excel(): void
    {
        $this->signInAs('staff');
        $order = $this->order(['customer_name' => 'سارة الأحمد', 'subtotal_fils' => 12_500, 'total_fils' => 14_500]);

        $response = $this->get(route('panel.orders.export'))->assertOk();

        $this->assertStringStartsWith('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('orders-', $response->headers->get('Content-Disposition'));

        $content = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content, 'a byte-order mark, so Excel reads the Arabic as UTF-8');

        $rows = $this->csvRows($content);
        $this->assertSame($order->number, $rows[1][0]);
        $this->assertSame('سارة الأحمد', $rows[1][5]);
        $this->assertSame('12.500', $rows[1][11]);
        $this->assertSame('14.500', $rows[1][16]);
    }

    public function test_the_download_follows_the_filters_on_the_page(): void
    {
        $this->signInAs('staff');
        $this->order(['customer_name' => 'Wanted']);
        $this->order(['customer_name' => 'Unwanted', 'status' => OrderStatus::Cancelled]);

        $content = $this->get(route('panel.orders.export', ['status' => 'new']))->streamedContent();

        $this->assertStringContainsString('Wanted', $content);
        $this->assertStringNotContainsString('Unwanted', $content);
    }

    public function test_a_customer_cannot_plant_a_spreadsheet_formula_in_the_download(): void
    {
        $this->signInAs('staff');
        $this->order(['customer_name' => '=HYPERLINK("http://evil.example","click")', 'notes' => '@SUM(1+1)']);

        $rows = $this->csvRows($this->get(route('panel.orders.export'))->streamedContent());

        $this->assertSame('\'=HYPERLINK("http://evil.example","click")', $rows[1][5]);
        $this->assertSame("'@SUM(1+1)", $rows[1][18]);
    }

    // ------------------------------------------------------------------ print

    public function test_the_printable_page_has_what_the_parcel_needs_and_nothing_internal(): void
    {
        $this->signInAs('staff');
        $order = $this->order(['customer_name' => 'Noor', 'notes' => 'Ring twice', 'admin_notes' => 'Probably a reseller']);
        OrderItem::factory()->for($order)->create(['name_en' => 'Albustan', 'quantity' => 1, 'unit_price_fils' => 20_000, 'total_fils' => 20_000]);

        $this->get(route('panel.orders.print', $order))
            ->assertOk()
            ->assertSee($order->number)
            ->assertSee('Noor')
            ->assertSee('Albustan')
            ->assertSee('Ring twice')
            ->assertDontSee('Probably a reseller');
    }

    // ------------------------------------------------------------------- feed

    public function test_the_alert_feed_reports_how_many_orders_wait_and_the_latest_to_arrive(): void
    {
        $this->signInAs('staff');
        $first = $this->order(['total_fils' => 14_500]);
        OrderStatusChange::factory()->for($first)->create(['from_status' => null, 'to_status' => OrderStatus::New]);

        $response = $this->getJson(route('panel.orders.feed'))->assertOk();

        $response->assertJson(['awaiting' => 1])->assertJsonPath('latestId', fn ($id) => $id > 0);
        $this->assertStringContainsString($first->number, $response->json('message'));
        $this->assertStringContainsString('14.500', $response->json('message'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_an_online_order_paid_after_it_was_placed_rings_when_the_payment_is_confirmed(): void
    {
        $this->signInAs('staff');
        $earlier = $this->order();
        OrderStatusChange::factory()->for($earlier)->create(['from_status' => null, 'to_status' => OrderStatus::New]);
        $waiting = Order::factory()->online()->pendingPayment()->create();

        $before = $this->getJson(route('panel.orders.feed'))->json('latestId');

        // The payment comes through a while later: the order only now becomes "new".
        app(OrderLifecycle::class)->moveTo($waiting, OrderStatus::New, null, 'Paid online');

        $after = $this->getJson(route('panel.orders.feed'))->json();

        $this->assertGreaterThan($before, $after['latestId']);
        $this->assertStringContainsString($waiting->number, $after['message']);
        $this->assertSame(2, $after['awaiting']);
    }

    public function test_an_order_still_waiting_for_its_payment_does_not_ring(): void
    {
        $this->signInAs('staff');
        $waiting = Order::factory()->online()->pendingPayment()->create();
        // Placing it is recorded, as a real checkout does — but as "pending payment", not "new".
        OrderStatusChange::factory()->for($waiting)->create(['from_status' => null, 'to_status' => OrderStatus::PendingPayment]);

        $this->getJson(route('panel.orders.feed'))->assertJson(['awaiting' => 0, 'latestId' => 0, 'message' => '']);
    }

    public function test_the_alert_feed_is_for_signed_in_staff_only(): void
    {
        $this->getJson(route('panel.orders.feed'))->assertUnauthorized();
    }
}
