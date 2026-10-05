<?php

namespace App\Http\Controllers;

use App\Contracts\Store\Catalog;

class HomeController extends Controller
{
    public function __construct(protected Catalog $catalog) {}

    public function index()
    {
        $bestSellers = $this->catalog->bestSellers(8);

        return view('pages.home', [
            'collections' => $this->catalog->categoriesWithCounts(),
            'bestSellers' => $bestSellers,
            'offers' => $this->catalog->onOffer(4),
            'newArrivals' => $this->catalog->newArrivals(4),
            // The hero leans on the strongest product photograph we actually
            // have, rather than a stock image that is not the brand's.
            'heroProduct' => $bestSellers[0] ?? null,
        ]);
    }
}
