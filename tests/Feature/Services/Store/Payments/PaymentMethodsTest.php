<?php

namespace Tests\Feature\Services\Store\Payments;

use App\Services\Store\Payments\PaymentMethods;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesMyFatoorah;
use Tests\TestCase;

class PaymentMethodsTest extends TestCase
{
    use FakesMyFatoorah;

    private function methods(): PaymentMethods
    {
        return $this->app->make(PaymentMethods::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_nothing_is_offered_while_online_payment_is_not_configured(): void
    {
        config(['myfatoorah.api_key' => null]);
        Http::preventStrayRequests();

        $this->assertSame([], $this->methods()->enabled());
        $this->assertFalse($this->methods()->offers('KNET'));
    }

    public function test_the_enabled_methods_the_shop_can_use_are_offered_in_a_fixed_order_with_arabic_names(): void
    {
        $this->configureMyFatoorah();
        $this->fakeMyFatoorah();
        app()->setLocale('ar');

        $options = $this->methods()->enabled();

        // MADA is enabled on the account but is not a method a payment can be created with.
        $this->assertSame(['KNET', 'CARD', 'APPLE_PAY'], array_column($options, 'id'));
        $this->assertSame(['كي نت', 'بطاقة (فيزا / ماستركارد)', 'أبل باي'], array_column($options, 'label'));
        $this->assertTrue($this->methods()->offers('KNET'));
        $this->assertFalse($this->methods()->offers('MADA'));
        $this->assertFalse($this->methods()->offers('GOOGLE_PAY'));
    }

    public function test_the_names_come_in_english_for_english_shoppers(): void
    {
        $this->configureMyFatoorah();
        $this->fakeMyFatoorah();
        app()->setLocale('en');

        $this->assertSame(['KNET', 'Card (Visa / Mastercard)', 'Apple Pay'], array_column($this->methods()->enabled(), 'label'));
    }

    public function test_the_list_is_read_once_and_remembered(): void
    {
        $this->configureMyFatoorah();
        $this->fakeMyFatoorah();

        $this->methods()->enabled();
        $this->methods()->enabled();
        $this->methods()->offers('KNET');

        Http::assertSentCount(1);
    }

    public function test_a_key_that_may_not_list_methods_gets_one_plain_online_choice_that_lets_my_fatoorahs_page_decide(): void
    {
        $this->configureMyFatoorah();
        $this->fakeMyFatoorah([self::MF.'/v3/payment-methods' => Http::response(['IsSuccess' => false, 'Message' => 'The token does not have the required permissions!'], 401)]);
        app()->setLocale('en');

        $options = $this->methods()->enabled();

        $this->assertSame([['id' => 'all', 'type' => 'online', 'label' => 'Card, KNET and other online methods']], $options);
        $this->assertTrue($this->methods()->offers('all'));
        $this->assertFalse($this->methods()->offers('KNET'));
    }

    public function test_the_plain_choice_also_covers_my_fatoorah_being_down_and_an_account_with_no_usable_method(): void
    {
        $this->configureMyFatoorah();
        $this->fakeMyFatoorah([self::MF.'/v3/payment-methods' => Http::failedConnection()]);
        $this->assertSame(['all'], array_column($this->methods()->enabled(), 'id'));

        Cache::flush();
        $this->fakeMyFatoorah([self::MF.'/v3/payment-methods' => Http::response(['IsSuccess' => true, 'Data' => ['PaymentMethods' => [['Name' => 'MADA', 'ApiName' => 'MADA']]]])]);
        $this->assertSame(['all'], array_column($this->methods()->enabled(), 'id'));
    }

    public function test_a_list_that_could_not_be_read_is_asked_for_again_soon_not_hours_later(): void
    {
        $this->configureMyFatoorah();
        // The first answer is a refusal; every later one is the real list.
        $this->fakeMyFatoorah([self::MF.'/v3/payment-methods' => Http::sequence()
            ->push(['IsSuccess' => false, 'Message' => 'no'], 401)
            ->push($this->fixture('myfatoorah/payment-methods'))]);

        $this->assertSame(['all'], array_column($this->methods()->enabled(), 'id'));

        $this->travel(5)->minutes();
        $this->assertSame(['all'], array_column($this->methods()->enabled(), 'id'));
        Http::assertSentCount(1);

        $this->travel(6)->minutes();
        $this->assertSame(['KNET', 'CARD', 'APPLE_PAY'], array_column($this->methods()->enabled(), 'id'));
        Http::assertSentCount(2);
    }
}
