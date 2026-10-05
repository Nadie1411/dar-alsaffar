<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Support\Nav;
use App\Support\Shopper;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * The page a customer lands on from the reset email. It exists only when
 * accounts live here — with Overzaki holding them, resetting is Overzaki's job.
 */
class PasswordResetController extends Controller
{
    public function show(Request $request, string $locale, string $token)
    {
        abort_unless(Shopper::usesLocalAccounts(), 404);

        return view('pages.auth.reset', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    public function update(Request $request)
    {
        abort_unless(Shopper::usesLocalAccounts(), 404);

        $validated = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email:rfc'],
            'password' => ['required', 'string', 'min:8', 'max:64', 'confirmed'],
        ]);

        $status = Password::broker('customers')->reset(
            [
                'email' => Str::lower($validated['email']),
                'token' => $validated['token'],
                'password' => $validated['password'],
                'password_confirmation' => $request->input('password_confirmation'),
            ],
            function (Customer $customer, string $password): void {
                $customer->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($customer));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => __('storefront.auth.resetFailed')]);
        }

        return redirect(Nav::url('login'))->with('status', __('storefront.auth.resetDone'));
    }
}
