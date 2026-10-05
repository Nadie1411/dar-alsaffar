<?php

namespace Tests\Feature\Services\Store\Payments;

use App\Models\GatewayCredential;
use App\Services\Store\Payments\GatewayConfig;
use App\Services\Store\Payments\MyFatoorahClient;
use App\Services\Store\Payments\PaymentMethods;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\FakesMyFatoorah;
use Tests\TestCase;

class GatewayConfigTest extends TestCase
{
    use FakesMyFatoorah, LazilyRefreshDatabase;

    private const PANEL_KEY = 'PANEL-SAVED-KEY-MARKER-0001';

    private const PANEL_SECRET = 'PANEL-SAVED-SECRET-MARKER-0002';

    private function gateway(): GatewayConfig
    {
        return $this->app->make(GatewayConfig::class);
    }

    // ----------------------------------------------------------- precedence

    public function test_with_nothing_saved_in_the_panel_everything_comes_from_the_servers_setting(): void
    {
        config([
            'myfatoorah.api_key' => 'env-key', 'myfatoorah.webhook_secret' => 'env-secret',
            'myfatoorah.api_url' => 'https://api.myfatoorah.com', 'myfatoorah.enabled' => true,
        ]);

        $gateway = $this->gateway();

        $this->assertSame('env-key', $gateway->apiKey());
        $this->assertSame('env-secret', $gateway->webhookSecret());
        $this->assertSame('https://api.myfatoorah.com', $gateway->apiUrl());
        $this->assertTrue($gateway->enabled());
        $this->assertSame(GatewayConfig::SOURCE_ENVIRONMENT, $gateway->keySource());
        $this->assertSame(GatewayConfig::SOURCE_ENVIRONMENT, $gateway->secretSource());
        $this->assertSame(GatewayConfig::SOURCE_ENVIRONMENT, $gateway->urlSource());
        $this->assertSame(GatewayConfig::SOURCE_ENVIRONMENT, $gateway->enabledSource());
    }

    public function test_with_nothing_anywhere_the_key_is_empty_and_the_source_says_so(): void
    {
        config(['myfatoorah.api_key' => '', 'myfatoorah.webhook_secret' => null]);

        $this->assertSame('', $this->gateway()->apiKey());
        $this->assertSame(GatewayConfig::SOURCE_NONE, $this->gateway()->keySource());
        $this->assertSame(GatewayConfig::SOURCE_NONE, $this->gateway()->secretSource());
    }

    public function test_what_an_owner_saved_in_the_panel_wins_over_the_server(): void
    {
        config([
            'myfatoorah.api_key' => 'env-key', 'myfatoorah.webhook_secret' => 'env-secret',
            'myfatoorah.api_url' => 'https://api.myfatoorah.com', 'myfatoorah.enabled' => true,
        ]);
        GatewayCredential::factory()->create([
            'api_key' => self::PANEL_KEY, 'webhook_secret' => self::PANEL_SECRET,
            'api_url' => 'https://apitest.myfatoorah.com', 'enabled' => false,
        ]);

        $gateway = $this->gateway();

        $this->assertSame(self::PANEL_KEY, $gateway->apiKey());
        $this->assertSame(self::PANEL_SECRET, $gateway->webhookSecret());
        $this->assertSame('https://apitest.myfatoorah.com', $gateway->apiUrl());
        $this->assertFalse($gateway->enabled());
        $this->assertSame(GatewayConfig::SOURCE_PANEL, $gateway->keySource());
        $this->assertSame(GatewayConfig::SOURCE_PANEL, $gateway->secretSource());
        $this->assertSame(GatewayConfig::SOURCE_PANEL, $gateway->urlSource());
        $this->assertSame(GatewayConfig::SOURCE_PANEL, $gateway->enabledSource());
    }

    public function test_each_value_falls_back_to_the_server_on_its_own(): void
    {
        config(['myfatoorah.api_key' => 'env-key', 'myfatoorah.webhook_secret' => 'env-secret', 'myfatoorah.api_url' => 'https://api.myfatoorah.com']);
        GatewayCredential::factory()->create(['api_key' => self::PANEL_KEY, 'webhook_secret' => null, 'api_url' => null, 'enabled' => null]);

        $gateway = $this->gateway();

        $this->assertSame(self::PANEL_KEY, $gateway->apiKey());
        $this->assertSame('env-secret', $gateway->webhookSecret());
        $this->assertSame('https://api.myfatoorah.com', $gateway->apiUrl());
        $this->assertSame(GatewayConfig::SOURCE_ENVIRONMENT, $gateway->secretSource());
    }

    public function test_an_address_that_is_not_on_the_list_is_never_used_even_if_it_somehow_got_saved(): void
    {
        config(['myfatoorah.api_url' => 'https://api.myfatoorah.com']);
        $credential = GatewayCredential::factory()->create();
        DB::table('gateway_credentials')->where('id', $credential->id)->update(['api_url' => 'https://evil.example']);

        $this->assertSame('https://api.myfatoorah.com', $this->gateway()->apiUrl());
        $this->assertSame(GatewayConfig::SOURCE_ENVIRONMENT, $this->gateway()->urlSource());
    }

    public function test_the_switch_saved_in_the_panel_overrides_the_server_either_way(): void
    {
        config(['myfatoorah.enabled' => false]);
        GatewayCredential::factory()->create(['enabled' => true]);
        $this->assertTrue($this->gateway()->enabled());

        config(['myfatoorah.enabled' => true]);
        GatewayCredential::query()->update(['enabled' => false]);
        $this->gateway()->refresh();
        $this->assertFalse($this->gateway()->enabled());

        GatewayCredential::query()->update(['enabled' => null]);
        $this->gateway()->refresh();
        $this->assertTrue($this->gateway()->enabled(), 'null follows the server');
    }

    // ----------------------------------------------------------- at rest

    public function test_the_secrets_are_encrypted_in_the_database_and_nowhere_in_the_clear(): void
    {
        GatewayCredential::factory()->create(['api_key' => self::PANEL_KEY, 'webhook_secret' => self::PANEL_SECRET]);

        $row = (array) DB::table('gateway_credentials')->first();

        $this->assertStringNotContainsString(self::PANEL_KEY, json_encode($row));
        $this->assertStringNotContainsString(self::PANEL_SECRET, json_encode($row));
        $this->assertNotSame(self::PANEL_KEY, $row['api_key']);
        $this->assertSame(self::PANEL_KEY, GatewayCredential::query()->firstOrFail()->api_key, 'the model still reads it back');
    }

    public function test_the_model_never_serialises_a_secret(): void
    {
        $credential = GatewayCredential::factory()->create(['api_key' => self::PANEL_KEY, 'webhook_secret' => self::PANEL_SECRET]);

        $this->assertStringNotContainsString(self::PANEL_KEY, $credential->toJson());
        $this->assertStringNotContainsString(self::PANEL_SECRET, $credential->toJson());
        $this->assertArrayNotHasKey('api_key', $credential->toArray());
        $this->assertArrayNotHasKey('webhook_secret', $credential->toArray());
    }

    public function test_a_secret_that_can_no_longer_be_decrypted_is_reported_and_the_server_setting_takes_over(): void
    {
        config(['myfatoorah.api_key' => 'env-key']);
        $credential = GatewayCredential::factory()->create(['webhook_secret' => null]);

        // Written under a different application key, as if APP_KEY had been changed.
        $other = new Encrypter(random_bytes(32), config('app.cipher'));
        DB::table('gateway_credentials')->where('id', $credential->id)->update(['api_key' => $other->encryptString(self::PANEL_KEY)]);

        $gateway = $this->gateway();

        $this->assertSame('env-key', $gateway->apiKey(), 'no crash, and the server\'s key is used');
        $this->assertTrue($gateway->hasUnreadableSecret());
        $this->assertSame(GatewayConfig::SOURCE_ENVIRONMENT, $gateway->keySource());
    }

    public function test_when_the_table_does_not_exist_yet_the_server_setting_is_used_without_an_error(): void
    {
        config(['myfatoorah.api_key' => 'env-key']);
        Schema::drop('gateway_credentials');

        $this->assertSame('env-key', $this->gateway()->apiKey());
        $this->assertTrue($this->gateway()->enabled());
    }

    public function test_a_change_is_seen_after_refresh_and_not_before(): void
    {
        config(['myfatoorah.api_key' => 'env-key']);
        $gateway = $this->gateway();
        $this->assertSame('env-key', $gateway->apiKey());

        GatewayCredential::factory()->create(['api_key' => self::PANEL_KEY]);
        $this->assertSame('env-key', $gateway->apiKey(), 'remembered for the length of the request');

        $gateway->refresh();
        $this->assertSame(self::PANEL_KEY, $gateway->apiKey());
    }

    // --------------------------------------------------- the gateway calls

    public function test_calls_to_my_fatoorah_use_the_key_and_address_saved_in_the_panel(): void
    {
        config(['myfatoorah.enabled' => true, 'myfatoorah.api_key' => 'env-key', 'myfatoorah.api_url' => 'https://api.myfatoorah.com']);
        GatewayCredential::factory()->sandbox()->create(['api_key' => self::PANEL_KEY]);
        $this->fakeMyFatoorah();

        $methods = $this->app->make(MyFatoorahClient::class)->paymentMethods();

        $this->assertNotEmpty($methods);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://apitest.myfatoorah.com/v3/payment-methods'
            && $request->hasHeader('Authorization', 'Bearer '.self::PANEL_KEY));
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'https://api.myfatoorah.com'));
    }

    public function test_calls_still_use_the_server_setting_when_the_panel_has_nothing_saved(): void
    {
        $this->configureMyFatoorah();
        $this->fakeMyFatoorah();

        $this->app->make(MyFatoorahClient::class)->paymentMethods();

        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer '.self::MF_KEY));
    }

    public function test_the_gateway_is_not_called_at_all_when_the_panel_switches_it_off(): void
    {
        $this->configureMyFatoorah();
        GatewayCredential::factory()->switchedOff()->create();
        Http::preventStrayRequests();

        $client = $this->app->make(MyFatoorahClient::class);

        $this->assertFalse($client->isConfigured());
        $this->assertSame([], $this->app->make(PaymentMethods::class)->enabled());
    }

    public function test_a_key_saved_in_the_panel_switches_online_payment_on_even_with_nothing_in_the_environment(): void
    {
        config(['myfatoorah.api_key' => '', 'myfatoorah.enabled' => true, 'myfatoorah.api_url' => 'https://api.myfatoorah.com']);
        $this->assertFalse($this->app->make(MyFatoorahClient::class)->isConfigured());

        GatewayCredential::factory()->create(['api_key' => self::PANEL_KEY]);
        $this->gateway()->refresh();

        $this->assertTrue($this->app->make(MyFatoorahClient::class)->isConfigured());
    }
}
