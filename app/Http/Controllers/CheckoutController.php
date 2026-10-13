<?php

namespace App\Http\Controllers;

use App\Contracts\Store\Cart;
use App\Contracts\Store\Orders;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Services\Overzaki\CartQuote;
use App\Services\Settings;
use App\Services\Store\Payments\GatewayConfig;
use App\Services\Store\Payments\MyFatoorahException;
use App\Services\Store\Payments\PaymentOutcome;
use App\Services\Store\Payments\PaymentReturn;
use App\Services\Store\Payments\PaymentService;
use App\Support\Money;
use App\Support\Nav;
use App\Support\Shopper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class CheckoutController extends Controller
{
    public function __construct(
        protected Cart $cart,
        protected Orders $orders,
        protected Settings $settings,
        protected PaymentService $payments,
        protected PaymentReturn $return,
        protected GatewayConfig $gateway,
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

        $customer = Shopper::customer();

        return view('pages.checkout', [
            'quote' => $quote,
            'cities' => $this->orders->deliveryLocations(),
            'methods' => $this->orders->paymentMethods(),
            'customer' => $customer,
            'phoneLen' => config('brand.country.phone_len'),
            'dial' => config('brand.country.dial'),
            'addons' => $this->orders->addonsEnabled() ? $this->orders->serviceAddons() : [],
            'addonsRequired' => $this->orders->addonsRequired(),
            'codEnabled' => $this->codOffered($quote),
            // Apple Pay only works on an Apple device, so when it is paid with the
            // button on this site the choice is hidden from everyone else.
            'applePayOnSite' => $this->payments->takesOnSite('APPLE_PAY'),
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
            // Optional: it is only used to send the shopper their order updates.
            'email' => ['nullable', 'email:rfc', 'max:190'],
            // Kuwaiti mobile numbers are 8 digits and never start with 0.
            'phone' => ['required', 'string', 'regex:/^[1-9][0-9]{'.($phoneLen - 1).'}$/'],
            'city' => ['required', 'string', Rule::in($cities->pluck('id')->all())],
            'area' => ['required', 'string'],
            // The courier needs these three; the rest of the address is optional.
            'block' => ['required', 'string', 'max:40'],
            'street' => ['required', 'string', 'max:120'],
            'building' => ['required', 'string', 'max:60'],
            'avenue' => ['nullable', 'string', 'max:60'],
            'floor' => ['nullable', 'string', 'max:30'],
            'apartment' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:500'],
            'payment' => ['required', Rule::in(
                $this->settings->bool('checkout.cod', true) ? ['cod', 'online'] : ['online']
            )],
            'paymentMethod' => ['nullable', 'required_if:payment,online', Rule::in(
                collect($this->orders->paymentMethods())->pluck('id')->all()
            )],
            'addons' => ['nullable', 'array'],
            'addons.*' => ['nullable', 'string'],
        ], [
            'phone.regex' => __('storefront.checkout.phoneHint'),
            'block.required' => __('storefront.errors.required'),
            'street.required' => __('storefront.errors.required'),
            'building.required' => __('storefront.errors.required'),
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

        // Only clear the basket once the order exists — and, for an online
        // payment, once it has been paid: an abandoned payment page must not
        // cost the shopper their basket.
        if ($result['clearCart'] ?? true) {
            $this->cart->clear();
        }

        if ($result['kind'] === 'redirect') {
            return redirect()->away($result['paymentUrl']);
        }

        return redirect(Nav::url('checkout/thanks/'.($result['orderId'] ?? '')));
    }

    /**
     * Whether to offer cash on delivery. The shop can hide it from the panel,
     * but it is never offered when Overzaki itself has it switched off — the
     * order would be refused after the shopper had chosen it.
     */
    protected function codOffered(CartQuote $quote): bool
    {
        return $this->settings->bool('checkout.cod', true) && $quote->supportsCashOnDelivery();
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

    public function failed(Request $request)
    {
        return view('pages.checkout-failed', [
            // Offered only to the shopper whose order it is.
            'retryUrl' => ($order = $this->payableOrder((string) $request->query('order', session('payment.order'))))
                ? Nav::url('checkout/pay/'.$order->number)
                : null,
        ]);
    }

    /** Payment is not confirmed yet — MyFatoorah could not be asked, or the order needs a person's attention. */
    public function pending(string $locale, ?string $number = null)
    {
        return view('pages.checkout-pending', ['orderId' => $number ?: null]);
    }

    /** Back to the payment page for an order that has not been paid. */
    public function pay(string $locale, string $number)
    {
        $order = $this->payableOrder($number);

        abort_if($order === null, 404);

        try {
            $payment = $this->payments->resume($order);
        } catch (MyFatoorahException) {
            return redirect(Nav::url('checkout/failed'));
        }

        session()->put('payment.order', $order->number);

        return redirect()->away($payment->mf_payment_url);
    }

    /**
     * The page with the Apple Pay button, for an order placed with Apple Pay and
     * not yet paid. The button is MyFatoorah's, drawn here; what it takes is
     * checked with MyFatoorah afterwards (applePayConfirm), not believed.
     */
    public function applePay(string $locale, string $number)
    {
        $order = $this->ownOrder($number);

        abort_if($order === null, 404);

        // Back on this page after paying, say from the browser's back button.
        if ($order->payment_status === PaymentStatus::Paid) {
            return redirect(Nav::url('checkout/thanks/'.$order->number));
        }

        abort_if($order->status !== OrderStatus::PendingPayment || ! $this->gateway->takesApplePayOnSite(), 404);

        try {
            // A shopper who paid and then reloaded must be taken on, not offered a second payment.
            $earlier = $this->payments->pendingApplePay($order);
            $outcome = $earlier === null ? null : $this->payments->checkSession($earlier);

            if ($outcome !== null && ($outcome->isPaid() || $outcome->result === PaymentOutcome::PENDING)) {
                return redirect($this->return->destination($outcome));
            }

            $payment = $this->payments->applePaySession($order);
        } catch (MyFatoorahException) {
            $payment = null;
        }

        session()->put('payment.order', $order->number);

        return view('pages.apple-pay', [
            'order' => $order,
            'total' => Money::format(Money::fromFils($order->total_fils), Money::KWD),
            'sessionId' => $payment?->mf_session_id,
            'sdkUrl' => $this->gateway->sdkUrl(),
            'confirmUrl' => Nav::url('checkout/apple-pay/'.$order->number.'/confirm'),
            'otherWayUrl' => Nav::url('checkout/pay/'.$order->number),
        ]);
    }

    /**
     * Called by the page once the Apple Pay button says it is done. What it said
     * is not looked at: MyFatoorah is asked about our own session, and the shopper
     * is sent where the answer says.
     */
    public function applePayConfirm(string $locale, string $number): JsonResponse
    {
        $order = $this->ownOrder($number);

        abort_if($order === null, 404);

        $payment = $order->payments()->whereNotNull('mf_session_id')->latest('id')->first();

        abort_if($payment === null, 404);

        try {
            $outcome = $this->payments->checkSession($payment);
        } catch (MyFatoorahException) {
            // MyFatoorah could not be asked. Nothing is decided; the webhook or
            // the reconcile job will settle it, and the shopper is told so.
            return response()->json(['result' => PaymentOutcome::PENDING, 'redirect' => Nav::url('checkout/pending/'.$order->number)]);
        }

        if ($outcome === null) {
            return response()->json(['result' => 'unpaid']);
        }

        return response()->json(['result' => $outcome->result, 'redirect' => $this->return->destination($outcome)]);
    }

    /**
     * An order still waiting for its payment, if the person asking is the one
     * who placed it: the same browser session, or the signed-in account.
     */
    protected function payableOrder(string $number): ?Order
    {
        $order = $this->ownOrder($number);

        return $order !== null && $order->status === OrderStatus::PendingPayment ? $order : null;
    }

    /**
     * An order, if the person asking is the one who placed it: the same browser
     * session, or the signed-in account — whatever has become of it since.
     */
    protected function ownOrder(string $number): ?Order
    {
        if ($number === '') {
            return null;
        }

        $order = Order::query()->where('number', $number)->first();

        if ($order === null) {
            return null;
        }

        $customerId = Auth::guard(Shopper::GUARD)->id();
        $isTheirs = session('payment.order') === $order->number
            || ($customerId !== null && $customerId === $order->customer_id);

        return $isTheirs ? $order : null;
    }
}
