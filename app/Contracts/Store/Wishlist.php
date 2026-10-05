<?php

namespace App\Contracts\Store;

use App\Services\Overzaki\DTO\Product;

/**
 * Saved products — a session list for guests, an account list once signed in.
 */
interface Wishlist
{
    /** @return array<int,string> */
    public function ids(): array;

    public function has(string $productId): bool;

    public function count(): int;

    /** @return array<int,Product> */
    public function products(): array;

    /** @return bool the product's state after toggling */
    public function toggle(string $productId): bool;

    public function add(string $productId): bool;

    public function remove(string $productId): bool;

    /** Called right after sign-in so a guest's saved products survive. */
    public function mergeGuestList(): void;
}
