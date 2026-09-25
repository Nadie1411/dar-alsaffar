<?php

namespace App\Http\Controllers;

use App\Services\Overzaki\AuthService;
use App\Services\Overzaki\OrderService;
use App\Services\Overzaki\OverzakiClient;
use App\Services\Overzaki\WishlistService;
use App\Support\Nav;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    public function __construct(
        protected OrderService $orders,
        protected WishlistService $wishlist,
        protected OverzakiClient $client,
    ) {}

    public function index()
    {
        if ($guard = $this->requireLogin()) {
            return $guard;
        }

        $orders = $this->orders->myOrders();

        return view('pages.account.index', [
            'customer' => AuthService::customer(),
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

        $response = $this->client->withToken(AuthService::token())
            ->get(config('overzaki.endpoints.myAddresses'));

        return view('pages.account.addresses', [
            'addresses' => $response['data'] ?? (is_array($response) ? $response : []),
        ]);
    }

    public function settings()
    {
        if ($guard = $this->requireLogin()) {
            return $guard;
        }

        return view('pages.account.settings', ['customer' => AuthService::customer()]);
    }

    public function update(Request $request)
    {
        if ($guard = $this->requireLogin()) {
            return $guard;
        }

        $validated = $request->validate([
            'fullName' => ['required', 'string', 'min:2', 'max:120'],
        ]);

        $response = $this->client->withToken(AuthService::token())
            ->postRaw('/customers/update_profile', $validated);

        if (! $response['ok']) {
            return back()->withErrors(['fullName' => $response['message'] ?? __('storefront.errors.generic')]);
        }

        session()->put('overzaki.customer', array_merge(AuthService::customer() ?? [], $validated));

        return back()->with('status', __('storefront.account.saved'));
    }

    protected function requireLogin()
    {
        if (AuthService::check()) {
            return null;
        }

        session()->put('url.intended', request()->fullUrl());

        return redirect(Nav::url('login'))->with('status', __('storefront.auth.loginRequired'));
    }
}
