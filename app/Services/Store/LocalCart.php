<?php

namespace App\Services\Store;

use App\Contracts\Store\Cart;
use App\Services\Overzaki\CartQuote;
use App\Services\Overzaki\DTO\Product as ProductView;
use App\Services\Store\Concerns\KeepsBasketInSession;
use App\Services\Store\Pricing\PricingContext;
use App\Services\Store\Pricing\PricingEngine;
use App\Support\Shopper;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

/**
 * The basket, priced by this application's own engine.
 *
 * The session holds only intent. Every amount the shopper sees comes from the
 * PricingEngine, which is also what prices the order when it is placed — so
 * the cart, the checkout summary and the charge cannot disagree.
 */
class LocalCart implements Cart
{
    use KeepsBasketInSession;

    public function __construct(
        protected PricingEngine $engine,
        protected ProductPresenter $presenter,
    ) {}

    /**
     * @param  array{voucher?:?string,area?:int|string|null,addons?:array<int,int|string>,payment?:?string,phone?:?string}  $context
     */
    public function quote(array $context = []): CartQuote
    {
        $items = $this->items();

        if ($items === []) {
            return CartQuote::empty();
        }

        $priced = $this->engine->price($items, $this->pricingContext($context));

        $products = [];

        foreach ($priced->lines as $line) {
            if ($line->product !== null) {
                $products[$line->key] = ProductView::make($this->presenter->raw($line->product));
            }
        }

        return new CartQuote($priced->toQuoteData(), $products, null, $items);
    }

    /**
     * What the shopper has chosen beyond the basket. A voucher already applied
     * in this session carries through every quote, unless the caller names one.
     *
     * @param  array<string,mixed>  $given
     */
    protected function pricingContext(array $given): PricingContext
    {
        return new PricingContext(
            areaId: isset($given['area']) ? (int) $given['area'] : null,
            voucherCode: array_key_exists('voucher', $given) ? $given['voucher'] : Session::get('cart.voucher'),
            addonIds: array_map('intval', $given['addons'] ?? []),
            cashOnDelivery: ($given['payment'] ?? null) === 'cod',
            customerId: Auth::guard(Shopper::GUARD)->id(),
            phone: $given['phone'] ?? null,
        );
    }
}
