<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\Customer;
use App\Notifications\CustomerResetPassword;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\UsesLocalStore;
use Tests\TestCase;

class AuthControllerTest extends TestCase
{
    use LazilyRefreshDatabase, UsesLocalStore;

    /**
     * @param  array<string,string>  $overrides
     * @return array<string,string>
     */
    private function signUp(array $overrides = []): array
    {
        return $overrides + [
            'fullName' => 'Sara Al-Ahmad',
            'email' => 'sara@example.com',
            'phone' => '51234567',
            'password' => 'correct horse battery',
        ];
    }

    public function test_signing_up_creates_the_account_and_signs_the_shopper_in(): void
    {
        $response = $this->post('/en-KW/register', $this->signUp());

        $response->assertRedirect('/en-KW/account')->assertSessionHas('status', 'Your account has been created.');
        $customer = Customer::query()->firstOrFail();
        $this->assertSame(['Sara Al-Ahmad', 'sara@example.com', '+96551234567'], [$customer->name, $customer->email, $customer->phone]);
        $this->assertAuthenticatedAs($customer, 'customer');
    }

    public function test_signing_up_checks_the_details(): void
    {
        $response = $this->post('/en-KW/register', $this->signUp(['email' => 'not-an-email', 'phone' => '012', 'password' => 'short']));

        $response->assertSessionHasErrors(['email', 'phone', 'password']);
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_signing_up_with_an_email_already_in_use_is_refused(): void
    {
        Customer::factory()->create(['email' => 'sara@example.com']);

        $response = $this->post('/en-KW/register', $this->signUp());

        $response->assertSessionHasErrors(['email' => 'Could not create the account, please check your details.']);
        $this->assertDatabaseCount('customers', 1);
    }

    public function test_signing_in_returns_the_shopper_to_the_page_that_asked_for_it(): void
    {
        $customer = Customer::factory()->create(['email' => 'sara@example.com', 'password' => 'correct horse battery']);

        $this->get('/en-KW/account/orders')->assertRedirect('/en-KW/login');
        $response = $this->post('/en-KW/login', ['email' => 'sara@example.com', 'password' => 'correct horse battery']);

        $response->assertRedirectContains('/en-KW/account/orders');
        $this->assertAuthenticatedAs($customer, 'customer');
    }

    public function test_a_wrong_password_shows_one_message_whether_or_not_the_email_exists(): void
    {
        Customer::factory()->create(['email' => 'sara@example.com']);

        $wrongPassword = $this->post('/en-KW/login', ['email' => 'sara@example.com', 'password' => 'nope']);
        $unknownEmail = $this->post('/en-KW/login', ['email' => 'nobody@example.com', 'password' => 'nope']);

        $wrongPassword->assertSessionHasErrors(['email' => 'Incorrect email or password.']);
        $unknownEmail->assertSessionHasErrors(['email' => 'Incorrect email or password.']);
        $this->assertGuest('customer');
    }

    public function test_signing_out_ends_the_session_but_not_the_basket(): void
    {
        $customer = Customer::factory()->create();

        $response = $this->actingAs($customer, 'customer')
            ->withSession(['cart.items' => ['line' => ['productId' => '1', 'quantity' => 1]]])
            ->post('/en-KW/logout');

        $response->assertRedirect('/en-KW')->assertSessionHas('cart.items');
        $this->assertGuest('customer');
    }

    public function test_the_account_pages_are_closed_to_guests_and_signed_in_customers_skip_the_login_form(): void
    {
        $this->get('/en-KW/account')->assertRedirect('/en-KW/login');
        $this->actingAs(Customer::factory()->create(), 'customer');

        $this->get('/en-KW/login')->assertRedirect('/en-KW/account');
        $this->get('/en-KW/register')->assertRedirect('/en-KW/account');
        $this->get('/en-KW/account')->assertOk();
    }

    public function test_asking_for_a_reset_sends_an_email_and_gives_the_same_answer_for_any_address(): void
    {
        Notification::fake();
        $customer = Customer::factory()->create(['email' => 'sara@example.com']);

        $known = $this->from('/en-KW/forgot-password')->post('/en-KW/forgot-password', ['email' => 'sara@example.com']);
        $unknown = $this->from('/en-KW/forgot-password')->post('/en-KW/forgot-password', ['email' => 'nobody@example.com']);

        $known->assertSessionHas('status', 'If that email is registered, a reset message is on its way.');
        $unknown->assertSessionHas('status', 'If that email is registered, a reset message is on its way.');
        Notification::assertSentTo($customer, CustomerResetPassword::class);
        Notification::assertCount(1);
    }

    public function test_a_customer_can_change_their_name_and_see_their_details(): void
    {
        $customer = Customer::factory()->create(['name' => 'Old Name', 'email' => 'sara@example.com', 'phone' => '+96551234567']);
        $this->actingAs($customer, 'customer');

        $this->get('/en-KW/account/settings')->assertOk()->assertSee('Old Name')->assertSee('sara@example.com');
        $response = $this->from('/en-KW/account/settings')->patch('/en-KW/account/settings', ['fullName' => 'New Name']);

        $response->assertSessionHas('status', 'Changes saved.');
        $this->assertSame('New Name', $customer->fresh()->name);
    }
}
