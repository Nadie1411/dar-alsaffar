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
 * A package is a real product in the catalogue. A ready-made one is sold
 * exactly like any other, from its product page. A set the customer builds is
 * a product too — its price is the set's price — but its pieces are picked on
 * a page of its own, here, and go into the bag as one line.
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

        // A ready-made package is an ordinary product page.
        if (! $product->isSetBuilder()) {
            return redirect(Nav::url('products/'.$slug), 301);
        }

        return view('pages.package', [
            'product' => $product,
            'group' => $product->setGroup(),
            'available' => $product->inStock() && $product->canBuildSet(),
        ]);
    }

    public function add(Request $request, string $locale, string $slug)
    {
        $product = $this->catalog->find($slug);

        abort_if($product === null || ! $product->isSetBuilder(), 404);

        if (! $product->inStock() || ! $product->canBuildSet()) {
            return back()->withErrors(['values' => __('storefront.bundle.unavailable')]);
        }

        $group = $product->setGroup();
        $allowed = collect($group['values'])->reject(fn (array $value) => $value['soldOut'])->pluck('id');

        $validated = $request->validate([
            'values' => ['required', 'array', 'size:'.$group['max']],
            'values.*' => ['required', 'string', 'distinct', 'in:'.$allowed->implode(',')],
        ], [
            'values.required' => __('storefront.bundle.remaining', ['count' => $group['max']]),
            'values.size' => __('storefront.bundle.remaining', ['count' => $group['max']]),
            'values.*.in' => __('storefront.bundle.unavailable'),
        ]);

        // In the set's own order, so the same pieces picked in another order are
        // the same line in the bag. Each is chosen once, whatever it costs: the
        // price is the set's.
        $picked = collect($group['values'])->pluck('id')->intersect($validated['values'])->values();

        $this->cart->add($product->id(), 1, null, [[
            'optionId' => $group['id'],
            'values' => $picked->map(fn (string $id) => ['valueId' => $id, 'quantity' => 1])->all(),
        ]]);

        return redirect(Nav::url('cart'))
            ->with('status', __('storefront.cart.added', ['name' => $product->name()]));
    }
}
