<?php

namespace Tests\Feature\Http\Controllers\Panel;

use App\Events\OrderPlaced;
use App\Models\Order;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\Store\Push\VapidKeys;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SignsInStaff;
use Tests\TestCase;

class PushNotificationsPanelTest extends TestCase
{
    use LazilyRefreshDatabase, SignsInStaff;

    private const ENDPOINT = 'https://fcm.googleapis.com/fcm/send/device-one';

    // ------------------------------------------------------ install on a phone

    public function test_the_app_manifest_and_the_worker_are_public(): void
    {
        $manifest = $this->get(route('panel.manifest'))->assertOk();
        $this->assertStringContainsString('application/manifest+json', $manifest->headers->get('Content-Type'));
        $this->assertSame('/panel', $manifest->json('scope'));
        $this->assertSame('standalone', $manifest->json('display'));
        $this->assertNotEmpty($manifest->json('icons'));

        $worker = $this->get(route('panel.worker'))->assertOk();
        $this->assertStringContainsString('javascript', $worker->headers->get('Content-Type'));
        $this->assertStringContainsString('no-cache', $worker->headers->get('Cache-Control'));
        $worker->assertSee("addEventListener('push'", false)->assertSee("'/orders/feed'", false);
    }

    public function test_the_manifest_and_the_worker_do_not_exist_while_the_shop_runs_on_overzaki(): void
    {
        config(['store.backend' => 'overzaki']);

        $this->get('/panel/manifest.webmanifest')->assertNotFound();
        $this->get('/panel/sw.js')->assertNotFound();
    }

    public function test_the_panel_pages_offer_to_be_installed(): void
    {
        $this->signInAs('staff');

        $this->get(route('panel.dashboard'))
            ->assertSee('rel="manifest"', false)
            ->assertSee('apple-mobile-web-app-capable', false)
            ->assertSee('data-worker="/panel/sw.js"', false);
    }

    public function test_every_panel_page_carries_the_add_to_home_screen_sheet_with_the_iphone_steps(): void
    {
        $this->signInAs('staff');

        $this->get(route('panel.orders.index'))
            ->assertSee('data-install', false)
            ->assertSee('Add the control panel to your home screen')
            ->assertSee('Choose "Add to Home Screen"')
            ->assertSee('Not now');
    }

    public function test_the_profile_page_can_reopen_the_add_to_home_screen_sheet(): void
    {
        $this->signInAs('staff');

        $this->get(route('panel.profile.edit'))->assertSee('data-install-open', false);
    }

    public function test_the_phone_status_bar_does_not_cover_the_panel(): void
    {
        $this->signInAs('staff');

        $this->get(route('panel.dashboard'))
            ->assertSee('name="apple-mobile-web-app-status-bar-style" content="default"', false)
            ->assertDontSee('black-translucent');
    }

    public function test_the_sign_in_page_offers_to_be_installed_too(): void
    {
        $this->get(route('panel.login'))->assertSee('rel="manifest"', false);
    }

    // ------------------------------------------------------------ the profile card

    public function test_the_profile_page_carries_the_public_key_and_never_the_private_one(): void
    {
        $this->signInAs('staff');

        $response = $this->get(route('panel.profile.edit'))->assertOk();

        $response->assertSee('Order notifications on this device')
            ->assertSee('data-key="'.app(VapidKeys::class)->publicKey().'"', false)
            ->assertDontSee('PRIVATE KEY')
            ->assertDontSee(base64_encode(app(VapidKeys::class)->privateKeyPem()));
    }

    // ------------------------------------------------------------ subscribing

    public function test_a_device_can_be_subscribed_and_is_remembered_once(): void
    {
        $member = $this->signInAs('staff');

        $this->postJson(route('panel.push.subscribe'), ['endpoint' => self::ENDPOINT])->assertOk();
        $this->postJson(route('panel.push.subscribe'), ['endpoint' => self::ENDPOINT])->assertOk();

        $this->assertSame(1, PushSubscription::query()->count());
        $this->assertTrue(PushSubscription::query()->firstOrFail()->staff->is($member));
    }

    public function test_a_shared_device_moves_to_whoever_signs_in_on_it(): void
    {
        $this->signInAs('staff');
        $this->postJson(route('panel.push.subscribe'), ['endpoint' => self::ENDPOINT])->assertOk();

        $second = $this->signInAs('manager');
        $this->postJson(route('panel.push.subscribe'), ['endpoint' => self::ENDPOINT])->assertOk();

        $this->assertSame(1, PushSubscription::query()->count());
        $this->assertTrue(PushSubscription::query()->firstOrFail()->staff->is($second));
    }

    public function test_an_address_that_is_not_a_push_service_is_refused(): void
    {
        $this->signInAs('staff');

        foreach (['https://example.com/hook', 'http://fcm.googleapis.com/x', 'https://127.0.0.1/x', 'nonsense'] as $endpoint) {
            $this->postJson(route('panel.push.subscribe'), ['endpoint' => $endpoint])->assertStatus(422);
        }
        $this->postJson(route('panel.push.subscribe'), [])->assertStatus(422);

        $this->assertSame(0, PushSubscription::query()->count());
    }

    public function test_only_signed_in_staff_can_use_the_notification_endpoints(): void
    {
        $this->postJson(route('panel.push.subscribe'), ['endpoint' => self::ENDPOINT])->assertUnauthorized();
        $this->postJson(route('panel.push.unsubscribe'), ['endpoint' => self::ENDPOINT])->assertUnauthorized();
        $this->postJson(route('panel.push.test'))->assertUnauthorized();
    }

    public function test_a_device_is_unsubscribed_only_by_its_own_member_of_staff(): void
    {
        $owner = User::factory()->owner()->create();
        PushSubscription::factory()->for($owner, 'staff')->create(['endpoint' => self::ENDPOINT, 'endpoint_hash' => PushSubscription::hashOf(self::ENDPOINT)]);

        $this->signInAs('manager');
        $this->postJson(route('panel.push.unsubscribe'), ['endpoint' => self::ENDPOINT])->assertOk();
        $this->assertSame(1, PushSubscription::query()->count());

        $this->actingAs($owner, 'staff');
        $this->signedInStaff = $owner;
        $this->startNewRequest();
        $this->postJson(route('panel.push.unsubscribe'), ['endpoint' => self::ENDPOINT])->assertOk();
        $this->assertSame(0, PushSubscription::query()->count());
    }

    public function test_the_test_button_reaches_only_the_devices_of_whoever_pressed_it(): void
    {
        Http::fake(['*' => Http::response('', 201)]);
        $member = $this->signInAs('staff');
        PushSubscription::factory()->for($member, 'staff')->count(2)->create();
        PushSubscription::factory()->create();

        $this->postJson(route('panel.push.test'))->assertOk()->assertJson(['delivered' => 2]);

        Http::assertSentCount(2);
    }

    // ------------------------------------------------------------ a new order

    public function test_a_placed_order_wakes_the_devices_of_active_staff_who_look_after_orders(): void
    {
        Http::fake(['*' => Http::response('', 201)]);
        $owner = User::factory()->owner()->create();
        $manager = User::factory()->manager()->create();
        $staff = User::factory()->staff()->create();
        $left = User::factory()->manager()->create(['is_active' => false]);

        foreach ([$owner, $manager, $staff, $left] as $member) {
            PushSubscription::factory()->for($member, 'staff')->create();
        }

        OrderPlaced::dispatch(Order::factory()->create());
        app(DeferredCallbackCollection::class)->invoke();

        Http::assertSentCount(3);
    }

    public function test_one_unreachable_phone_neither_stops_the_others_nor_the_order(): void
    {
        Http::fake([
            'https://fcm.googleapis.com/fcm/send/dead' => Http::response('', 410),
            '*' => Http::response('', 201),
        ]);
        $owner = User::factory()->owner()->create();
        PushSubscription::factory()->for($owner, 'staff')->create(['endpoint' => 'https://fcm.googleapis.com/fcm/send/dead', 'endpoint_hash' => 'dead']);
        $alive = PushSubscription::factory()->for($owner, 'staff')->create();

        OrderPlaced::dispatch(Order::factory()->create());
        app(DeferredCallbackCollection::class)->invoke();

        $this->assertNotNull($alive->fresh()->last_sent_at);
        $this->assertSame(1, PushSubscription::query()->count());
    }

    public function test_with_nobody_subscribed_a_placed_order_contacts_no_one(): void
    {
        Http::fake();

        OrderPlaced::dispatch(Order::factory()->create());
        app(DeferredCallbackCollection::class)->invoke();

        Http::assertNothingSent();
    }

    public function test_the_notification_carries_no_order_details(): void
    {
        Http::fake(['*' => Http::response('', 201)]);
        PushSubscription::factory()->for(User::factory()->owner(), 'staff')->create();
        $order = Order::factory()->create(['customer_name' => 'Sara Al-Ahmad']);

        OrderPlaced::dispatch($order);
        app(DeferredCallbackCollection::class)->invoke();

        Http::assertSent(fn ($request) => $request->body() === ''
            && ! str_contains(json_encode($request->headers()), 'Sara')
            && ! str_contains($request->url(), $order->number));
    }
}
