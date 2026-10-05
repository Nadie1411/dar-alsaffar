<?php

namespace App\Http\Controllers;

use App\Contracts\Store\Customers;
use App\Contracts\Store\Orders;
use App\Contracts\Store\Wishlist;
use App\Support\Nav;
use App\Support\Shopper;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(
        protected Customers $auth,
        protected Wishlist $wishlist,
        protected Orders $orders,
    ) {}

    public function showLogin()
    {
        return Shopper::check()
            ? redirect(Nav::url('account'))
            : view('pages.auth.login');
    }

    public function login(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email:rfc'],
            'password' => ['required', 'string'],
        ]);

        $result = $this->auth->login($validated['email'], $validated['password']);

        if (! $result['ok']) {
            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => $result['message'] ?? __('storefront.auth.failed')]);
        }

        // Anything saved as a guest follows them into the account.
        $this->wishlist->mergeGuestList();

        return redirect($this->intended())->with('status', __('storefront.auth.login'));
    }

    public function showRegister()
    {
        return Shopper::check()
            ? redirect(Nav::url('account'))
            : view('pages.auth.register');
    }

    public function register(Request $request)
    {
        $phoneLen = (int) config('brand.country.phone_len');

        $validated = $request->validate([
            'fullName' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190'],
            'phone' => ['required', 'string', 'regex:/^[1-9][0-9]{'.($phoneLen - 1).'}$/'],
            'password' => ['required', 'string', 'min:8', 'max:64'],
        ], [
            'phone.regex' => __('storefront.checkout.phoneHint'),
        ]);

        $result = $this->auth->register([
            'fullName' => $validated['fullName'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'phoneNumber' => $this->orders->e164($validated['phone']),
        ]);

        if (! $result['ok']) {
            return back()
                ->withInput($request->except('password'))
                ->withErrors(['email' => $result['message'] ?? __('storefront.auth.registerFailed')]);
        }

        if (! empty($result['authenticated'])) {
            $this->wishlist->mergeGuestList();

            return redirect($this->intended())->with('status', __('storefront.auth.registered'));
        }

        // The tenant requires verification before a token is issued, so send
        // them to sign in rather than pretending they are already signed in.
        return redirect(Nav::url('login'))->with('status', __('storefront.auth.registered'));
    }

    public function showForgot()
    {
        return view('pages.auth.forgot');
    }

    public function forgot(Request $request)
    {
        $validated = $request->validate(['email' => ['required', 'email:rfc']]);

        $this->auth->forgotPassword($validated['email']);

        // The same message either way, so the form cannot be used to discover
        // which addresses are registered.
        return back()->with('status', __('storefront.auth.forgotSent'));
    }

    public function logout()
    {
        $this->auth->logout();

        return redirect(Nav::url())->with('status', __('storefront.auth.loggedOut'));
    }

    protected function intended(): string
    {
        return session()->pull('url.intended', Nav::url('account'));
    }
}
