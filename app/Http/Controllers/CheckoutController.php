<?php

namespace App\Http\Controllers;

use App\Services\Overzaki\AuthService;
use App\Services\Overzaki\CartService;
use App\Services\Overzaki\OrderService;
use App\Support\Nav;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CheckoutController extends Controller
{
    public function __construct(
        protected CartService $cart,
        protected OrderService $orders,
    ) {}

    public function index()
    {
        // Bouncing silently back to the cart looks like a dead button, so say
        // why. The same applies when the API will not accept the basket.
        if ($this->cart->isEmpty()) {
            return redirect(Nav::url('cart'))->with('status', __('storefront.cart.empty'));
        }

        $quote = $this->cart->quote();

        if ($quote->hasError() || ! $quote->canPlaceOrder()) {
            return redirect(Nav::url('cart'))->with('checkoutBlocked',
                $quote->error ?? $quote->problems()[0] ?? __('storefront.checkout.blocked')
            );
        }

        $customer = AuthService::customer();

        return view('pages.checkout', [
            'quote' => $quote,
            'cities' => $this->orders->deliveryLocations(),
            'methods' => $this->orders->paymentMethods(),
            'customer' => $customer,
            'phoneLen' => config('brand.country.phone_len'),
            'dial' => config('brand.country.dial'),
            'addons' => $this->orders->addonsEnabled() ? $this->orders->serviceAddons() : [],
            'addonsRequired' => $this->orders->addonsRequired(),
        ]);
    }

    public function store(Request $request)
    {
        if ($this->cart->isEmpty()) {
            return redirect(Nav::url('cart'))->with('status', __('storefront.cart.empty'));
        }

        $cities = collect($this->orders->deliveryLocations());
        $phoneLen = (int) config('brand.country.phone_len');

        $validated = $request->validate([
            'fullName' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190'],
            // Kuwaiti mobile numbers are 8 digits and never start with 0.
            'phone' => ['required', 'string', 'regex:/^[1-9][0-9]{'.($phoneLen - 1).'}$/'],
            'city' => ['required', 'string', Rule::in($cities->pluck('id')->all())],
            'area' => ['required', 'string'],
            'block' => ['nullable', 'string', 'max:40'],
            'street' => ['nullable', 'string', 'max:120'],
            'building' => ['nullable', 'string', 'max:60'],
            'floor' => ['nullable', 'string', 'max:30'],
            'apartment' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:500'],
            'payment' => ['required', Rule::in(['cod', 'online'])],
            'paymentMethod' => ['nullable', 'required_if:payment,online', Rule::in(
                collect($this->orders->paymentMethods())->pluck('id')->all()
            )],
            'policy' => ['accepted'],
            'addons' => ['nullable', 'array'],
            'addons.*' => ['nullable', 'string'],
        ], [
            'phone.regex' => __('storefront.checkout.phoneHint'),
            'policy.accepted' => __('storefront.errors.required'),
        ]);

        // The chosen area must belong to the chosen governorate.
        $areaIds = collect($cities->firstWhere('id', $validated['city'])['areas'] ?? [])->pluck('id');

        if (! $areaIds->contains($validated['area'])) {
            return back()->withInput()->withErrors(['area' => __('storefront.errors.required')]);
        }

        $validated['voucher'] = session('cart.voucher');
        // An empty string is the "no wrapping" choice, not an add-on id.
        $validated['addons'] = array_values(array_filter((array) ($validated['addons'] ?? [])));

        $result = $this->orders->place($validated);

        if (! $result['ok']) {
            return back()->withInput()->withErrors(['checkout' => $result['message'] ?? __('storefront.checkout.failed')]);
        }

        // Only clear the basket once the order exists upstream.
        $this->cart->clear();

        if ($result['kind'] === 'redirect') {
            return redirect()->away($result['paymentUrl']);
        }

        return redirect(Nav::url('checkout/thanks/'.($result['orderId'] ?? '')));
    }

    /** Areas for the selected governorate, for the dependent select. */
    public function areas(string $locale, ?string $cityId = null)
    {
        $city = collect($this->orders->deliveryLocations())->firstWhere('id', $cityId);

        return response()->json($city['areas'] ?? []);
    }

    public function thanks(string $locale, ?string $order = null)
    {
        return view('pages.thanks', ['orderId' => $order ?: null]);
    }

    public function failed()
    {
        return view('pages.checkout-failed');
    }
}
