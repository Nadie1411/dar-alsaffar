<?php

namespace App\Http\Controllers;

use App\Services\Overzaki\CatalogService;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function __construct(protected CatalogService $catalog) {}

    public function index(Request $request)
    {
        $term = trim($request->string('q')->toString());

        return view('pages.search', [
            'term' => $term,
            'products' => $term === '' ? [] : $this->catalog->search($term, 48),
            'categories' => $this->catalog->categoriesWithCounts(),
        ]);
    }

    /** Type-ahead fragment for the search overlay. */
    public function suggest(Request $request)
    {
        $term = trim($request->string('q')->toString());
        $products = $term === '' ? [] : $this->catalog->search($term, 8);

        return response()
            ->view('partials.search-results', compact('term', 'products'))
            ->header('Cache-Control', 'no-store');
    }
}
