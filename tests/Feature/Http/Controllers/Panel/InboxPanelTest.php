<?php

namespace Tests\Feature\Http\Controllers\Panel;

use App\Contracts\Store\Inbox;
use App\Models\ContactMessage;
use App\Models\NewsletterSubscriber;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Concerns\SignsInStaff;
use Tests\TestCase;

class InboxPanelTest extends TestCase
{
    use LazilyRefreshDatabase, SignsInStaff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->signInAs('staff');
    }

    public function test_a_message_sent_through_the_storefront_arrives_in_the_inbox(): void
    {
        app(Inbox::class)->sendContact(['name' => 'Dana', 'email' => 'Dana@Example.com', 'phone' => '55512345', 'message' => 'Do you ship to Bahrain?']);

        $this->get(route('panel.inbox.index'))
            ->assertOk()
            ->assertSee('Dana')
            ->assertSee('Do you ship to Bahrain?')
            ->assertSee('New');
    }

    public function test_messages_are_listed_newest_first_and_unread_ones_are_counted_in_the_tab_and_the_sidebar(): void
    {
        ContactMessage::factory()->create(['name' => 'Older Sender']);
        ContactMessage::factory()->create(['name' => 'Newer Sender']);
        ContactMessage::factory()->read()->create(['name' => 'Already Read']);

        $response = $this->get(route('panel.inbox.index'))->assertSeeInOrder(['Already Read', 'Newer Sender', 'Older Sender']);

        $this->assertSame(2, $response->viewData('unread'));
        $this->assertSame(3, $response->viewData('total'));
        $response->assertSee('<span class="panel-nav__count">2</span>', false);
    }

    public function test_the_list_can_show_only_unread_messages_or_be_searched(): void
    {
        ContactMessage::factory()->create(['name' => 'Unread Una', 'message' => 'About oud']);
        ContactMessage::factory()->read()->create(['name' => 'Read Rami', 'message' => 'About musk']);

        $this->get(route('panel.inbox.index', ['filter' => 'unread']))->assertSee('Unread Una')->assertDontSee('Read Rami');
        $this->get(route('panel.inbox.index', ['q' => 'musk']))->assertSee('Read Rami')->assertDontSee('Unread Una');
    }

    public function test_opening_a_message_marks_it_read_and_it_can_be_marked_unread_again(): void
    {
        $message = ContactMessage::factory()->create(['message' => "First line\nSecond line", 'phone' => '55512345']);

        $this->get(route('panel.inbox.show', $message))
            ->assertOk()
            ->assertSee('First line')
            ->assertSee('mailto:'.$message->email, false)
            ->assertSee('https://wa.me/55512345', false);

        $this->assertNotNull($message->fresh()->read_at);

        $this->post(route('panel.inbox.read', $message))->assertRedirect(route('panel.inbox.index'));
        $this->assertNull($message->fresh()->read_at);

        $this->post(route('panel.inbox.read', $message));
        $this->assertNotNull($message->fresh()->read_at);
    }

    public function test_what_a_customer_writes_is_shown_as_text_never_as_markup(): void
    {
        $message = ContactMessage::factory()->create(['name' => '<b>Bold</b> Name', 'message' => '<script>alert(1)</script> hello']);

        $this->get(route('panel.inbox.show', $message))
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt; hello', false);
        $this->get(route('panel.inbox.index'))->assertDontSee('<b>Bold</b>', false);
    }

    public function test_a_message_can_be_deleted_and_the_deletion_is_logged(): void
    {
        $message = ContactMessage::factory()->create(['name' => 'Delete Me']);

        $this->delete(route('panel.inbox.destroy', $message))->assertRedirect(route('panel.inbox.index'));

        $this->assertNull(ContactMessage::query()->find($message->id));
        $this->assertDatabaseHas('activity_logs', ['action' => 'inbox.message_deleted', 'subject_label' => 'Delete Me']);
    }

    public function test_a_message_that_does_not_exist_is_a_404(): void
    {
        $this->get(route('panel.inbox.show', 999))->assertNotFound();
        $this->post(route('panel.inbox.read', 999))->assertNotFound();
        $this->delete(route('panel.inbox.destroy', 999))->assertNotFound();
    }

    public function test_an_empty_inbox_says_so(): void
    {
        $this->get(route('panel.inbox.index'))->assertSee('No messages');
    }

    // ------------------------------------------------------------ newsletter

    public function test_subscribers_are_listed_with_those_who_left_marked_as_such(): void
    {
        NewsletterSubscriber::factory()->create(['email' => 'in@example.com']);
        NewsletterSubscriber::factory()->unsubscribed()->create(['email' => 'out@example.com']);

        $response = $this->get(route('panel.inbox.subscribers'))->assertOk()->assertSee('in@example.com')->assertSee('out@example.com')->assertSee('Unsubscribed');

        $this->assertSame(1, $response->viewData('active'));
    }

    public function test_subscribers_can_be_searched_by_address(): void
    {
        NewsletterSubscriber::factory()->create(['email' => 'findme@example.com']);
        NewsletterSubscriber::factory()->create(['email' => 'other@example.com']);

        $this->get(route('panel.inbox.subscribers', ['q' => 'findme']))->assertSee('findme@example.com')->assertDontSee('other@example.com');
    }

    public function test_a_subscriber_can_be_removed(): void
    {
        $subscriber = NewsletterSubscriber::factory()->create(['email' => 'remove@example.com']);

        $this->delete(route('panel.inbox.subscribers.destroy', $subscriber))->assertSessionHas('status');

        $this->assertNull(NewsletterSubscriber::query()->find($subscriber->id));
        $this->assertDatabaseHas('activity_logs', ['action' => 'inbox.subscriber_removed', 'subject_label' => 'remove@example.com']);
    }

    public function test_the_download_has_only_those_still_subscribed_and_cannot_carry_a_formula(): void
    {
        NewsletterSubscriber::factory()->create(['email' => '=1+1@example.com', 'locale' => 'en']);
        NewsletterSubscriber::factory()->create(['email' => 'fine@example.com']);
        NewsletterSubscriber::factory()->unsubscribed()->create(['email' => 'gone@example.com']);

        $content = $this->get(route('panel.inbox.subscribers.export'))->assertOk()->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $this->assertStringContainsString("'=1+1@example.com", $content);
        $this->assertStringContainsString('fine@example.com', $content);
        $this->assertStringNotContainsString('gone@example.com', $content);
    }

    public function test_signing_up_again_after_leaving_puts_someone_back_on_the_list(): void
    {
        NewsletterSubscriber::factory()->unsubscribed()->create(['email' => 'back@example.com']);

        app(Inbox::class)->subscribe('Back@Example.com');

        $this->get(route('panel.inbox.subscribers'))->assertSee('Subscribed');
        $this->assertNull(NewsletterSubscriber::query()->where('email', 'back@example.com')->firstOrFail()->unsubscribed_at);
    }
}
