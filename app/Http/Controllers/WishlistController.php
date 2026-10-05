<?php

namespace App\Http\Controllers;

use App\Contracts\Store\Wishlist;
use Illuminate\Http\Request;

class WishlistController extends Controller
{
    public function __construct(protected Wishlist $wishlist) {}

    public function index()
    {
        return view('pages.wishlist', [
            'products' => $this->wishlist->products(),
        ]);
    }

    public function toggle(Request $request, string $locale, string $productId)
    {
        $saved = $this->wishlist->toggle($productId);

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'ok' => true,
                'saved' => $saved,
                'count' => $this->wishlist->count(),
            ]);
        }

        return back()->with('status', $saved
            ? __('storefront.wishlist.added')
            : __('storefront.wishlist.removed'));
    }
}
