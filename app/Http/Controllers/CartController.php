<?php

namespace App\Http\Controllers;

use App\Contracts\Store\Cart;
use App\Contracts\Store\Catalog;
use App\Support\Nav;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function __construct(
        protected Cart $cart,
        protected Catalog $catalog,
    ) {}

    public function index()
    {
        return view('pages.cart', [
            'quote' => $this->cart->quote(),
            'suggestions' => $this->catalog->bestSellers(4),
        ]);
    }

    public function add(Request $request)
    {
        $validated = $request->validate([
            'productId' => ['required', 'string'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:99'],
            'varientId' => ['nullable', 'string'],
            'options' => ['nullable', 'array'],
            'options.*.optionId' => ['required', 'string'],
            'options.*.values' => ['required', 'array', 'min:1'],
            'options.*.values.*.valueId' => ['required', 'string'],
            'options.*.values.*.quantity' => ['nullable', 'integer', 'min:1', 'max:99'],
        ]);

        $this->cart->add(
            $validated['productId'],
            (int) ($validated['quantity'] ?? 1),
            $validated['varientId'] ?? null,
            // Option ids are validated upstream by the cart checker, which
            // rejects anything that does not belong to the product.
            $this->normaliseOptions($validated['options'] ?? [])
        );

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'ok' => true,
                'count' => $this->cart->count(),
            ]);
        }

        return back()->with('status', __('storefront.cart.updated'));
    }

    /**
     * Every chosen value needs a quantity: the cart checker rejects a priced
     * option value without one.
     */
    protected function normaliseOptions(array $groups): array
    {
        return array_values(array_map(fn (array $group) => [
            'optionId' => $group['optionId'],
            'values' => array_values(array_map(fn (array $value) => [
                'valueId' => $value['valueId'],
                'quantity' => (int) ($value['quantity'] ?? 1),
            ], $group['values'])),
        ], $groups));
    }

    public function update(Request $request, string $locale, string $key)
    {
        $changed = $this->cart->updateQuantity($key, (int) $request->input('quantity', 1));

        return $this->respond($request, __('storefront.cart.updated'), $changed);
    }

    public function remove(Request $request, string $locale, string $key)
    {
        $changed = $this->cart->remove($key);

        return $this->respond($request, __('storefront.cart.removed'), $changed);
    }

    /** The drawer body, rendered as an HTML fragment. */
    public function drawer()
    {
        return response()
            ->view('partials.cart-drawer-body', ['quote' => $this->cart->quote()])
            ->header('Cache-Control', 'no-store');
    }

    public function voucher(Request $request)
    {
        $code = trim($request->string('voucher')->toString());

        // The voucher is validated by the API's own checker, never locally.
        $quote = $this->cart->quote($code === '' ? [] : ['voucher' => $code]);

        if ($code !== '' && ! $quote->voucherAccepted()) {
            return back()->withErrors(['voucher' => $quote->voucherMessage() ?: __('storefront.errors.generic')]);
        }

        session()->put('cart.voucher', $code ?: null);

        return back()->with('status', __('storefront.cart.updated'));
    }

    /**
     * A line the session no longer holds must not report success — that is how
     * a broken quantity control looks like a working one.
     */
    protected function respond(Request $request, string $message, bool $changed = true)
    {
        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'ok' => $changed,
                'count' => $this->cart->count(),
            ], $changed ? 200 : 422);
        }

        if (! $changed) {
            return redirect(Nav::url('cart'))->with('checkoutBlocked', __('storefront.cart.unavailable'));
        }

        return redirect(Nav::url('cart'))->with('status', $message);
    }
}
