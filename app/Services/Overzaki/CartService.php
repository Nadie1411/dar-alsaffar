<?php

namespace App\Services\Overzaki;

use App\Contracts\Store\Cart;
use App\Services\Overzaki\DTO\Product;
use App\Services\Store\Concerns\KeepsBasketInSession;

/**
 * The basket.
 *
 * The session holds only intent — which product, how many. Every figure the
 * shopper sees (unit price after discount, subtotal, delivery, VAT, voucher
 * effects, whether the order may be placed at all) comes back from Overzaki's
 * cart checker, which is the same engine the previous storefront used and the
 * same one that will price the order at checkout. No totals are computed here.
 */
class CartService implements Cart
{
    use KeepsBasketInSession;

    public function __construct(
        protected OverzakiClient $client,
        protected CatalogService $catalog,
    ) {}

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
