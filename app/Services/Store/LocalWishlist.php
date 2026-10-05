<?php

namespace App\Services\Store;

use App\Contracts\Store\Catalog;
use App\Contracts\Store\Wishlist;
use App\Models\Product;
use App\Models\WishlistItem;
use App\Services\Overzaki\DTO\Product as ProductView;
use App\Support\Shopper;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

/**
 * Saved products. A signed-in customer's list is kept in the database so it
 * follows them between devices; a guest's lives in the session and is merged
 * into the account the moment they sign in.
 *
 * One instance serves a whole request, and the product cards on a listing ask
 * about every product in turn, so the list is read once and remembered.
 */
class LocalWishlist implements Wishlist
{
    protected const GUEST_KEY = 'wishlist.ids';

    /** @var array<int,string>|null */
    protected ?array $ids = null;

    public function __construct(protected Catalog $catalog) {}

    protected function customerId(): ?int
    {
        return Auth::guard(Shopper::GUARD)->id();
    }

    /** @return array<int,string> */
    public function ids(): array
    {
        if ($this->ids !== null) {
            return $this->ids;
        }

        $customerId = $this->customerId();

        return $this->ids = $customerId === null
            ? array_values(Session::get(self::GUEST_KEY, []))
            : WishlistItem::query()
                ->where('customer_id', $customerId)
                ->orderBy('id')
                ->pluck('product_id')
                ->map(fn ($id) => (string) $id)
                ->all();
    }

    public function has(string $productId): bool
    {
        return in_array($productId, $this->ids(), true);
    }

    public function count(): int
    {
        return count($this->ids());
    }

    /** @return array<int,ProductView> */
    public function products(): array
    {
        $ids = $this->ids();

        if ($ids === []) {
            return [];
        }

        return array_values(array_filter(
            $this->catalog->all(),
            fn (ProductView $product) => in_array($product->id(), $ids, true)
        ));
    }

    /** @return bool the product's state after toggling */
    public function toggle(string $productId): bool
    {
        return $this->has($productId)
            ? ! $this->remove($productId)
            : $this->add($productId);
    }

    /** @return bool whether the product is now saved */
    public function add(string $productId): bool
    {
        // Only a product that exists can be saved.
        if (! ctype_digit($productId) || ! Product::query()->active()->whereKey((int) $productId)->exists()) {
            return false;
        }

        if ($customerId = $this->customerId()) {
            WishlistItem::query()->firstOrCreate(['customer_id' => $customerId, 'product_id' => (int) $productId]);
        } else {
            $ids = Session::get(self::GUEST_KEY, []);
            $ids[$productId] = $productId;
            Session::put(self::GUEST_KEY, $ids);
        }

        $this->ids = null;

        return true;
    }

    public function remove(string $productId): bool
    {
        if ($customerId = $this->customerId()) {
            WishlistItem::query()
                ->where('customer_id', $customerId)
                ->where('product_id', ctype_digit($productId) ? (int) $productId : 0)
                ->delete();
        } else {
            $ids = Session::get(self::GUEST_KEY, []);
            unset($ids[$productId]);
            Session::put(self::GUEST_KEY, $ids);
        }

        $this->ids = null;

        return true;
    }

    /** Called right after sign-in so a guest's saved products survive. */
    public function mergeGuestList(): void
    {
        $customerId = $this->customerId();
        $guestIds = array_values(Session::get(self::GUEST_KEY, []));

        if ($customerId === null || $guestIds === []) {
            return;
        }

        $existing = Product::query()->active()
            ->whereKey(array_map('intval', array_filter($guestIds, 'ctype_digit')))
            ->pluck('id');

        foreach ($existing as $productId) {
            WishlistItem::query()->firstOrCreate(['customer_id' => $customerId, 'product_id' => $productId]);
        }

        Session::forget(self::GUEST_KEY);
        $this->ids = null;
    }
}
