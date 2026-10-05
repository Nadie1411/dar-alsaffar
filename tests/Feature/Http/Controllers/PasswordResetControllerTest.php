<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\Customer;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\Concerns\UsesLocalStore;
use Tests\TestCase;

class PasswordResetControllerTest extends TestCase
{
    use LazilyRefreshDatabase, UsesLocalStore;

    public function test_the_reset_page_shows_the_form_with_the_token_and_email_filled_in(): void
    {
        $response = $this->get('/en-KW/reset-password/tok123?email=sara%40example.com');

        $response->assertOk()
            ->assertSee('Choose a new password')
            ->assertSee('name="token" value="tok123"', false)
            ->assertSee('value="sara@example.com"', false);
    }

    public function test_a_valid_token_sets_the_new_password_and_sends_the_customer_to_sign_in(): void
    {
        $customer = Customer::factory()->create(['email' => 'sara@example.com', 'password' => 'old password 1']);
        $token = Password::broker('customers')->createToken($customer);

        $response = $this->post('/en-KW/reset-password', [
            'token' => $token,
            'email' => 'Sara@Example.com',
            'password' => 'brand new password',
            'password_confirmation' => 'brand new password',
        ]);

        $response->assertRedirect('/en-KW/login')->assertSessionHas('status', 'Your password has been changed. Please sign in.');
        $this->assertTrue(Hash::check('brand new password', $customer->fresh()->password));
    }

    public function test_a_reset_link_works_once_only(): void
    {
        $customer = Customer::factory()->create(['email' => 'sara@example.com']);
        $token = Password::broker('customers')->createToken($customer);
        $payload = ['token' => $token, 'email' => 'sara@example.com', 'password' => 'brand new password', 'password_confirmation' => 'brand new password'];
        $this->post('/en-KW/reset-password', $payload);

        $again = $this->post('/en-KW/reset-password', ['password' => 'a different password', 'password_confirmation' => 'a different password'] + $payload);

        $again->assertSessionHasErrors(['email' => 'This reset link is invalid or has expired. Please request a new one.']);
        $this->assertTrue(Hash::check('brand new password', $customer->fresh()->password));
    }

    public function test_a_wrong_token_changes_nothing(): void
    {
        $customer = Customer::factory()->create(['email' => 'sara@example.com', 'password' => 'old password 1']);

        $response = $this->post('/en-KW/reset-password', [
            'token' => 'not-a-real-token',
            'email' => 'sara@example.com',
            'password' => 'brand new password',
            'password_confirmation' => 'brand new password',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('old password 1', $customer->fresh()->password));
    }

    public function test_the_new_password_must_be_long_enough_and_confirmed(): void
    {
        $customer = Customer::factory()->create(['email' => 'sara@example.com']);
        $token = Password::broker('customers')->createToken($customer);

        $short = $this->post('/en-KW/reset-password', ['token' => $token, 'email' => 'sara@example.com', 'password' => 'short', 'password_confirmation' => 'short']);
        $mismatch = $this->post('/en-KW/reset-password', ['token' => $token, 'email' => 'sara@example.com', 'password' => 'long enough pw', 'password_confirmation' => 'different pw']);

        $short->assertSessionHasErrors('password');
        $mismatch->assertSessionHasErrors('password');
    }

    public function test_the_pages_do_not_exist_while_overzaki_holds_the_accounts(): void
    {
        config(['store.backend' => 'overzaki']);

        $this->get('/en-KW/reset-password/tok123')->assertNotFound();
        $this->post('/en-KW/reset-password', ['token' => 'x', 'email' => 'a@b.co', 'password' => 'long enough pw', 'password_confirmation' => 'long enough pw'])->assertNotFound();
    }

    public function test_signing_in_is_throttled_after_five_attempts_for_the_same_email_and_address(): void
    {
        Customer::factory()->create(['email' => 'sara@example.com']);

        foreach (range(1, 5) as $attempt) {
            $this->post('/en-KW/login', ['email' => 'sara@example.com', 'password' => 'wrong '.$attempt])->assertSessionHasErrors('email');
        }

        $this->post('/en-KW/login', ['email' => 'sara@example.com', 'password' => 'wrong 6'])->assertStatus(429);
        // A different email from the same address is counted separately.
        $this->post('/en-KW/login', ['email' => 'other@example.com', 'password' => 'wrong'])->assertSessionHasErrors('email');
    }
}
