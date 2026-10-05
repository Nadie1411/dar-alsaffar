<?php

namespace Tests\Feature\Http\Controllers\Panel;

use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\DeliveryArea;
use App\Models\Order;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Concerns\SignsInStaff;
use Tests\TestCase;

class CustomerPanelTest extends TestCase
{
    use LazilyRefreshDatabase, SignsInStaff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->signInAs('staff');
    }

    public function test_customers_are_listed_newest_first_with_what_they_have_bought(): void
    {
        $regular = Customer::factory()->create(['name' => 'Regular Rana']);
        $newcomer = Customer::factory()->create(['name' => 'Newcomer Noor']);
        Order::factory()->forCustomer($regular)->totalling(10_000, 2_000)->count(2)->create();

        $response = $this->get(route('panel.customers.index'))->assertOk()->assertSeeInOrder(['Newcomer Noor', 'Regular Rana']);

        $rows = $response->viewData('customers')->keyBy('id');
        $this->assertSame(2, $rows[$regular->id]->orders_count);
        $this->assertSame(24_000, (int) $rows[$regular->id]->orders_sum_total_fils);
        $this->assertSame(0, $rows[$newcomer->id]->orders_count);
    }

    public function test_cancelled_and_unpaid_orders_do_not_make_a_customer_look_better_than_they_are(): void
    {
        $customer = Customer::factory()->create();
        Order::factory()->forCustomer($customer)->totalling(10_000, 0)->create();
        Order::factory()->forCustomer($customer)->totalling(90_000, 0)->create(['status' => OrderStatus::Cancelled]);
        Order::factory()->forCustomer($customer)->totalling(80_000, 0)->pendingPayment()->create();

        $row = $this->get(route('panel.customers.index'))->viewData('customers')->first();

        $this->assertSame(1, $row->orders_count);
        $this->assertSame(10_000, (int) $row->orders_sum_total_fils);
    }

    public function test_customers_can_be_searched_by_name_email_or_phone(): void
    {
        Customer::factory()->create(['name' => 'Lulwa Al-Mutairi', 'email' => 'lulwa@example.com', 'phone' => '55512345']);
        Customer::factory()->create(['name' => 'Someone Else', 'email' => 'else@example.com', 'phone' => '66600000']);

        foreach (['Lulwa', 'lulwa@example', '5551234'] as $term) {
            $this->get(route('panel.customers.index', ['q' => $term]))
                ->assertSee('Lulwa Al-Mutairi')
                ->assertDontSee('Someone Else');
        }

        $this->get(route('panel.customers.index', ['q' => 'nobody-by-that-name']))->assertSee('Nothing found');
    }

    public function test_an_empty_list_says_so(): void
    {
        $this->get(route('panel.customers.index'))->assertSee('No customers yet');
    }

    public function test_the_list_pages_at_twenty(): void
    {
        Customer::factory()->count(22)->create();

        $this->assertCount(20, $this->get(route('panel.customers.index'))->viewData('customers'));
        $this->assertCount(2, $this->get(route('panel.customers.index', ['page' => 2]))->viewData('customers'));
    }

    public function test_a_customers_page_shows_their_history_totals_and_contact_details(): void
    {
        $customer = Customer::factory()->create(['name' => 'Fatma Al-Rashed', 'email' => 'fatma@example.com', 'phone' => '55501234', 'marketing_opt_in' => true]);
        $first = Order::factory()->forCustomer($customer)->totalling(10_000, 0)->create(['placed_at' => now()->subDays(5)]);
        $last = Order::factory()->forCustomer($customer)->totalling(20_000, 0)->create(['placed_at' => now()->subDay()]);
        Order::factory()->forCustomer($customer)->totalling(50_000, 0)->create(['status' => OrderStatus::Cancelled]);

        $response = $this->get(route('panel.customers.show', $customer))
            ->assertOk()
            ->assertSee('Fatma Al-Rashed')
            ->assertSee('fatma@example.com')
            ->assertSee('https://wa.me/55501234', false)
            ->assertSee('agreed to receive offers')
            ->assertSee($first->number)
            ->assertSee($last->number);

        $this->assertSame(2, $response->viewData('countedOrders'));
        $this->assertSame(30_000, $response->viewData('spent'));
        $this->assertSame(15_000, $response->viewData('average'));
        $this->assertCount(3, $response->viewData('orders'));
    }

    public function test_a_customer_who_has_not_agreed_to_marketing_is_shown_as_such(): void
    {
        $customer = Customer::factory()->create(['marketing_opt_in' => false]);

        $this->get(route('panel.customers.show', $customer))->assertSee('Has not agreed to marketing messages', false)->assertSee('has no orders yet', false);
    }

    public function test_their_saved_addresses_are_named_in_the_panels_language(): void
    {
        $customer = Customer::factory()->create();
        $area = DeliveryArea::factory()->create(['name_en' => 'Salmiya', 'name_ar' => 'السالمية']);
        CustomerAddress::factory()->create([
            'customer_id' => $customer->id, 'delivery_city_id' => $area->delivery_city_id, 'delivery_area_id' => $area->id, 'block' => '4', 'street' => 'Street 12',
        ]);

        $this->get(route('panel.customers.show', $customer))->assertSee('Salmiya')->assertSee('Street 12');
    }

    public function test_another_customers_orders_never_appear_on_this_page(): void
    {
        $customer = Customer::factory()->create();
        $stranger = Customer::factory()->create();
        $theirs = Order::factory()->forCustomer($stranger)->create();

        $this->get(route('panel.customers.show', $customer))->assertDontSee($theirs->number);
    }

    public function test_a_customer_who_does_not_exist_is_a_404(): void
    {
        $this->get(route('panel.customers.show', 999))->assertNotFound();
    }

    public function test_the_list_can_be_downloaded_with_spend_and_consent_and_cannot_carry_a_formula(): void
    {
        $customer = Customer::factory()->create(['name' => '=cmd|"/c calc"!A1', 'email' => 'x@example.com', 'marketing_opt_in' => true]);
        Order::factory()->forCustomer($customer)->totalling(10_000, 2_000)->create();

        $response = $this->get(route('panel.customers.export'))->assertOk();
        $content = $response->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $rows = array_map('str_getcsv', array_values(array_filter(preg_split('/\R/u', ltrim($content, "\xEF\xBB\xBF")))));

        $this->assertSame('\'=cmd|"/c calc"!A1', $rows[1][0]);
        $this->assertSame('1', $rows[1][3]);
        $this->assertSame('12.000', $rows[1][4]);
        $this->assertSame('Yes', $rows[1][5]);
    }

    public function test_the_download_follows_the_search(): void
    {
        Customer::factory()->create(['name' => 'Included Person']);
        Customer::factory()->create(['name' => 'Left Out Person']);

        $content = $this->get(route('panel.customers.export', ['q' => 'Included']))->streamedContent();

        $this->assertStringContainsString('Included Person', $content);
        $this->assertStringNotContainsString('Left Out Person', $content);
    }
}
