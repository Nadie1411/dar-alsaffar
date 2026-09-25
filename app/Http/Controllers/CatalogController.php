<?php

namespace App\Http\Controllers;

use App\Services\Overzaki\CatalogService;
use App\Support\Nav;
use Illuminate\Http\Request;

class CatalogController extends Controller
{
    protected const PER_PAGE = 12;

    public function __construct(protected CatalogService $catalog) {}

    public function index(Request $request)
    {
        return $this->renderListing($request, [
            'title' => __('storefront.listing.allProducts'),
            'lede' => __('storefront.listing.allLede'),
            'crumbs' => [],
        ]);
    }

    public function category(Request $request, string $locale, string $slug)
    {
        $category = $this->catalog->category($slug);

        abort_if($category === null, 404);

        $request->merge(['category' => [$slug]]);

        return $this->renderListing($request, [
            'title' => $category['name'],
            'lede' => null,
            'image' => $category['image'] ?? null,
            'category' => $category,
            'crumbs' => [
                ['label' => __('storefront.nav.collections'), 'url' => Nav::url('categories')],
                ['label' => $category['name']],
            ],
        ]);
    }

    public function offers(Request $request)
    {
        $request->merge(['offers' => 1]);

        return $this->renderListing($request, [
            'title' => __('storefront.nav.offers'),
            'lede' => __('storefront.home.offersTitle'),
            'crumbs' => [['label' => __('storefront.nav.offers')]],
        ]);
    }

    public function bestSellers(Request $request)
    {
        $request->merge(['sort' => 'recommended']);

        return $this->renderListing($request, [
            'title' => __('storefront.nav.bestSellers'),
            'lede' => __('storefront.home.bestLede'),
            'crumbs' => [['label' => __('storefront.nav.bestSellers')]],
        ]);
    }

    public function categories()
    {
        return view('pages.categories', [
            'collections' => $this->catalog->categoriesWithCounts(),
        ]);
    }

    public function show(string $locale, string $slug)
    {
        $product = $this->catalog->find($slug);

        abort_if($product === null, 404);

        return view('pages.product', [
            'product' => $product,
            'related' => $this->catalog->related($product->id(), 4),
        ]);
    }

    /** Shared rendering for every product listing view. */
    protected function renderListing(Request $request, array $context)
    {
        $filters = [
            'q' => $request->string('q')->toString() ?: null,
            'category' => (array) $request->input('category', []),
            'min' => $request->input('min'),
            'max' => $request->input('max'),
            'availability' => $request->input('availability'),
            'offers' => $request->boolean('offers'),
            'sort' => $request->input('sort', 'recommended'),
        ];

        $products = $this->catalog->filterable(
            $filters,
            self::PER_PAGE,
            max(1, (int) $request->input('page', 1))
        );

        return view('pages.listing', array_merge($context, [
            'products' => $products,
            'filters' => $filters,
            'categories' => $this->catalog->categoriesWithCounts(),
            'priceRange' => $this->catalog->priceRange(),
        ]));
    }
}
