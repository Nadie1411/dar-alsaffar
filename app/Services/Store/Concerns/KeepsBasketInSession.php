<?php

namespace App\Services\Store\Concerns;

use Illuminate\Support\Facades\Session;

/**
 * The basket as the session holds it: which product, how many, which options.
 *
 * Only intent is kept — never a price. Pricing is the cart's other half, and
 * it differs between the Overzaki-backed cart and the local one, so each does
 * its own; the basket itself works the same either way.
 */
trait KeepsBasketInSession
{
    protected const SESSION_KEY = 'cart.items';

    /** @return array<string,array{productId:string,quantity:int,varientId:?string,options:array}> */
    public function items(): array
    {
        return Session::get(self::SESSION_KEY, []);
    }

    public function add(string $productId, int $quantity = 1, ?string $varientId = null, array $options = []): void
    {
        $items = $this->items();
        $key = $this->key($productId, $varientId, $options);

        $items[$key] = [
            'productId' => $productId,
            'quantity' => max(1, ($items[$key]['quantity'] ?? 0) + $quantity),
            'varientId' => $varientId,
            'options' => $options,
        ];

        $this->persist($items);
    }

    /** @return bool whether the line existed and was changed */
    public function updateQuantity(string $key, int $quantity): bool
    {
        $items = $this->items();

        if (! isset($items[$key])) {
            return false;
        }

        if ($quantity < 1) {
            unset($items[$key]);
        } else {
            $items[$key]['quantity'] = $quantity;
        }

        $this->persist($items);

        return true;
    }

    /** @return bool whether the line existed and was removed */
    public function remove(string $key): bool
    {
        $items = $this->items();

        if (! isset($items[$key])) {
            return false;
        }

        unset($items[$key]);
        $this->persist($items);

        return true;
    }

    public function clear(): void
    {
        Session::forget(self::SESSION_KEY);
        Session::forget('cart.quote');
    }

    public function isEmpty(): bool
    {
        return $this->items() === [];
    }

    /** Badge count in the header — a cheap read that never calls the API. */
    public function count(): int
    {
        return array_sum(array_column($this->items(), 'quantity'));
    }

    public function has(string $productId): bool
    {
        foreach ($this->items() as $item) {
            if ($item['productId'] === $productId) {
                return true;
            }
        }

        return false;
    }

    protected function key(string $productId, ?string $varientId, array $options): string
    {
        return substr(md5($productId.'|'.($varientId ?? '').'|'.json_encode($options)), 0, 16);
    }

    protected function persist(array $items): void
    {
        Session::put(self::SESSION_KEY, $items);
        Session::forget('cart.quote');
    }
}
