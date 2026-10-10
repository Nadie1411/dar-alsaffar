<?php

namespace App\Http\Controllers;

use App\Contracts\Store\Catalog;
use App\Services\Overzaki\DTO\Product;
use App\Support\Nav;

/**
 * Packages ("البكجات").
 *
 * A package is a real product in the catalogue, sold exactly like any other:
 * the shopper picks nothing, so it has no page of its own — its product page
 * is the page. This lists them, and keeps the old package links working.
 */
class BundleController extends Controller
{
    public function __construct(protected Catalog $catalog) {}

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
        abort_if($this->catalog->find($slug) === null, 404);

        return redirect(Nav::url('products/'.$slug), 301);
    }
}
