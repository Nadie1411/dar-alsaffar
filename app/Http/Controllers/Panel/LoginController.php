<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Http\Middleware\PanelAuth;
use App\Services\Store\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LoginController extends Controller
{
    /** Failed attempts allowed per minute for one e-mail address from one place. */
    protected const ATTEMPTS = 5;

    /** How long "keep me signed in" lasts for staff, in minutes: thirty days, not the framework's year and more. */
    protected const REMEMBER_MINUTES = 43_200;

    public function __construct(protected ActivityLogger $log) {}

    public function show(): View|RedirectResponse
    {
        $user = Auth::guard('staff')->user();

        if ($user !== null && $user->is_active) {
            return redirect()->route('panel.dashboard');
        }

        return view('panel.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        // Addresses are stored in lower case, and the database compares them as written.
        $credentials['email'] = Str::lower(trim($credentials['email']));
        $throttleKey = $credentials['email'].'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, self::ATTEMPTS)) {
            throw ValidationException::withMessages([
                'email' => __('panel.login.throttled', ['seconds' => RateLimiter::availableIn($throttleKey)]),
            ]);
        }

        $guard = Auth::guard('staff');
        $guard->setRememberDuration(self::REMEMBER_MINUTES);

        // An account that has been switched off is refused here, with the same
        // answer as a wrong password, so the form cannot be used to find out
        // which addresses belong to staff.
        if (! $guard->attempt($credentials + ['is_active' => true], $request->boolean('remember'))) {
            RateLimiter::hit($throttleKey, 60);

            throw ValidationException::withMessages(['email' => __('panel.login.failed')]);
        }

        RateLimiter::clear($throttleKey);

        $request->session()->regenerate();

        $user = $guard->user();
        $user->forceFill(['last_login_at' => now()])->save();

        $request->session()->put([
            'panel.locale' => $user->locale,
            PanelAuth::SESSION_KEY => PanelAuth::fingerprint($user),
        ]);

        $this->log->record($user, 'auth.login', $user);

        $intended = $request->session()->pull('panel.intended');

        return redirect()->to(
            is_string($intended) && str_starts_with($intended, url('/panel')) ? $intended : route('panel.dashboard')
        );
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('staff')->logout();

        // Only the panel's own keys go: a shopper using the same browser keeps
        // their basket and their account.
        $request->session()->forget(['panel.locale', 'panel.intended', PanelAuth::SESSION_KEY]);
        $request->session()->regenerate();

        return redirect()->route('panel.login');
    }
}
