<?php

namespace App\Services\Overzaki;

use App\Services\Overzaki\DTO\Product;
use Illuminate\Support\Facades\Session;

/**
 * The basket.
 *
 * The session holds only intent — which product, how many. Every figure the
 * shopper sees (unit price after discount, subtotal, delivery, VAT, voucher
 * effects, whether the order may be placed at all) comes back from Overzaki's
 * cart checker, which is the same engine the previous storefront used and the
 * same one that will price the order at checkout. No totals are computed here.
 */
class CartService
{
    protected const SESSION_KEY = 'cart.items';

    public function __construct(
        protected OverzakiClient $client,
        protected CatalogService $catalog,
    ) {}

    // --------------------------------------------------------------- intent

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

    // ---------------------------------------------------------------- quote

    /**
     * Price the basket through Overzaki.
     *
     * @param  array  $context  Optional address / voucher / payment context the
     *                          checker uses to work out delivery and discounts.
     */
    public function quote(array $context = []): CartQuote
    {
        $items = $this->items();

        if ($items === []) {
            return CartQuote::empty();
        }

        $payload = array_merge([
            'items' => array_values(array_map(fn ($item) => array_filter([
                'productId' => $item['productId'],
                'quantity' => $item['quantity'],
                'varientId' => $item['varientId'] ?: null,
                'options' => $item['options'] ?: null,
            ], fn ($value) => $value !== null), $items)),
        ], $context);

        $response = $this->client
            ->withToken(AuthService::token())
            ->postRaw(config('overzaki.endpoints.cartChecker'), $payload);

        if (! $response['ok'] || ! is_array($response['data'])) {
            return CartQuote::failed($response['message'] ?? null);
        }

        return new CartQuote($response['data'], $this->decorate($response['data']), null, $items);
    }

    /**
     * The checker echoes the product document back inside each line, but the
     * catalogue copy is richer (gallery, category, flags), so lines are matched
     * up with it for display.
     *
     * @return array<string,Product>
     */
    protected function decorate(array $cart): array
    {
        $byId = [];

        foreach ($this->catalog->all() as $product) {
            $byId[$product->id()] = $product;
        }

        $resolved = [];

        foreach ($cart['items'] ?? [] as $line) {
            $productId = is_array($line['productId'] ?? null)
                ? (string) ($line['productId']['_id'] ?? '')
                : (string) ($line['productId'] ?? '');

            if ($productId === '') {
                continue;
            }

            $resolved[(string) ($line['_id'] ?? $productId)] = $byId[$productId]
                ?? Product::make(is_array($line['productId'] ?? null) ? $line['productId'] : null);
        }

        return array_filter($resolved);
    }
}
