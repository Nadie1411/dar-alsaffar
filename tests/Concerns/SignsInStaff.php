<?php

namespace Tests\Concerns;

use App\Models\User;

/**
 * Runs a test inside the admin panel: the store on its own backend, settings
 * kept away from the real file, and a member of staff signed in on request.
 *
 * Staff are given an English panel unless a test says otherwise, so what the
 * pages say can be read in the assertions.
 */
trait SignsInStaff
{
    use IsolatesSettings, UsesLocalStore;

    /** Who `signInAs()` last signed in, so a new request can sign them in again. */
    protected ?User $signedInStaff = null;

    /**
     * @param  array<string,mixed>  $attributes
     */
    protected function staffMember(string $role = 'owner', array $attributes = []): User
    {
        return User::factory()->{$role}()->create($attributes + ['locale' => 'en']);
    }

    /**
     * @param  array<string,mixed>  $attributes
     */
    protected function signInAs(string $role = 'owner', array $attributes = []): User
    {
        $user = $this->staffMember($role, $attributes);

        // One test may sign in as more than one person, one after another; a
        // session belongs to a single person, so it starts clean each time.
        $this->flushSession();
        $this->actingAs($user, 'staff');
        $this->signedInStaff = $user;

        return $user;
    }

    /**
     * Makes the next request look like a fresh one: to the authentication
     * layer, which in a real request has never seen the user before, and to
     * the request-scoped services. A test that changes something in the
     * database and then makes another request would otherwise still be talking
     * to the copy the first request already holds.
     */
    protected function startNewRequest(): void
    {
        $this->app['auth']->forgetGuards();

        // The storefront's catalogue reads are remembered for the length of one
        // request; a test that edits and then looks again is a new request.
        $this->app->forgetScopedInstances();

        // Someone signed in through `signInAs()` is still signed in — as they
        // are now in the database, which is how a real request would see them.
        if ($this->signedInStaff !== null) {
            $this->actingAs($this->signedInStaff->fresh(), 'staff');
        }
    }
}
