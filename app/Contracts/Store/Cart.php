<?php

namespace App\Contracts\Store;

use App\Services\Overzaki\CartQuote;

/**
 * The shopper's basket. The session holds only intent — which product, how
 * many, which options — and every figure comes back from a quote.
 */
interface Cart
{
    /** @return array<string,array{productId:string,quantity:int,varientId:?string,options:array<int,mixed>}> */
    public function items(): array;

    /** @param  array<int,mixed>  $options */
    public function add(string $productId, int $quantity = 1, ?string $varientId = null, array $options = []): void;

    /** @return bool whether the line existed and was changed */
    public function updateQuantity(string $key, int $quantity): bool;

    /** @return bool whether the line existed and was removed */
    public function remove(string $key): bool;

    public function clear(): void;

    public function isEmpty(): bool;

    /** Badge count in the header — a cheap read that never prices anything. */
    public function count(): int;

    public function has(string $productId): bool;

    /** @param  array<string,mixed>  $context  voucher, delivery address and similar */
    public function quote(array $context = []): CartQuote;
}
