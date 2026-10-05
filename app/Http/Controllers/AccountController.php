<?php

namespace App\Http\Controllers;

use App\Contracts\Store\Customers;
use App\Contracts\Store\Orders;
use App\Contracts\Store\Wishlist;
use App\Support\Nav;
use App\Support\Shopper;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    public function __construct(
        protected Orders $orders,
        protected Wishlist $wishlist,
        protected Customers $customers,
    ) {}

    public function index()
    {
        if ($guard = $this->requireLogin()) {
            return $guard;
        }

        $orders = $this->orders->myOrders();

        return view('pages.account.index', [
            'customer' => Shopper::customer(),
            'recentOrders' => array_slice($orders, 0, 3),
            'orderCount' => count($orders),
            'wishCount' => $this->wishlist->count(),
        ]);
    }

    public function orders()
    {
        if ($guard = $this->requireLogin()) {
            return $guard;
        }

        return view('pages.account.orders', ['orders' => $this->orders->myOrders()]);
    }

    public function order(string $locale, string $id)
    {
        if ($guard = $this->requireLogin()) {
            return $guard;
        }

        $order = $this->orders->findOrder($id);

        abort_if($order === null, 404);

        return view('pages.account.order', ['order' => $order]);
    }

    public function addresses()
    {
        if ($guard = $this->requireLogin()) {
            return $guard;
        }

        return view('pages.account.addresses', ['addresses' => $this->customers->addresses()]);
    }

    public function settings()
    {
        if ($guard = $this->requireLogin()) {
            return $guard;
        }

        return view('pages.account.settings', ['customer' => Shopper::customer()]);
    }

    public function update(Request $request)
    {
        if ($guard = $this->requireLogin()) {
            return $guard;
        }

        $validated = $request->validate([
            'fullName' => ['required', 'string', 'min:2', 'max:120'],
        ]);

        $result = $this->customers->updateProfile($validated);

        if (! $result['ok']) {
            return back()->withErrors(['fullName' => $result['message'] ?? __('storefront.errors.generic')]);
        }

        return back()->with('status', __('storefront.account.saved'));
    }

    protected function requireLogin()
    {
        if (Shopper::check()) {
            return null;
        }

        session()->put('url.intended', request()->fullUrl());

        return redirect(Nav::url('login'))->with('status', __('storefront.auth.loginRequired'));
    }
}
