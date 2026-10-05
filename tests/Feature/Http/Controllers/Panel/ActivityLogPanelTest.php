<?php

namespace Tests\Feature\Http\Controllers\Panel;

use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Store\ActivityLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\SignsInStaff;
use Tests\TestCase;

class ActivityLogPanelTest extends TestCase
{
    use LazilyRefreshDatabase, SignsInStaff;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = $this->signInAs('manager', ['name' => 'Mona Manager']);
    }

    public function test_only_roles_that_may_read_the_log_can_open_it(): void
    {
        $this->signInAs('staff');

        $this->get(route('panel.log.index'))->assertForbidden();
    }

    public function test_entries_are_listed_newest_first_with_who_did_what_and_to_what(): void
    {
        $order = Order::factory()->create();
        ActivityLog::factory()->create(['user_id' => $this->manager->id, 'action' => 'category.created', 'subject_type' => 'category', 'subject_id' => 1, 'subject_label' => 'Older Entry']);
        ActivityLog::factory()->create(['user_id' => $this->manager->id, 'action' => 'order.status_changed', 'subject_type' => 'order', 'subject_id' => $order->id, 'subject_label' => $order->number, 'properties' => ['from' => 'new', 'to' => 'accepted']]);

        $this->get(route('panel.log.index'))
            ->assertOk()
            ->assertSeeInOrder([$order->number, 'Older Entry'])
            ->assertSee('Mona Manager')
            ->assertSee('Changed an order\'s status')
            ->assertSee('Added a category')
            ->assertSeeInOrder(['New', '→', 'Accepted']);
    }

    public function test_an_entry_links_to_what_it_was_about_unless_that_has_been_deleted_or_is_out_of_reach(): void
    {
        $order = Order::factory()->create();
        $product = Product::factory()->create();
        ActivityLog::factory()->create(['user_id' => $this->manager->id, 'action' => 'order.note_saved', 'subject_type' => 'order', 'subject_id' => $order->id, 'subject_label' => $order->number]);
        ActivityLog::factory()->create(['user_id' => $this->manager->id, 'action' => 'product.deleted', 'subject_type' => null, 'subject_id' => null, 'subject_label' => 'Gone Product']);
        ActivityLog::factory()->create(['user_id' => $this->manager->id, 'action' => 'product.updated', 'subject_type' => 'product', 'subject_id' => $product->id, 'subject_label' => 'Linked Product']);
        ActivityLog::factory()->create(['user_id' => $this->manager->id, 'action' => 'staff.updated', 'subject_type' => 'user', 'subject_id' => $this->manager->id, 'subject_label' => 'Some Colleague']);

        $this->get(route('panel.log.index'))
            ->assertSee('href="'.route('panel.orders.show', $order).'"', false)
            ->assertSee('href="'.route('panel.products.edit', $product).'"', false)
            ->assertSee('Gone Product')
            ->assertDontSee('href="'.route('panel.products.edit', 999).'"', false)
            // A manager may not open the staff pages, so the entry is text, not a link.
            ->assertDontSee('href="'.route('panel.staff.edit', $this->manager).'"', false);
    }

    public function test_an_action_the_panel_has_no_wording_for_shows_its_plain_name(): void
    {
        ActivityLog::factory()->create(['user_id' => $this->manager->id, 'action' => 'future.thing_happened', 'subject_type' => null, 'subject_label' => null]);

        $this->get(route('panel.log.index'))->assertSee('future.thing_happened');
    }

    public function test_entries_can_be_narrowed_by_person_by_area_and_by_date(): void
    {
        $other = $this->staffMember('staff', ['name' => 'Omar Other']);
        ActivityLog::factory()->create(['user_id' => $this->manager->id, 'action' => 'product.created', 'subject_label' => 'Product Entry', 'created_at' => '2026-10-01 09:00:00']);
        ActivityLog::factory()->create(['user_id' => $other->id, 'action' => 'order.status_changed', 'subject_label' => 'Order Entry', 'created_at' => '2026-10-05 09:00:00']);
        $labels = fn (array $query) => $this->get(route('panel.log.index', $query))->viewData('entries')->pluck('subject_label')->all();

        $this->assertSame(['Order Entry', 'Product Entry'], $labels([]));
        $this->assertSame(['Order Entry'], $labels(['staff' => (string) $other->id]));
        $this->assertSame(['Product Entry'], $labels(['area' => 'product']));
        $this->assertSame(['Order Entry'], $labels(['from' => '2026-10-04']));
        $this->assertSame(['Product Entry'], $labels(['to' => '2026-10-02']));
        $this->assertSame(['Order Entry', 'Product Entry'], $labels(['area' => 'bad area!', 'from' => 'nonsense']));
    }

    public function test_the_areas_to_choose_from_are_the_ones_that_have_entries(): void
    {
        ActivityLog::factory()->create(['action' => 'product.created']);
        ActivityLog::factory()->create(['action' => 'order.note_saved']);
        ActivityLog::factory()->create(['action' => 'order.marked_paid']);

        $this->assertSame(['order', 'product'], $this->get(route('panel.log.index'))->viewData('areas')->all());
    }

    public function test_the_log_pages_at_thirty_entries(): void
    {
        ActivityLog::factory()->count(32)->create();

        $this->assertCount(30, $this->get(route('panel.log.index'))->viewData('entries'));
        $this->assertCount(2, $this->get(route('panel.log.index', ['page' => 2]))->viewData('entries'));
    }

    public function test_an_empty_log_says_so(): void
    {
        $this->get(route('panel.log.index'))->assertSee('No activity recorded');
    }

    public function test_an_entry_by_somebody_since_deleted_is_attributed_to_the_system_not_hidden(): void
    {
        ActivityLog::factory()->create(['user_id' => null, 'action' => 'product.created', 'subject_label' => 'Orphan Entry']);

        $this->get(route('panel.log.index'))->assertSee('Orphan Entry')->assertSee('System');
    }

    public function test_nothing_in_the_page_can_edit_or_remove_an_entry(): void
    {
        ActivityLog::factory()->create();

        $html = $this->get(route('panel.log.index'))->getContent();

        // The only form aimed at the log is the search, which only reads.
        $this->assertDoesNotMatchRegularExpression('#method="POST"[^>]*action="[^"]*/panel/log["/]#', $html);
        // There is nothing to send a change or a deletion to.
        $this->delete('/panel/log/1')->assertNotFound();
        $this->put('/panel/log')->assertStatus(405);
        $this->post('/panel/log')->assertStatus(405);
    }

    // ----------------------------------------------------------------- logger

    public function test_the_logger_labels_a_subject_by_its_number_code_name_or_email_and_never_leaves_it_blank(): void
    {
        $logger = app(ActivityLogger::class);
        $order = Order::factory()->create();

        $this->assertSame($order->number, $logger->record($this->manager, 'order.note_saved', $order)->subject_label);
        $this->assertSame('order', $logger->record($this->manager, 'order.note_saved', $order)->subject_type);
        $this->assertSame($this->manager->name, $logger->record($this->manager, 'auth.login', $this->manager)->subject_label);

        $bare = new class extends Model
        {
            protected $table = 'categories';
        };
        $bare->id = 7;
        $this->assertSame('#7', $logger->record($this->manager, 'x.y', $bare)->subject_label);
    }

    public function test_the_logger_keeps_properties_only_when_there_are_some_and_records_where_it_came_from(): void
    {
        $logger = app(ActivityLogger::class);

        $without = $logger->record($this->manager, 'auth.login');
        $with = $logger->record($this->manager, 'order.status_changed', null, ['from' => 'new'], 'DS-1');

        $this->assertNull($without->properties);
        $this->assertSame(['from' => 'new'], $with->properties);
        $this->assertSame('DS-1', $with->subject_label);
        $this->assertNull($without->subject_label);
        $this->assertSame('127.0.0.1', $with->ip);
    }

    public function test_the_log_lists_what_the_panel_itself_recorded_in_this_session(): void
    {
        Carbon::setTestNow('2026-10-06 10:00:00');
        $order = Order::factory()->create();

        $this->post(route('panel.orders.status', $order), ['status' => 'accepted']);

        $this->get(route('panel.log.index'))->assertSee($order->number)->assertSee('Changed an order\'s status');
    }
}
