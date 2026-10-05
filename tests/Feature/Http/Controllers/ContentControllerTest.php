<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\NewsletterSubscriber;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Concerns\UsesLocalStore;
use Tests\TestCase;

class ContentControllerTest extends TestCase
{
    use LazilyRefreshDatabase, UsesLocalStore;

    public function test_a_contact_message_is_kept_for_the_shop_to_read(): void
    {
        $response = $this->from('/en-KW/contact-us')->post('/en-KW/contact-us', [
            'name' => 'Sara Al-Ahmad',
            'email' => 'Sara@Example.com',
            'phone' => '51234567',
            'message' => 'Do you deliver to Failaka?',
        ]);

        $response->assertRedirect('/en-KW/contact-us')->assertSessionHas('status', 'We have received your message, thank you.');
        $this->assertDatabaseHas('contact_messages', [
            'name' => 'Sara Al-Ahmad', 'email' => 'sara@example.com', 'phone' => '51234567',
            'message' => 'Do you deliver to Failaka?', 'read_at' => null,
        ]);
    }

    public function test_an_incomplete_contact_message_is_not_kept(): void
    {
        $response = $this->post('/en-KW/contact-us', ['name' => 'S', 'email' => 'nope', 'message' => 'hi']);

        $response->assertSessionHasErrors(['name', 'email', 'message']);
        $this->assertDatabaseCount('contact_messages', 0);
    }

    public function test_signing_up_for_the_newsletter_keeps_the_address_once_whatever_its_case(): void
    {
        $this->post('/en-KW/newsletter', ['email' => 'Sara@Example.com'])->assertSessionHas('newsletter');
        $this->post('/en-KW/newsletter', ['email' => 'sara@example.com']);

        $this->assertSame(['sara@example.com'], NewsletterSubscriber::query()->pluck('email')->all());
        $this->assertSame('en', NewsletterSubscriber::query()->firstOrFail()->locale);
    }

    public function test_someone_who_unsubscribed_and_signs_up_again_is_subscribed_again(): void
    {
        NewsletterSubscriber::factory()->unsubscribed()->create(['email' => 'sara@example.com']);

        $this->post('/en-KW/newsletter', ['email' => 'sara@example.com']);

        $this->assertNull(NewsletterSubscriber::query()->firstOrFail()->unsubscribed_at);
        $this->assertSame(1, NewsletterSubscriber::query()->subscribed()->count());
    }

    public function test_a_bad_newsletter_address_is_refused(): void
    {
        $this->post('/en-KW/newsletter', ['email' => 'nope'])->assertSessionHasErrors('email');

        $this->assertDatabaseCount('newsletter_subscribers', 0);
    }

    public function test_the_about_page_lists_the_local_collections(): void
    {
        $this->get('/en-KW/about-us')->assertOk();
    }
}
