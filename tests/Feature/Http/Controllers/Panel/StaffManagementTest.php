<?php

namespace Tests\Feature\Http\Controllers\Panel;

use App\Enums\AdminRole;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\SignsInStaff;
use Tests\TestCase;

class StaffManagementTest extends TestCase
{
    use LazilyRefreshDatabase, SignsInStaff;

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function form(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Layla Hassan',
            'email' => 'Layla@Dar.test',
            'role' => 'manager',
            'locale' => 'en',
            'is_active' => '1',
            'password' => 'Strong-pass-123',
            'password_confirmation' => 'Strong-pass-123',
        ], $overrides);
    }

    public function test_only_an_owner_manages_staff(): void
    {
        $target = $this->staffMember('staff');

        foreach (['manager', 'staff'] as $role) {
            $this->signInAs($role);

            $this->get(route('panel.staff.index'))->assertForbidden();
            $this->get(route('panel.staff.create'))->assertForbidden();
            $this->post(route('panel.staff.store'), $this->form())->assertForbidden();
            $this->put(route('panel.staff.update', $target), $this->form())->assertForbidden();
            $this->delete(route('panel.staff.destroy', $target))->assertForbidden();
        }

        $this->assertDatabaseMissing('users', ['name' => 'Layla Hassan']);
        $this->assertDatabaseHas('users', ['id' => $target->id]);
    }

    public function test_the_list_shows_everyone_with_their_role_and_what_each_role_may_open(): void
    {
        $this->signInAs('owner', ['name' => 'Nora Owner']);
        $this->staffMember('staff', ['name' => 'Sami Staff']);

        $this->get(route('panel.staff.index'))
            ->assertOk()
            ->assertSee('Nora Owner')
            ->assertSee('Sami Staff')
            ->assertSee('Owner')
            ->assertSee('Activity log');
    }

    public function test_an_owner_adds_a_member_with_a_hashed_password_and_a_lower_case_address(): void
    {
        $owner = $this->signInAs('owner');

        $this->post(route('panel.staff.store'), $this->form())->assertRedirect(route('panel.staff.index'));

        $member = User::query()->where('email', 'layla@dar.test')->firstOrFail();

        $this->assertSame('Layla Hassan', $member->name);
        $this->assertSame(AdminRole::Manager, $member->role);
        $this->assertTrue($member->is_active);
        $this->assertTrue(Hash::check('Strong-pass-123', $member->password));
        $this->assertNotSame('Strong-pass-123', $member->password);
        $this->assertDatabaseHas('activity_logs', ['user_id' => $owner->id, 'action' => 'staff.created', 'subject_label' => 'Layla Hassan']);
    }

    public function test_the_new_member_can_then_sign_in_with_the_password_they_were_given(): void
    {
        $this->signInAs('owner');
        $this->post(route('panel.staff.store'), $this->form(['role' => 'staff']));

        $this->flushSession();
        $this->startNewRequest();

        $this->post('/panel/login', ['email' => 'layla@dar.test', 'password' => 'Strong-pass-123'])
            ->assertRedirect(route('panel.dashboard'));
    }

    public function test_a_weak_or_unconfirmed_password_is_refused(): void
    {
        $this->signInAs('owner');

        foreach ([
            ['password' => 'short1', 'password_confirmation' => 'short1'],
            ['password' => 'onlyletterspassword', 'password_confirmation' => 'onlyletterspassword'],
            ['password' => '1234567890123', 'password_confirmation' => '1234567890123'],
            ['password' => 'Strong-pass-123', 'password_confirmation' => 'Different-pass-456'],
            ['password' => '', 'password_confirmation' => ''],
        ] as $password) {
            $this->post(route('panel.staff.store'), $this->form($password))->assertSessionHasErrors('password');
        }

        $this->assertDatabaseMissing('users', ['email' => 'layla@dar.test']);
    }

    public function test_an_address_already_in_use_is_refused_whatever_its_case(): void
    {
        $this->signInAs('owner');
        $this->staffMember('staff', ['email' => 'layla@dar.test']);

        $this->post(route('panel.staff.store'), $this->form())->assertSessionHasErrors('email');
    }

    public function test_a_role_outside_the_three_is_refused(): void
    {
        $this->signInAs('owner');

        $this->post(route('panel.staff.store'), $this->form(['role' => 'superuser']))->assertSessionHasErrors('role');
    }

    public function test_an_owner_can_change_a_members_role_and_switch_them_off(): void
    {
        $this->signInAs('owner');
        $member = $this->staffMember('staff');

        $this->put(route('panel.staff.update', $member), $this->form([
            'name' => $member->name, 'email' => $member->email, 'role' => 'manager', 'is_active' => null,
            'password' => '', 'password_confirmation' => '',
        ]))->assertRedirect(route('panel.staff.index'));

        $member->refresh();

        $this->assertSame(AdminRole::Manager, $member->role);
        $this->assertFalse($member->is_active);
    }

    public function test_leaving_the_password_empty_keeps_the_old_one_and_filling_it_in_replaces_it(): void
    {
        $this->signInAs('owner');
        $member = $this->staffMember('staff', ['password' => 'Original-pass-1']);

        $keep = $this->form(['name' => $member->name, 'email' => $member->email, 'role' => 'staff', 'password' => '', 'password_confirmation' => '']);
        $this->put(route('panel.staff.update', $member), $keep);

        $this->assertTrue(Hash::check('Original-pass-1', $member->fresh()->password));

        $this->put(route('panel.staff.update', $member), array_merge($keep, ['password' => 'Replaced-pass-2', 'password_confirmation' => 'Replaced-pass-2']));

        $this->assertTrue(Hash::check('Replaced-pass-2', $member->fresh()->password));
        $this->assertFalse(Hash::check('Original-pass-1', $member->fresh()->password));
    }

    public function test_the_log_notes_that_a_password_changed_but_never_what_it_is(): void
    {
        $this->signInAs('owner');
        $member = $this->staffMember('staff');

        $this->put(route('panel.staff.update', $member), $this->form([
            'name' => $member->name, 'email' => $member->email, 'role' => 'staff',
            'password' => 'Replaced-pass-2', 'password_confirmation' => 'Replaced-pass-2',
        ]));

        $entry = ActivityLog::query()->where('action', 'staff.updated')->firstOrFail();

        $this->assertTrue($entry->properties['password_changed']);
        $this->assertStringNotContainsString('Replaced-pass-2', json_encode($entry->properties));
    }

    public function test_the_only_active_owner_cannot_be_demoted_switched_off_or_deleted(): void
    {
        $owner = $this->signInAs('owner');
        $back = ['name' => $owner->name, 'email' => $owner->email, 'password' => '', 'password_confirmation' => ''];

        $this->put(route('panel.staff.update', $owner), $this->form($back + ['role' => 'manager']))
            ->assertSessionHas('warning', __('panel.staff.lastOwner', [], 'en'));
        $this->put(route('panel.staff.update', $owner), $this->form($back + ['role' => 'owner', 'is_active' => null]))
            ->assertSessionHas('warning');
        $this->delete(route('panel.staff.destroy', $owner))->assertSessionHas('warning');

        $owner->refresh();
        $this->assertSame(AdminRole::Owner, $owner->role);
        $this->assertTrue($owner->is_active);
    }

    public function test_an_owner_can_step_down_once_another_owner_exists(): void
    {
        $owner = $this->signInAs('owner');
        $this->staffMember('owner');

        $this->put(route('panel.staff.update', $owner), $this->form([
            'name' => $owner->name, 'email' => $owner->email, 'role' => 'manager', 'password' => '', 'password_confirmation' => '',
        ]))->assertRedirect(route('panel.staff.index'));

        $this->assertSame(AdminRole::Manager, $owner->fresh()->role);
    }

    public function test_an_inactive_owner_does_not_count_as_somebody_who_can_run_the_shop(): void
    {
        $owner = $this->signInAs('owner');
        $this->staffMember('owner', ['is_active' => false]);

        $this->delete(route('panel.staff.destroy', $owner))->assertSessionHas('warning');
        $this->assertDatabaseHas('users', ['id' => $owner->id]);
    }

    public function test_nobody_can_delete_or_switch_off_their_own_account(): void
    {
        $owner = $this->signInAs('owner');
        $this->staffMember('owner');

        $this->delete(route('panel.staff.destroy', $owner))
            ->assertSessionHas('warning', __('panel.staff.cannotDeleteSelf', [], 'en'));

        $this->put(route('panel.staff.update', $owner), $this->form([
            'name' => $owner->name, 'email' => $owner->email, 'role' => 'owner', 'is_active' => null,
            'password' => '', 'password_confirmation' => '',
        ]))->assertSessionHas('warning', __('panel.staff.cannotDeactivateSelf', [], 'en'));

        $this->assertDatabaseHas('users', ['id' => $owner->id, 'is_active' => true]);
    }

    public function test_an_owner_can_delete_another_member_and_their_history_is_kept(): void
    {
        $this->signInAs('owner');
        $member = $this->staffMember('staff', ['name' => 'Leaver']);
        ActivityLog::factory()->create(['user_id' => $member->id, 'action' => 'order.note_saved']);

        $this->delete(route('panel.staff.destroy', $member))->assertRedirect(route('panel.staff.index'));

        $this->assertDatabaseMissing('users', ['id' => $member->id]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'order.note_saved', 'user_id' => null]);
    }

    public function test_the_edit_page_never_prints_a_password_hash(): void
    {
        $this->signInAs('owner');
        $member = $this->staffMember('staff');

        $this->get(route('panel.staff.edit', $member))
            ->assertOk()
            ->assertDontSee($member->password, false);
    }
}
