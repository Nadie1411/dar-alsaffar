<?php

namespace Tests\Feature\Http\Controllers\Panel;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\SignsInStaff;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use LazilyRefreshDatabase, SignsInStaff;

    public function test_every_role_can_open_their_own_profile(): void
    {
        foreach (['staff', 'manager', 'owner'] as $role) {
            $this->signInAs($role);

            $this->get(route('panel.profile.edit'))->assertOk()->assertSee('My account');
        }
    }

    public function test_a_guest_cannot_reach_it(): void
    {
        $this->get(route('panel.profile.edit'))->assertRedirect(route('panel.login'));
        $this->put(route('panel.profile.update'), ['name' => 'X', 'locale' => 'ar'])->assertRedirect(route('panel.login'));
    }

    public function test_a_member_can_change_their_name_and_language_without_touching_the_password(): void
    {
        $user = $this->signInAs('staff', ['name' => 'Old Name', 'password' => 'Original-pass-1']);

        $this->put(route('panel.profile.update'), ['name' => 'New Name', 'locale' => 'ar'])
            ->assertRedirect(route('panel.profile.edit'));

        $user->refresh();

        $this->assertSame('New Name', $user->name);
        $this->assertSame('ar', $user->locale);
        $this->assertTrue(Hash::check('Original-pass-1', $user->password));
        $this->assertSame('ar', session('panel.locale'));
    }

    public function test_the_current_password_is_needed_to_set_a_new_one(): void
    {
        $user = $this->signInAs('staff', ['password' => 'Original-pass-1']);

        $this->put(route('panel.profile.update'), [
            'name' => $user->name, 'locale' => 'en',
            'password' => 'Replaced-pass-2', 'password_confirmation' => 'Replaced-pass-2',
        ])->assertSessionHasErrors('current_password');

        $this->put(route('panel.profile.update'), [
            'name' => $user->name, 'locale' => 'en', 'current_password' => 'not-the-password',
            'password' => 'Replaced-pass-2', 'password_confirmation' => 'Replaced-pass-2',
        ])->assertSessionHasErrors('current_password');

        $this->assertTrue(Hash::check('Original-pass-1', $user->fresh()->password));
    }

    public function test_the_new_password_must_be_strong_and_confirmed(): void
    {
        $user = $this->signInAs('staff', ['password' => 'Original-pass-1']);
        $valid = ['name' => $user->name, 'locale' => 'en', 'current_password' => 'Original-pass-1'];

        $this->put(route('panel.profile.update'), $valid + ['password' => 'weak', 'password_confirmation' => 'weak'])
            ->assertSessionHasErrors('password');
        $this->put(route('panel.profile.update'), $valid + ['password' => 'Replaced-pass-2', 'password_confirmation' => 'Mismatch-pass-3'])
            ->assertSessionHasErrors('password');
    }

    public function test_changing_the_password_keeps_this_session_and_ends_every_other(): void
    {
        $user = $this->staffMember('manager', ['password' => 'Original-pass-1']);

        // The session the change is made from.
        $this->post('/panel/login', ['email' => $user->email, 'password' => 'Original-pass-1']);
        $this->put(route('panel.profile.update'), [
            'name' => $user->name, 'locale' => 'en', 'current_password' => 'Original-pass-1',
            'password' => 'Replaced-pass-2', 'password_confirmation' => 'Replaced-pass-2',
        ])->assertRedirect(route('panel.profile.edit'));

        $this->startNewRequest();
        $this->get(route('panel.dashboard'))->assertOk();

        // A second session, still carrying what the old password produced.
        $this->startNewRequest();
        $this->flushSession();
        $this->withSession(['panel.credential' => hash_hmac('sha256', Hash::make('Original-pass-1'), config('app.key'))]);
        $this->actingAs($user->fresh(), 'staff');

        $this->get(route('panel.dashboard'))->assertRedirect(route('panel.login'));
    }

    public function test_a_name_is_required_and_the_language_must_be_one_of_the_two(): void
    {
        $this->signInAs('staff');

        $this->put(route('panel.profile.update'), ['name' => '', 'locale' => 'fr'])
            ->assertSessionHasErrors(['name', 'locale']);
    }
}
