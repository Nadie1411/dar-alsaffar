<?php

namespace App\Http\Controllers;

use App\Contracts\Store\Cart;
use App\Contracts\Store\Catalog;
use App\Services\Overzaki\DTO\Product;
use App\Support\Nav;
use Illuminate\Http\Request;

/**
 * Packages ("البكجات").
 *
 * A package is a real product in the catalogue whose checkbox option group
 * says how many items the customer picks — the same mechanism the previous
 * storefront used, so packages stay editable from the Overzaki dashboard.
 * Nothing here invents a package that the store has not published.
 */
class BundleController extends Controller
{
    public function __construct(
        protected Catalog $catalog,
        protected Cart $cart,
    ) {}

    public function index()
    {
        return view('pages.packages', [
            'packages' => array_values(array_filter(
                $this->catalog->all(),
                fn (Product $product) => $product->isBundle()
            )),
        ]);
    }

    public function show(string $locale, string $slug)
    {
        $product = $this->catalog->find($slug);

        abort_if($product === null, 404);

        // A product without a choice group is an ordinary product page.
        if (! $product->isBundle()) {
            return redirect(Nav::url('products/'.$slug));
        }

        return view('pages.package', [
            'product' => $product,
            'group' => $product->bundleGroup(),
        ]);
    }

    public function add(Request $request, string $locale, string $slug)
    {
        $product = $this->catalog->find($slug);

        abort_if($product === null || ! $product->isBundle(), 404);

        $group = $product->bundleGroup();
        $allowed = collect($group['values'])->pluck('id');

        $validated = $request->validate([
            'values' => ['required', 'array', 'size:'.$group['max']],
            'values.*' => ['required', 'string', 'in:'.$allowed->implode(',')],
        ], [
            'values.size' => __('storefront.bundle.remaining', ['count' => $group['max']]),
        ]);

        $this->cart->add($product->id(), 1, null, [[
            'optionId' => $group['id'],
            // Priced option values are rejected without a quantity, and free
            // ones accept it, so every value carries one.
            'values' => array_map(
                fn ($id) => ['valueId' => $id, 'quantity' => 1],
                array_values($validated['values'])
            ),
        ]]);

        return redirect(Nav::url('cart'))
            ->with('status', __('storefront.cart.added', ['name' => $product->name()]));
    }
}
