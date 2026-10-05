<?php

namespace Tests\Feature\Http\Controllers\Panel;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\SignsInStaff;
use Tests\TestCase;

class PanelAccessTest extends TestCase
{
    use LazilyRefreshDatabase, SignsInStaff;

    private const PASSWORD = 'Correct-horse-9';

    /**
     * @return array<string,array{0:string,1:string,2:int}>
     */
    public static function areasByRole(): array
    {
        $everyone = ['panel.dashboard', 'panel.orders.index', 'panel.customers.index', 'panel.inbox.index', 'panel.profile.edit'];
        $managing = [
            'panel.products.index', 'panel.categories.index', 'panel.addons.index', 'panel.vouchers.index',
            'panel.delivery.index', 'panel.payments.index', 'panel.content.edit', 'panel.reports.index', 'panel.log.index',
        ];

        $cases = [];

        foreach ($everyone as $route) {
            foreach (['staff', 'manager', 'owner'] as $role) {
                $cases["$role opens $route"] = [$role, $route, 200];
            }
        }

        foreach ($managing as $route) {
            $cases["staff is refused $route"] = ['staff', $route, 403];
            $cases["manager opens $route"] = ['manager', $route, 200];
            $cases["owner opens $route"] = ['owner', $route, 200];
        }

        // The payment keys: whoever holds them decides where customers' money goes, so owners only.
        $cases['owner opens panel.payments.gateway.edit'] = ['owner', 'panel.payments.gateway.edit', 200];
        $cases['manager is refused panel.payments.gateway.edit'] = ['manager', 'panel.payments.gateway.edit', 403];
        $cases['staff is refused panel.payments.gateway.edit'] = ['staff', 'panel.payments.gateway.edit', 403];

        $cases['staff is refused panel.staff.index'] = ['staff', 'panel.staff.index', 403];
        $cases['manager is refused panel.staff.index'] = ['manager', 'panel.staff.index', 403];
        $cases['owner opens panel.staff.index'] = ['owner', 'panel.staff.index', 200];

        return $cases;
    }

    public function test_the_panel_does_not_exist_while_the_store_still_runs_on_overzaki(): void
    {
        config(['store.backend' => 'overzaki']);

        $this->get('/panel')->assertNotFound();
        $this->get('/panel/login')->assertNotFound();
        $this->post('/panel/login', ['email' => 'a@b.test', 'password' => 'x'])->assertNotFound();
    }

    public function test_a_guest_is_sent_to_the_login_page_and_back_to_where_they_were_going_once_signed_in(): void
    {
        $user = $this->staffMember('manager', ['password' => self::PASSWORD]);

        $this->get('/panel/orders?status=new')->assertRedirect(route('panel.login'));

        $this->post('/panel/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertRedirect(url('/panel/orders?status=new'));
    }

    #[DataProvider('areasByRole')]
    public function test_each_role_can_open_only_the_areas_it_is_given(string $role, string $route, int $status): void
    {
        $this->signInAs($role);

        $this->get(route($route))->assertStatus($status);
    }

    public function test_the_sidebar_offers_a_staff_member_only_what_they_can_open(): void
    {
        $this->signInAs('staff');

        $this->get(route('panel.dashboard'))
            ->assertSee(route('panel.orders.index'), false)
            ->assertDontSee(route('panel.products.index'), false)
            ->assertDontSee(route('panel.staff.index'), false)
            ->assertDontSee(route('panel.payments.index'), false);
    }

    public function test_a_member_of_staff_sees_no_sales_figures_on_the_dashboard_but_a_manager_does(): void
    {
        $this->signInAs('staff');
        $this->get(route('panel.dashboard'))->assertDontSee('Sales today');

        $this->signInAs('manager');
        $this->get(route('panel.dashboard'))->assertSee('Sales today');
    }

    public function test_an_account_switched_off_is_signed_out_at_its_very_next_request(): void
    {
        $user = $this->staffMember('manager', ['password' => self::PASSWORD]);

        $this->post('/panel/login', ['email' => $user->email, 'password' => self::PASSWORD]);
        $this->get(route('panel.dashboard'))->assertOk();

        $user->update(['is_active' => false]);
        $this->startNewRequest();

        $this->get(route('panel.dashboard'))->assertRedirect(route('panel.login'));
        $this->assertGuest('staff');
    }

    public function test_changing_a_password_signs_out_every_session_that_was_open_with_the_old_one(): void
    {
        $user = $this->staffMember('manager', ['password' => self::PASSWORD]);

        $this->post('/panel/login', ['email' => $user->email, 'password' => self::PASSWORD]);
        $this->get(route('panel.dashboard'))->assertOk();

        // Somebody else — an owner, or the command line — resets it.
        $user->update(['password' => 'Brand-new-pass-77']);
        $this->startNewRequest();

        $this->get(route('panel.dashboard'))->assertRedirect(route('panel.login'));
    }

    public function test_signing_in_opens_the_dashboard_and_is_recorded(): void
    {
        $user = $this->staffMember('owner', ['password' => self::PASSWORD]);

        $this->post('/panel/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertRedirect(route('panel.dashboard'));

        $this->assertAuthenticatedAs($user, 'staff');
        $this->assertNotNull($user->fresh()->last_login_at);
        $this->assertDatabaseHas('activity_logs', ['user_id' => $user->id, 'action' => 'auth.login']);
    }

    public function test_keep_me_signed_in_lasts_thirty_days_not_the_frameworks_year_and_more(): void
    {
        $user = $this->staffMember('owner', ['password' => self::PASSWORD]);

        $response = $this->post('/panel/login', ['email' => $user->email, 'password' => self::PASSWORD, 'remember' => '1']);

        $name = $this->app['auth']->guard('staff')->getRecallerName();
        $cookie = $response->getCookie($name, false);

        $this->assertNotNull($cookie, 'a "remember me" cookie was set');
        $days = ($cookie->getExpiresTime() - time()) / 86_400;
        $this->assertGreaterThan(29, $days);
        $this->assertLessThan(31, $days);
    }

    public function test_without_ticking_remember_no_long_lived_cookie_is_set(): void
    {
        $user = $this->staffMember('owner', ['password' => self::PASSWORD]);

        $response = $this->post('/panel/login', ['email' => $user->email, 'password' => self::PASSWORD]);

        $this->assertNull($response->getCookie($this->app['auth']->guard('staff')->getRecallerName(), false));
    }

    public function test_the_email_address_is_matched_without_regard_to_case_or_stray_spaces(): void
    {
        $user = $this->staffMember('owner', ['email' => 'owner@dar.test', 'password' => self::PASSWORD]);

        $this->post('/panel/login', ['email' => '  Owner@DAR.test ', 'password' => self::PASSWORD])
            ->assertRedirect(route('panel.dashboard'));

        $this->assertAuthenticatedAs($user, 'staff');
    }

    public function test_a_wrong_password_and_an_unknown_address_get_the_same_answer(): void
    {
        $user = $this->staffMember('owner', ['password' => self::PASSWORD]);

        $wrong = $this->withSession(['panel.locale' => 'en'])->from('/panel/login')
            ->post('/panel/login', ['email' => $user->email, 'password' => 'not-it']);
        $unknown = $this->withSession(['panel.locale' => 'en'])->from('/panel/login')
            ->post('/panel/login', ['email' => 'nobody@dar.test', 'password' => 'not-it']);

        $wrong->assertSessionHasErrors(['email' => __('panel.login.failed', [], 'en')]);
        $unknown->assertSessionHasErrors(['email' => __('panel.login.failed', [], 'en')]);
        $this->assertGuest('staff');
    }

    public function test_an_account_that_is_switched_off_cannot_sign_in_and_is_not_told_why(): void
    {
        $user = $this->staffMember('owner', ['password' => self::PASSWORD, 'is_active' => false]);

        $this->withSession(['panel.locale' => 'en'])->from('/panel/login')
            ->post('/panel/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertSessionHasErrors(['email' => __('panel.login.failed', [], 'en')]);

        $this->assertGuest('staff');
    }

    public function test_repeated_failures_lock_the_address_out_even_for_the_right_password(): void
    {
        $user = $this->staffMember('owner', ['password' => self::PASSWORD]);

        foreach (range(1, 5) as $attempt) {
            $this->post('/panel/login', ['email' => $user->email, 'password' => 'wrong-'.$attempt]);
        }

        $this->from('/panel/login')
            ->post('/panel/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertSessionHasErrors('email');

        $this->assertGuest('staff');
    }

    public function test_a_failed_attempt_from_one_address_does_not_lock_out_another(): void
    {
        $first = $this->staffMember('owner', ['password' => self::PASSWORD]);
        $second = $this->staffMember('manager', ['password' => self::PASSWORD]);

        foreach (range(1, 5) as $attempt) {
            $this->post('/panel/login', ['email' => $first->email, 'password' => 'wrong-'.$attempt]);
        }

        $this->post('/panel/login', ['email' => $second->email, 'password' => self::PASSWORD])
            ->assertRedirect(route('panel.dashboard'));
    }

    public function test_the_login_form_needs_both_fields(): void
    {
        $this->from('/panel/login')
            ->post('/panel/login', [])
            ->assertSessionHasErrors(['email', 'password']);
    }

    public function test_someone_already_signed_in_is_taken_straight_to_the_dashboard(): void
    {
        $this->signInAs('owner');

        $this->get(route('panel.login'))->assertRedirect(route('panel.dashboard'));
    }

    public function test_signing_out_ends_the_panel_session_but_leaves_a_shoppers_basket_alone(): void
    {
        $this->signInAs('owner');

        $this->withSession(['cart.items' => ['line' => ['productId' => '1', 'quantity' => 1]]])
            ->post(route('panel.logout'))
            ->assertRedirect(route('panel.login'));

        $this->assertGuest('staff');
        $this->assertSame(['line' => ['productId' => '1', 'quantity' => 1]], session('cart.items'));
    }

    public function test_the_panels_language_can_be_switched_and_is_remembered_on_the_account(): void
    {
        $user = $this->signInAs('owner', ['locale' => 'ar']);

        $this->get(route('panel.dashboard'))->assertSee('dir="rtl"', false);

        $this->from('/panel/orders')->get(route('panel.language', 'en'))->assertRedirect('/panel/orders');

        $this->assertSame('en', $user->fresh()->locale);
        $this->get(route('panel.dashboard'))->assertSee('dir="ltr"', false)->assertSee('Welcome back');
    }

    public function test_the_language_switch_never_sends_anyone_off_the_panel(): void
    {
        $this->signInAs('owner');

        $this->from('https://elsewhere.example/phish')
            ->get(route('panel.language', 'ar'))
            ->assertRedirect(route('panel.dashboard'));
    }

    public function test_only_arabic_and_english_are_offered(): void
    {
        $this->signInAs('owner');

        $this->get('/panel/language/fr')->assertNotFound();
    }

    public function test_the_login_page_works_in_both_languages_before_anyone_is_signed_in(): void
    {
        $this->get(route('panel.login'))->assertOk()->assertSee('dir="rtl"', false);

        // Back to the page they were on, now in English.
        $this->get(route('panel.language', 'en'))->assertRedirect(route('panel.login'));
        $this->get(route('panel.login'))->assertOk()->assertSee('dir="ltr"', false)->assertSee('Sign in');
    }

    public function test_the_old_single_password_admin_hands_over_to_the_panel_once_the_store_is_on_its_own_backend(): void
    {
        $this->get('/admin')->assertRedirect(route('panel.dashboard'));
        $this->get('/admin/login')->assertRedirect(route('panel.dashboard'));
    }

    public function test_the_panel_is_never_indexed(): void
    {
        $this->get(route('panel.login'))->assertSee('noindex', false);
    }

    public function test_the_panel_has_an_owner_after_the_admin_command_runs(): void
    {
        $this->artisan('store:admin', ['email' => 'First@Dar.test', '--name' => 'First Owner'])
            ->expectsQuestion('Password', 'Long-enough-pass-1')
            ->expectsQuestion('Confirm password', 'Long-enough-pass-1')
            ->expectsOutputToContain('Created owner account')
            ->assertExitCode(0);

        $user = User::query()->where('email', 'first@dar.test')->firstOrFail();

        $this->assertTrue($user->isOwner());
        $this->post('/panel/login', ['email' => 'first@dar.test', 'password' => 'Long-enough-pass-1'])
            ->assertRedirect(route('panel.dashboard'));
    }

    public function test_the_admin_command_resets_a_password_and_switches_the_account_back_on(): void
    {
        $user = $this->staffMember('manager', ['is_active' => false, 'password' => self::PASSWORD]);

        $this->artisan('store:admin', ['email' => $user->email])
            ->expectsQuestion('New password', 'Another-long-pass-2')
            ->expectsQuestion('Confirm password', 'Another-long-pass-2')
            ->expectsOutputToContain('Password reset')
            ->assertExitCode(0);

        $this->assertTrue($user->fresh()->is_active);
        $this->post('/panel/login', ['email' => $user->email, 'password' => 'Another-long-pass-2'])
            ->assertRedirect(route('panel.dashboard'));
    }

    public function test_the_admin_command_refuses_a_short_password_and_a_mismatch(): void
    {
        $this->artisan('store:admin', ['email' => 'short@dar.test', '--name' => 'Short'])
            ->expectsQuestion('Password', 'short')
            ->expectsOutputToContain('at least 10 characters')
            ->assertExitCode(1);

        $this->artisan('store:admin', ['email' => 'mismatch@dar.test', '--name' => 'Mismatch'])
            ->expectsQuestion('Password', 'Long-enough-pass-1')
            ->expectsQuestion('Confirm password', 'Something-else-pass-1')
            ->expectsOutputToContain('did not match')
            ->assertExitCode(1);

        $this->assertDatabaseMissing('users', ['email' => 'short@dar.test']);
        $this->assertDatabaseMissing('users', ['email' => 'mismatch@dar.test']);
        $this->assertSame(0, ActivityLog::query()->count());
    }
}
