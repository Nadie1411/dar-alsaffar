<?php

namespace App\Services\Overzaki;

use App\Services\Overzaki\DTO\Product;
use Illuminate\Support\Facades\Session;

/**
 * Saved products.
 *
 * Signed-in shoppers get the real server-side wishlist so it follows them
 * between devices. Guests get a session list, which is merged upstream the
 * moment they sign in — so nothing a guest saved is lost at the door.
 */
class WishlistService
{
    protected const GUEST_KEY = 'wishlist.ids';

    public function __construct(
        protected OverzakiClient $client,
        protected CatalogService $catalog,
    ) {}

    /** @return array<int,string> */
    public function ids(): array
    {
        if (! AuthService::check()) {
            return array_values(Session::get(self::GUEST_KEY, []));
        }

        return Session::remember('wishlist.remote', fn () => $this->fetchRemoteIds());
    }

    public function has(string $productId): bool
    {
        return in_array($productId, $this->ids(), true);
    }

    public function count(): int
    {
        return count($this->ids());
    }

    /** @return array<int,Product> */
    public function products(): array
    {
        $ids = $this->ids();

        if ($ids === []) {
            return [];
        }

        return array_values(array_filter(
            $this->catalog->all(),
            fn (Product $product) => in_array($product->id(), $ids, true)
        ));
    }

    /** @return bool the product's state after toggling */
    public function toggle(string $productId): bool
    {
        return $this->has($productId)
            ? ! $this->remove($productId)
            : $this->add($productId);
    }

    public function add(string $productId): bool
    {
        if (AuthService::check()) {
            $this->client->withToken(AuthService::token())
                ->postRaw(config('overzaki.endpoints.wishlistAdd').$productId);

            Session::forget('wishlist.remote');

            return true;
        }

        $ids = Session::get(self::GUEST_KEY, []);
        $ids[$productId] = $productId;
        Session::put(self::GUEST_KEY, $ids);

        return true;
    }

    public function remove(string $productId): bool
    {
        if (AuthService::check()) {
            $this->client->withToken(AuthService::token())
                ->delete(config('overzaki.endpoints.wishlistRemove').$productId);

            Session::forget('wishlist.remote');

            return true;
        }

        $ids = Session::get(self::GUEST_KEY, []);
        unset($ids[$productId]);
        Session::put(self::GUEST_KEY, $ids);

        return true;
    }

    /** Called right after sign-in so a guest's saved products survive. */
    public function mergeGuestList(): void
    {
        $guestIds = array_values(Session::get(self::GUEST_KEY, []));

        if ($guestIds === [] || ! AuthService::check()) {
            return;
        }

        $this->client->withToken(AuthService::token())
            ->postRaw(config('overzaki.endpoints.wishlist').'../add_wishlist_multiple', [
                'productIds' => $guestIds,
            ]);

        Session::forget(self::GUEST_KEY);
        Session::forget('wishlist.remote');
    }

    /** @return array<int,string> */
    protected function fetchRemoteIds(): array
    {
        $response = $this->client->withToken(AuthService::token())
            ->get(config('overzaki.endpoints.wishlist'));

        $rows = $response['data'] ?? $response;
        $ids = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_string($row)) {
                $ids[] = $row;
            } elseif (is_array($row)) {
                $ids[] = (string) ($row['_id'] ?? $row['productId']['_id'] ?? $row['productId'] ?? '');
            }
        }

        return array_values(array_filter($ids));
    }
}
