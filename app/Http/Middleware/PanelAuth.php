<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets only signed-in, active staff into the panel.
 *
 * Two things end a session on the very next request rather than at the next
 * login: the account being switched off, and its password being changed —
 * so whoever is still signed in somewhere else after a reset is signed out.
 */
class PanelAuth
{
    public const SESSION_KEY = 'panel.credential';

    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('staff');
        $user = $guard->user();

        if ($user === null || ! $user->is_active || ! $this->credentialIsCurrent($request, $user)) {
            if ($user !== null) {
                $guard->logout();
            }

            // The order alert polls for JSON in the background: it needs to be
            // told it is signed out, not handed the login page.
            if ($request->expectsJson()) {
                abort(401);
            }

            $request->session()->put('panel.intended', $request->fullUrl());

            return redirect()->route('panel.login');
        }

        return $next($request);
    }

    /**
     * What a session remembers of the password it was opened with. Not the
     * hash itself: a keyed digest of it, so the session store never holds it.
     */
    public static function fingerprint(User $user): string
    {
        return hash_hmac('sha256', (string) $user->getAuthPassword(), (string) config('app.key'));
    }

    /**
     * A session with nothing remembered — one restored from a "remember me"
     * cookie, which Laravel has already checked against the password — adopts
     * the current one. After that, any change shows.
     */
    protected function credentialIsCurrent(Request $request, User $user): bool
    {
        $remembered = $request->session()->get(self::SESSION_KEY);

        if ($remembered === null) {
            $request->session()->put(self::SESSION_KEY, self::fingerprint($user));

            return true;
        }

        return hash_equals((string) $remembered, self::fingerprint($user));
    }
}
