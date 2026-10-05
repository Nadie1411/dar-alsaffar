<?php

namespace Tests\Feature\Services\Store;

use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\DeliveryArea;
use App\Models\DeliveryCity;
use App\Notifications\CustomerResetPassword;
use App\Services\Store\LocalCustomers;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Session;
use Tests\Concerns\UsesLocalStore;
use Tests\TestCase;

class LocalCustomersTest extends TestCase
{
    use LazilyRefreshDatabase, UsesLocalStore;

    private function customers(): LocalCustomers
    {
        return $this->app->make(LocalCustomers::class);
    }

    /**
     * @param  array<string,string>  $overrides
     * @return array{fullName:string,email:string,password:string,phoneNumber:string}
     */
    private function registration(array $overrides = []): array
    {
        return $overrides + [
            'fullName' => 'Sara Al-Ahmad',
            'email' => 'Sara@Example.com',
            'password' => 'correct horse battery',
            'phoneNumber' => '+96551234567',
        ];
    }

    public function test_registering_creates_the_account_signs_the_shopper_in_and_stores_a_hashed_password(): void
    {
        $result = $this->customers()->register($this->registration());

        $customer = Customer::query()->firstOrFail();
        $this->assertSame(['ok' => true, 'message' => null, 'authenticated' => true], $result);
        $this->assertSame(['Sara Al-Ahmad', 'sara@example.com', '+96551234567'], [$customer->name, $customer->email, $customer->phone]);
        $this->assertNotSame('correct horse battery', $customer->password);
        $this->assertTrue(Hash::check('correct horse battery', $customer->password));
        $this->assertTrue(Auth::guard('customer')->check());
    }

    public function test_an_email_that_is_already_registered_is_refused_whatever_its_case(): void
    {
        Customer::factory()->create(['email' => 'sara@example.com']);

        $result = $this->customers()->register($this->registration(['email' => 'SARA@EXAMPLE.COM']));

        $this->assertFalse($result['ok']);
        $this->assertSame('Could not create the account, please check your details.', $result['message']);
        $this->assertDatabaseCount('customers', 1);
        $this->assertFalse(Auth::guard('customer')->check());
    }

    public function test_signing_in_works_whatever_the_case_of_the_email_and_fails_on_a_wrong_password(): void
    {
        Customer::factory()->create(['email' => 'sara@example.com', 'password' => 'correct horse battery']);

        $wrong = $this->customers()->login('sara@example.com', 'nope');
        $this->assertFalse(Auth::guard('customer')->check());
        $unknown = $this->customers()->login('nobody@example.com', 'correct horse battery');
        $right = $this->customers()->login('  SARA@example.com ', 'correct horse battery');

        $this->assertSame(['ok' => false, 'message' => 'Incorrect email or password.'], $wrong);
        $this->assertSame(['ok' => false, 'message' => 'Incorrect email or password.'], $unknown);
        $this->assertSame(['ok' => true, 'message' => null], $right);
        $this->assertTrue(Auth::guard('customer')->check());
    }

    public function test_signing_out_keeps_the_basket_but_drops_a_guests_saved_list(): void
    {
        $customer = Customer::factory()->create();
        $this->actingAs($customer, 'customer');
        Session::put('cart.items', ['a' => ['productId' => '1', 'quantity' => 1]]);
        Session::put('wishlist.ids', ['5' => '5']);

        $this->customers()->logout();

        $this->assertFalse(Auth::guard('customer')->check());
        $this->assertSame(['a' => ['productId' => '1', 'quantity' => 1]], Session::get('cart.items'));
        $this->assertNull(Session::get('wishlist.ids'));
    }

    public function test_the_profile_name_can_be_changed(): void
    {
        $customer = Customer::factory()->create(['name' => 'Old Name']);
        $this->actingAs($customer, 'customer');

        $result = $this->customers()->updateProfile(['fullName' => '  New Name ']);

        $this->assertSame(['ok' => true, 'message' => null], $result);
        $this->assertSame('New Name', $customer->fresh()->name);
    }

    public function test_addresses_list_newest_first_in_the_shoppers_language_and_are_empty_for_a_guest(): void
    {
        $customer = Customer::factory()->create();
        $city = DeliveryCity::factory()->create(['name_en' => 'Hawalli', 'name_ar' => 'حولي']);
        $area = DeliveryArea::factory()->for($city, 'city')->create(['name_en' => 'Salmiya', 'name_ar' => 'السالمية']);
        CustomerAddress::factory()->for($customer)->create(['delivery_city_id' => $city->id, 'delivery_area_id' => $area->id, 'street' => 'Older street']);
        CustomerAddress::factory()->for($customer)->create(['delivery_city_id' => $city->id, 'delivery_area_id' => $area->id, 'street' => 'Newer street']);
        CustomerAddress::factory()->create();
        $this->assertSame([], $this->customers()->addresses());
        $this->actingAs($customer, 'customer');

        $addresses = $this->customers()->addresses();

        $this->assertSame(['Newer street', 'Older street'], array_column($addresses, 'street'));
        $this->assertSame(['name' => ['ar' => 'حولي', 'en' => 'Hawalli']], $addresses[0]['city']);
        $this->assertSame(['name' => ['ar' => 'السالمية', 'en' => 'Salmiya']], $addresses[0]['area']);
    }

    public function test_asking_to_reset_a_password_sends_the_customer_a_link_and_says_nothing_about_unknown_emails(): void
    {
        Notification::fake();
        $customer = Customer::factory()->create(['email' => 'sara@example.com']);

        $known = $this->customers()->forgotPassword(' Sara@Example.com ');
        $unknown = $this->customers()->forgotPassword('nobody@example.com');

        $this->assertSame(['ok' => true, 'message' => null], $known);
        $this->assertSame(['ok' => true, 'message' => null], $unknown);
        Notification::assertSentTo($customer, CustomerResetPassword::class);
        Notification::assertCount(1);
    }

    public function test_the_reset_email_links_to_the_storefronts_own_page_with_the_token_and_email(): void
    {
        $customer = Customer::factory()->create(['name' => 'Sara', 'email' => 'sara@example.com']);
        app()->setLocale('en');

        $mail = (new CustomerResetPassword('tok123'))->toMail($customer);

        $this->assertSame('Reset your Dar Alsaffar password', $mail->subject);
        $this->assertSame('Hello Sara,', $mail->greeting);
        $this->assertStringContainsString('/reset-password/tok123?email=sara%40example.com', $mail->actionUrl);
        $this->assertSame('Choose a new password', $mail->actionText);
    }
}
