<?php

namespace Tests\Feature\Http\Controllers\Panel;

use App\Models\ActivityLog;
use App\Models\GatewayCredential;
use App\Models\User;
use App\Services\Store\Payments\GatewayConfig;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\FakesMyFatoorah;
use Tests\Concerns\SignsInStaff;
use Tests\TestCase;

/**
 * Where an owner sets up the Apple Pay button on the shop's own page: the
 * verification file, telling MyFatoorah about the domain, and the switch.
 */
class ApplePayGatewayPanelTest extends TestCase
{
    use FakesMyFatoorah, LazilyRefreshDatabase, SignsInStaff;

    private const PASSWORD = 'Owner-pass-12345';

    private const FILE = '7B227073704964223A22353439';

    private const SHOP = 'https://daralsaffar.shop';

    private const REGISTER = 'https://apitest.myfatoorah.com/v2/RegisterApplePayDomain';

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = $this->signInAs('owner', ['password' => self::PASSWORD]);
        $this->configureMyFatoorah();
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function form(array $overrides = []): array
    {
        return array_merge(['environment' => 'sandbox', 'enabled' => '1', 'current_password' => self::PASSWORD], $overrides);
    }

    private function saved(): GatewayCredential
    {
        return GatewayCredential::query()->firstOrFail();
    }

    private function registerAt(string $shop = self::SHOP): TestResponse
    {
        return $this->post($shop.route('panel.payments.gateway.apple-pay.register', absolute: false));
    }

    private function accepted(): void
    {
        $this->fakeMyFatoorah([self::REGISTER => Http::response(['IsSuccess' => true, 'Message' => 'OK', 'ValidationErrors' => null, 'Data' => null])]);
    }

    // ------------------------------------------------------------------ page

    public function test_the_page_walks_the_owner_through_the_steps_with_this_shops_own_addresses(): void
    {
        $this->get($this->shopUrl('panel.payments.gateway.edit'))
            ->assertOk()
            ->assertSee('Apple Pay on the site')
            ->assertSee('tech@myfatoorah.com')
            ->assertSee(url('/.well-known/apple-developer-merchantid-domain-association'))
            ->assertSee('Register daralsaffar.shop')
            ->assertSee('Show the Apple Pay button on the site');
    }

    private function shopUrl(string $route): string
    {
        return self::SHOP.route($route, absolute: false);
    }

    public function test_only_an_owner_can_register_the_domain(): void
    {
        $this->fakeMyFatoorah();
        GatewayCredential::factory()->create(['apple_pay_domain_file' => self::FILE]);

        foreach (['manager', 'staff'] as $role) {
            $this->signInAs($role);

            $this->registerAt()->assertForbidden();
        }

        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------ file

    public function test_the_file_is_saved_as_pasted_with_plain_line_breaks_and_the_site_then_serves_it(): void
    {
        $this->put(route('panel.payments.gateway.update'), $this->form(['apple_pay_domain_file' => "AAAA\r\nBBBB"]))
            ->assertRedirect(route('panel.payments.index'));

        $this->assertSame("AAAA\nBBBB", $this->saved()->apple_pay_domain_file);
        $this->startNewRequest();
        $this->get('/.well-known/apple-developer-merchantid-domain-association')->assertOk()->assertSee('AAAA');
    }

    public function test_something_that_is_not_plain_text_is_refused_and_nothing_is_saved(): void
    {
        $this->put(route('panel.payments.gateway.update'), $this->form(['apple_pay_domain_file' => 'ملف']))
            ->assertSessionHasErrors('apple_pay_domain_file');

        $this->assertDatabaseCount('gateway_credentials', 0);
    }

    public function test_the_activity_log_says_the_file_changed_never_what_it_says(): void
    {
        $this->put(route('panel.payments.gateway.update'), $this->form(['apple_pay_domain_file' => self::FILE]));

        $log = ActivityLog::query()->where('action', 'payment.credentials_updated')->firstOrFail();

        $this->assertSame('changed', $log->properties['apple_pay_domain_file']);
        $this->assertStringNotContainsString(self::FILE, json_encode($log->properties));
    }

    // -------------------------------------------------------------- register

    public function test_registering_tells_my_fatoorah_the_domain_the_owner_is_on_and_remembers_it(): void
    {
        $this->accepted();
        GatewayCredential::factory()->create(['apple_pay_domain_file' => self::FILE]);

        $this->registerAt()->assertRedirect()->assertSessionHas('status', 'daralsaffar.shop was registered with MyFatoorah for Apple Pay.');

        Http::assertSent(fn (Request $request) => $request->url() === self::REGISTER && $request->data() === ['DomainName' => 'daralsaffar.shop']);
        $saved = $this->saved();
        $this->assertSame('daralsaffar.shop', $saved->apple_pay_domain);
        $this->assertNotNull($saved->apple_pay_registered_at);
        $this->assertSame(['domain' => 'daralsaffar.shop'], ActivityLog::query()->where('action', 'payment.apple_pay_domain_registered')->firstOrFail()->properties);
    }

    public function test_registering_needs_the_file_saved_first(): void
    {
        $this->fakeMyFatoorah();

        $this->registerAt()->assertSessionHas('warning', 'Save the domain verification file first.');

        Http::assertNothingSent();
    }

    public function test_an_address_that_is_not_a_real_domain_is_never_sent_to_my_fatoorah(): void
    {
        $this->fakeMyFatoorah();
        GatewayCredential::factory()->create(['apple_pay_domain_file' => self::FILE]);

        foreach (['http://localhost', 'http://127.0.0.1', 'http://192.168.1.20'] as $shop) {
            $this->registerAt($shop)->assertSessionHas('warning');
        }

        Http::assertNothingSent();
        $this->assertNull($this->saved()->apple_pay_registered_at);
    }

    public function test_when_my_fatoorah_says_no_nothing_is_recorded_and_the_owner_is_told_what_to_check(): void
    {
        $this->fakeMyFatoorah([self::REGISTER => Http::response(['IsSuccess' => false, 'Message' => 'Make sure the verification file is accessible'], 400)]);
        GatewayCredential::factory()->create(['apple_pay_domain_file' => self::FILE]);

        $this->registerAt()->assertSessionHas('warning', fn (string $warning) => str_contains($warning, 'did not accept daralsaffar.shop'));

        $this->assertNull($this->saved()->apple_pay_registered_at);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_when_my_fatoorah_cannot_be_reached_the_owner_is_asked_to_try_again(): void
    {
        $this->fakeMyFatoorah([self::REGISTER => Http::response([], 503)]);
        GatewayCredential::factory()->create(['apple_pay_domain_file' => self::FILE]);

        $this->registerAt()->assertSessionHas('warning', 'MyFatoorah could not be reached just now. Try again shortly.');

        $this->assertNull($this->saved()->apple_pay_registered_at);
    }

    // ---------------------------------------------------------------- switch

    public function test_the_button_cannot_be_switched_on_before_the_domain_is_registered(): void
    {
        GatewayCredential::factory()->create(['apple_pay_domain_file' => self::FILE]);

        $this->put(route('panel.payments.gateway.update'), $this->form(['apple_pay_domain_file' => self::FILE, 'apple_pay_on_site' => '1']))
            ->assertSessionHasErrors('apple_pay_on_site');

        $this->assertNull($this->saved()->apple_pay_on_site);
    }

    public function test_once_registered_the_button_can_be_switched_on_and_the_gateway_then_takes_apple_pay_on_the_site(): void
    {
        GatewayCredential::factory()->create(['apple_pay_domain_file' => self::FILE, 'apple_pay_domain' => 'daralsaffar.shop', 'apple_pay_registered_at' => now()]);

        $this->put(route('panel.payments.gateway.update'), $this->form(['apple_pay_domain_file' => self::FILE, 'apple_pay_on_site' => '1']))
            ->assertRedirect(route('panel.payments.index'));
        $this->startNewRequest();

        $this->assertTrue($this->app->make(GatewayConfig::class)->takesApplePayOnSite());
        $this->get(route('panel.payments.index'))->assertSee("Button on the shop's page");
        $this->assertTrue(ActivityLog::query()->where('action', 'payment.credentials_updated')->firstOrFail()->properties['apple_pay_on_site']);
    }

    public function test_replacing_the_file_forgets_the_registration_and_asks_for_it_again_while_the_button_is_on(): void
    {
        GatewayCredential::factory()->create([
            'apple_pay_domain_file' => self::FILE, 'apple_pay_domain' => 'daralsaffar.shop',
            'apple_pay_registered_at' => now(), 'apple_pay_on_site' => true,
        ]);

        $this->put(route('panel.payments.gateway.update'), $this->form(['apple_pay_domain_file' => 'NEWFILE', 'apple_pay_on_site' => '1']))
            ->assertSessionHasErrors('apple_pay_on_site');
        $this->assertSame(self::FILE, $this->saved()->apple_pay_domain_file);

        // Switched off, the new file is saved and the registration is cleared.
        $this->put(route('panel.payments.gateway.update'), $this->form(['apple_pay_domain_file' => 'NEWFILE']))
            ->assertRedirect(route('panel.payments.index'));
        $saved = $this->saved();
        $this->assertSame(['NEWFILE', null, null, false], [$saved->apple_pay_domain_file, $saved->apple_pay_domain, $saved->apple_pay_registered_at, $saved->apple_pay_on_site]);
    }

    public function test_a_switch_left_on_in_the_servers_setting_does_not_stop_the_keys_being_saved(): void
    {
        config(['myfatoorah.apple_pay_on_site' => true]);

        $this->put(route('panel.payments.gateway.update'), $this->form(['apple_pay_on_site' => '1']))
            ->assertRedirect(route('panel.payments.index'));

        $this->assertNull($this->saved()->apple_pay_on_site);
    }
}
