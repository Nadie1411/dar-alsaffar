<?php

namespace Tests\Feature\Http\Controllers\Panel;

use App\Enums\AdminRole;
use App\Enums\PanelModule;
use App\Models\ActivityLog;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\Store\Payments\GatewayConfig;
use App\Services\Store\Payments\WebhookSignature;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\FakesMyFatoorah;
use Tests\Concerns\SignsInStaff;
use Tests\TestCase;

class GatewayKeysPanelTest extends TestCase
{
    use FakesMyFatoorah, LazilyRefreshDatabase, SignsInStaff;

    /** Recognisable, so a test can look for it anywhere it must never appear. */
    private const KEY = 'THE-OWNERS-LIVE-KEY-MARKER-9f3a';

    private const SECRET = 'THE-OWNERS-WEBHOOK-SECRET-MARKER-77b1';

    private const PASSWORD = 'Owner-pass-12345';

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = $this->signInAs('owner', ['password' => self::PASSWORD]);
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function form(array $overrides = []): array
    {
        return array_merge([
            'environment' => 'sandbox', 'api_key' => self::KEY, 'webhook_secret' => self::SECRET,
            'enabled' => '1', 'current_password' => self::PASSWORD,
        ], $overrides);
    }

    private function saved(): ?GatewayCredential
    {
        return GatewayCredential::query()->first();
    }

    // ---------------------------------------------------------------- access

    public function test_only_an_owner_can_open_or_change_the_keys(): void
    {
        foreach (['manager', 'staff'] as $role) {
            $this->signInAs($role);

            $this->get(route('panel.payments.gateway.edit'))->assertForbidden();
            $this->put(route('panel.payments.gateway.update'), $this->form())->assertForbidden();
        }

        $this->assertNull($this->saved());
    }

    public function test_a_guest_is_sent_to_the_login_page(): void
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        $this->get(route('panel.payments.gateway.edit'))->assertRedirect(route('panel.login'));
        $this->put(route('panel.payments.gateway.update'), $this->form())->assertRedirect(route('panel.login'));
    }

    public function test_the_role_that_holds_the_keys_is_the_owner_and_nobody_else(): void
    {
        $this->assertTrue(AdminRole::Owner->allows(PanelModule::Gateway));
        $this->assertFalse(AdminRole::Manager->allows(PanelModule::Gateway));
        $this->assertFalse(AdminRole::Staff->allows(PanelModule::Gateway));
        $this->assertTrue(AdminRole::Manager->allows(PanelModule::Payments), 'a manager still runs payments day to day');
    }

    public function test_the_payments_page_offers_the_way_in_to_owners_only(): void
    {
        $this->get(route('panel.payments.index'))->assertSee(route('panel.payments.gateway.edit'), false)->assertSee('Manage payment keys');

        $this->signInAs('manager');
        $this->get(route('panel.payments.index'))->assertOk()->assertDontSee(route('panel.payments.gateway.edit'), false)->assertDontSee('Manage payment keys');
    }

    public function test_the_page_opens_and_says_where_each_key_comes_from(): void
    {
        config(['myfatoorah.api_key' => 'env-key', 'myfatoorah.webhook_secret' => '']);

        $this->get(route('panel.payments.gateway.edit'))
            ->assertOk()
            ->assertSee('Payment keys')
            ->assertSee("From the server's setting")
            ->assertSee('Not set');
    }

    // ---------------------------------------------------------------- saving

    public function test_the_keys_are_saved_encrypted_and_the_owner_is_sent_back_with_a_confirmation(): void
    {
        $this->put(route('panel.payments.gateway.update'), $this->form())
            ->assertRedirect(route('panel.payments.index'))
            ->assertSessionHas('status', __('panel.gateway.saved', [], 'en'));

        $credential = $this->saved();

        $this->assertSame(self::KEY, $credential->api_key);
        $this->assertSame(self::SECRET, $credential->webhook_secret);
        $this->assertSame('https://apitest.myfatoorah.com', $credential->api_url);
        $this->assertTrue($credential->enabled);
        $this->assertSame($this->owner->id, $credential->updated_by);

        $raw = json_encode((array) DB::table('gateway_credentials')->first());
        $this->assertStringNotContainsString(self::KEY, $raw);
        $this->assertStringNotContainsString(self::SECRET, $raw);
    }

    public function test_what_was_saved_is_what_the_gateway_then_uses(): void
    {
        $this->put(route('panel.payments.gateway.update'), $this->form(['environment' => 'kuwait']));
        $this->startNewRequest();

        $gateway = $this->app->make(GatewayConfig::class);

        $this->assertSame(self::KEY, $gateway->apiKey());
        $this->assertSame('https://api.myfatoorah.com', $gateway->apiUrl());
        $this->assertSame(self::SECRET, $gateway->webhookSecret());
    }

    public function test_the_password_is_asked_for_again_and_without_it_nothing_changes(): void
    {
        foreach ([[], ['current_password' => ''], ['current_password' => 'not-the-password']] as $override) {
            $payload = $this->form($override);

            if ($override === []) {
                unset($payload['current_password']);
            }

            $this->put(route('panel.payments.gateway.update'), $payload)->assertSessionHasErrors('current_password');
        }

        $this->assertNull($this->saved());
    }

    public function test_leaving_a_key_empty_keeps_the_one_already_saved(): void
    {
        $this->put(route('panel.payments.gateway.update'), $this->form());

        $this->put(route('panel.payments.gateway.update'), $this->form(['api_key' => '', 'webhook_secret' => '', 'environment' => 'kuwait']));

        $credential = $this->saved();
        $this->assertSame(self::KEY, $credential->api_key);
        $this->assertSame(self::SECRET, $credential->webhook_secret);
        $this->assertSame('https://api.myfatoorah.com', $credential->api_url, 'but the address did change');
    }

    public function test_a_new_key_replaces_the_old_one(): void
    {
        $this->put(route('panel.payments.gateway.update'), $this->form());

        $this->put(route('panel.payments.gateway.update'), $this->form(['api_key' => 'A-NEWER-KEY-0001', 'webhook_secret' => '']));

        $this->assertSame('A-NEWER-KEY-0001', $this->saved()->api_key);
        $this->assertSame(self::SECRET, $this->saved()->webhook_secret);
    }

    public function test_a_saved_key_can_be_removed_and_the_server_setting_takes_over_again(): void
    {
        config(['myfatoorah.api_key' => 'env-key']);
        $this->put(route('panel.payments.gateway.update'), $this->form());

        $this->put(route('panel.payments.gateway.update'), $this->form(['api_key' => '', 'webhook_secret' => '', 'clear_api_key' => '1', 'clear_webhook_secret' => '1']));
        $this->startNewRequest();

        $this->assertNull($this->saved()->api_key);
        $this->assertNull($this->saved()->webhook_secret);
        $this->assertSame('env-key', $this->app->make(GatewayConfig::class)->apiKey());
    }

    public function test_a_new_value_wins_over_a_remove_ticked_at_the_same_time(): void
    {
        $this->put(route('panel.payments.gateway.update'), $this->form());

        $this->put(route('panel.payments.gateway.update'), $this->form(['api_key' => 'REPLACEMENT-KEY-1', 'clear_api_key' => '1']));

        $this->assertSame('REPLACEMENT-KEY-1', $this->saved()->api_key);
    }

    public function test_the_environment_can_be_set_back_to_the_servers_own(): void
    {
        $this->put(route('panel.payments.gateway.update'), $this->form(['environment' => 'sandbox']));
        $this->assertSame('https://apitest.myfatoorah.com', $this->saved()->api_url);

        $this->put(route('panel.payments.gateway.update'), $this->form(['api_key' => '', 'webhook_secret' => '', 'environment' => '']));

        $this->assertNull($this->saved()->api_url);
    }

    public function test_online_payment_can_be_switched_off_without_touching_the_key(): void
    {
        $this->put(route('panel.payments.gateway.update'), $this->form());

        $this->put(route('panel.payments.gateway.update'), $this->form(['api_key' => '', 'webhook_secret' => '', 'enabled' => null]));

        $this->assertFalse($this->saved()->enabled);
        $this->assertSame(self::KEY, $this->saved()->api_key);
    }

    // ------------------------------------------------------------ validation

    /**
     * @return array<string,array{0:array<string,mixed>,1:string}>
     */
    public static function invalid(): array
    {
        return [
            'an unknown environment' => [['environment' => 'my-own'], 'environment'],
            'an address typed in instead of chosen' => [['environment' => 'https://evil.example'], 'environment'],
            'a key with a space inside' => [['api_key' => 'abc def'], 'api_key'],
            'a key with "Bearer" in front' => [['api_key' => 'Bearer abcdef'], 'api_key'],
            'a key with a quote' => [['api_key' => 'abc"def'], 'api_key'],
            'a key with a line break inside' => [['api_key' => "abc\ndef"], 'api_key'],
            'a key with markup' => [['api_key' => '<script>x</script>'], 'api_key'],
            'a key far too long' => [['api_key' => str_repeat('a', 4_001)], 'api_key'],
            'a secret with a space' => [['webhook_secret' => 'has space'], 'webhook_secret'],
            'a secret that is too long' => [['webhook_secret' => str_repeat('s', 1_001)], 'webhook_secret'],
        ];
    }

    /**
     * @param  array<string,mixed>  $override
     */
    #[DataProvider('invalid')]
    public function test_something_that_is_not_a_key_is_refused_and_nothing_is_saved(array $override, string $field): void
    {
        $this->put(route('panel.payments.gateway.update'), $this->form($override))->assertSessionHasErrors($field);

        $this->assertNull($this->saved());
    }

    public function test_spaces_and_line_breaks_around_a_pasted_key_are_trimmed_not_refused(): void
    {
        $this->put(route('panel.payments.gateway.update'), $this->form(['api_key' => "  \n".self::KEY."\r\n ", 'webhook_secret' => ' '.self::SECRET.' ']))
            ->assertSessionHasNoErrors();

        $this->assertSame(self::KEY, $this->saved()->api_key);
        $this->assertSame(self::SECRET, $this->saved()->webhook_secret);
    }

    public function test_the_characters_real_keys_are_made_of_are_accepted(): void
    {
        $key = 'AbCd0123_-.=+/xyz'.str_repeat('Q', 900);

        $this->put(route('panel.payments.gateway.update'), $this->form(['api_key' => $key]))->assertSessionHasNoErrors();

        $this->assertSame($key, $this->saved()->api_key);
    }

    // ----------------------------------------------- the key never leaks out

    public function test_a_form_sent_back_for_an_error_does_not_carry_the_keys_in_the_session(): void
    {
        $this->from(route('panel.payments.gateway.edit'))
            ->put(route('panel.payments.gateway.update'), $this->form(['environment' => 'my-own', 'current_password' => 'wrong']))
            ->assertSessionHasErrors(['environment', 'current_password']);

        $old = session()->getOldInput();

        $this->assertArrayNotHasKey('api_key', $old);
        $this->assertArrayNotHasKey('webhook_secret', $old);
        $this->assertArrayNotHasKey('current_password', $old);
        $this->assertStringNotContainsString(self::KEY, json_encode(session()->all()));
        $this->assertStringNotContainsString(self::SECRET, json_encode(session()->all()));
    }

    public function test_the_page_after_an_error_does_not_show_the_keys_back(): void
    {
        $response = $this->from(route('panel.payments.gateway.edit'))
            ->put(route('panel.payments.gateway.update'), $this->form(['environment' => 'my-own']));

        $page = $this->followRedirects($response)->assertOk()->getContent();

        $this->assertStringNotContainsString(self::KEY, $page);
        $this->assertStringNotContainsString(self::SECRET, $page);
    }

    public function test_once_saved_no_page_of_the_panel_ever_shows_a_key(): void
    {
        $this->put(route('panel.payments.gateway.update'), $this->form());
        $this->startNewRequest();

        foreach ([
            route('panel.payments.gateway.edit'), route('panel.payments.index'), route('panel.log.index'), route('panel.dashboard'),
            route('panel.orders.index'), route('panel.content.edit'), route('panel.staff.index'),
        ] as $url) {
            $content = $this->get($url)->assertOk()->getContent();

            $this->assertStringNotContainsString(self::KEY, $content, $url);
            $this->assertStringNotContainsString(self::SECRET, $content, $url);
        }
    }

    public function test_the_edit_page_says_a_key_is_saved_without_putting_it_in_the_form(): void
    {
        $this->put(route('panel.payments.gateway.update'), $this->form());
        $this->startNewRequest();

        $page = $this->get(route('panel.payments.gateway.edit'))->assertOk()
            ->assertSee('Saved in the panel')
            ->assertSee('Saved — leave empty to keep it')
            ->assertSee('Remove the one saved in the panel')
            ->getContent();

        // Both password fields are empty: there is nothing in the page to read out.
        $this->assertMatchesRegularExpression('/name="api_key" type="password" value=""/', $page);
        $this->assertMatchesRegularExpression('/name="webhook_secret" type="password" value=""/', $page);
    }

    public function test_the_activity_log_records_what_changed_never_what_it_changed_to(): void
    {
        $this->put(route('panel.payments.gateway.update'), $this->form());

        $entry = ActivityLog::query()->where('action', 'payment.credentials_updated')->firstOrFail();

        $this->assertSame($this->owner->id, $entry->user_id);
        $this->assertSame('MyFatoorah', $entry->subject_label);
        $this->assertSame('changed', $entry->properties['api_key']);
        $this->assertSame('changed', $entry->properties['webhook_secret']);
        $this->assertSame('sandbox', $entry->properties['environment']);
        $this->assertStringNotContainsString(self::KEY, json_encode($entry->toArray()));
        $this->assertStringNotContainsString(self::SECRET, json_encode($entry->toArray()));
        $this->assertStringNotContainsString('apitest', json_encode($entry->properties), 'not even the address');
    }

    public function test_nothing_is_logged_when_nothing_changed(): void
    {
        $this->put(route('panel.payments.gateway.update'), $this->form());
        ActivityLog::query()->delete();

        $this->put(route('panel.payments.gateway.update'), $this->form(['api_key' => '', 'webhook_secret' => '']));

        $this->assertSame(0, ActivityLog::query()->where('action', 'payment.credentials_updated')->count());
    }

    public function test_the_keys_page_is_never_cached_by_a_browser_or_a_proxy_that_ignores_the_session(): void
    {
        $response = $this->get(route('panel.payments.gateway.edit'));

        // Laravel marks a response that touched the session as private; it must never be public.
        $this->assertStringNotContainsString('public', (string) $response->headers->get('Cache-Control'));
    }

    // ------------------------------------------------------------- effects

    public function test_saving_makes_checkout_forget_the_payment_methods_it_remembered_for_the_old_account(): void
    {
        Cache::put('myfatoorah:methods:ar', [['id' => 'stale']], 600);
        Cache::put('myfatoorah:methods:en', [['id' => 'stale']], 600);

        $this->put(route('panel.payments.gateway.update'), $this->form());

        $this->assertNull(Cache::get('myfatoorah:methods:ar'));
        $this->assertNull(Cache::get('myfatoorah:methods:en'));
    }

    public function test_a_key_pasted_here_is_what_reaches_my_fatoorah_from_then_on(): void
    {
        config(['myfatoorah.api_key' => '', 'myfatoorah.enabled' => true]);
        $this->put(route('panel.payments.gateway.update'), $this->form(['environment' => 'sandbox']));
        $this->startNewRequest();
        $this->fakeMyFatoorah();

        $this->post(route('panel.payments.test'))->assertSessionHas('status', fn (string $message) => str_contains($message, 'KNET'));

        Http::assertSent(fn ($request) => $request->url() === 'https://apitest.myfatoorah.com/v3/payment-methods'
            && $request->hasHeader('Authorization', 'Bearer '.self::KEY));
    }

    public function test_the_payments_page_then_reports_a_working_gateway_without_showing_anything_secret(): void
    {
        config(['myfatoorah.api_key' => '', 'myfatoorah.webhook_secret' => '']);
        $this->put(route('panel.payments.gateway.update'), $this->form(['environment' => 'kuwait']));
        $this->startNewRequest();

        $this->get(route('panel.payments.index'))
            ->assertSee('Live')
            ->assertSee('api.myfatoorah.com')
            ->assertSee('Saved in the panel')
            ->assertDontSee('Online payment is not active');
    }

    public function test_a_webhook_is_checked_with_the_secret_saved_in_the_panel_not_the_servers(): void
    {
        $this->configureMyFatoorah('the-servers-old-secret');
        $this->put(route('panel.payments.gateway.update'), $this->form(['environment' => 'sandbox']));
        $this->startNewRequest();

        $order = Order::factory()->pendingPayment()->create(['total_fils' => 40_000]);
        $payment = Payment::factory()->create(['order_id' => $order->id, 'reference' => 'REF-PANEL', 'amount_fils' => 40_000]);
        $this->fakeMyFatoorah(['apitest.myfatoorah.com/v3/payments/*' => Http::response($this->paymentDetails($payment->reference))]);
        $event = $this->webhookEvent($payment->reference);
        $signature = new WebhookSignature;

        // Signed with the server's old secret: refused.
        $this->flushSession();
        $this->postJson('/api/webhooks/myfatoorah', $event, ['MyFatoorah-Signature' => $signature->sign($event, 'the-servers-old-secret')])->assertUnauthorized();

        // Signed with the one saved in the panel: accepted.
        $this->postJson('/api/webhooks/myfatoorah', $event, ['MyFatoorah-Signature' => $signature->sign($event, self::SECRET)])->assertOk();
    }

    public function test_the_attempts_to_guess_the_password_here_are_rate_limited(): void
    {
        foreach (range(1, 10) as $attempt) {
            $this->put(route('panel.payments.gateway.update'), $this->form(['current_password' => 'wrong-'.$attempt]))->assertSessionHasErrors('current_password');
        }

        $this->put(route('panel.payments.gateway.update'), $this->form())->assertStatus(429);

        $this->assertNull($this->saved());
    }
}
