<?php

namespace App\Services\Overzaki;

use App\Services\Overzaki\DTO\Product;
use App\Support\Money;

/**
 * A priced basket as Overzaki returned it. Read-only: every accessor reads a
 * field the API computed, so the cart, checkout and order summary can never
 * disagree with what the shopper is actually charged.
 */
class CartQuote
{
    public function __construct(
        public readonly array $raw,
        /** @var array<string,Product> keyed by cart line id */
        public readonly array $products = [],
        public readonly ?string $error = null,
        /**
         * The basket as this session holds it, keyed by the key the cart
         * service edits by. The API answers with its own line ids, which are
         * meaningless to us, so lines() maps one onto the other.
         *
         * @var array<string,array<string,mixed>>
         */
        public readonly array $sessionItems = [],
    ) {}

    public static function empty(): self
    {
        return new self([], []);
    }

    public static function failed(?string $message): self
    {
        return new self([], [], $message ?: __('storefront.cart.unavailable'));
    }

    public function isEmpty(): bool
    {
        return $this->lines() === [];
    }

    public function hasError(): bool
    {
        return $this->error !== null;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function lines(): array
    {
        $lines = [];
        $keys = $this->resolveSessionKeys();

        foreach ($this->raw['items'] ?? [] as $index => $line) {
            $lineId = (string) ($line['_id'] ?? '');
            $product = $this->products[$lineId] ?? null;

            $productId = is_array($line['productId'] ?? null)
                ? (string) ($line['productId']['_id'] ?? '')
                : (string) ($line['productId'] ?? '');

            $lines[] = [
                'id' => $lineId,
                // What the cart service edits by. Null for a line the shopper
                // did not add — a promotion's free gift, which is not editable.
                'key' => $keys[$index] ?? null,
                'productId' => $productId,
                'product' => $product,
                'quantity' => (int) ($line['quantity'] ?? 0),
                'unitPrice' => (float) ($line['unitPriceAfterDiscount'] ?? $line['unitPrice'] ?? 0),
                'listPrice' => (float) ($line['unitPrice'] ?? 0),
                'total' => (float) ($line['totalPriceAfterDiscount'] ?? $line['totalPrice'] ?? 0),
                'symbol' => $line['symbol'] ?? $this->symbol(),
                'available' => (bool) ($line['status'] ?? true),
                'message' => ($line['msg'] ?? '') ?: null,
                'isFreeGift' => (bool) ($line['isFreeGift'] ?? false),
                'varientId' => $line['varientId'] ?? null,
                'options' => $line['options'] ?? [],
            ];
        }

        return $lines;
    }

    /**
     * Pair each API line with the session key that produced it.
     *
     * Matching is by product and variant, consumed in order, so two lines of
     * the same product with different options still resolve to distinct keys.
     *
     * @return array<int,string>
     */
    protected function resolveSessionKeys(): array
    {
        $available = $this->sessionItems;
        $keys = [];

        foreach ($this->raw['items'] ?? [] as $index => $line) {
            $productId = is_array($line['productId'] ?? null)
                ? (string) ($line['productId']['_id'] ?? '')
                : (string) ($line['productId'] ?? '');

            $varientId = ($line['varientId'] ?? null) ?: null;

            foreach ($available as $key => $item) {
                if (($item['productId'] ?? null) !== $productId) {
                    continue;
                }

                if ((($item['varientId'] ?? null) ?: null) !== $varientId) {
                    continue;
                }

                $keys[$index] = $key;
                unset($available[$key]);
                break;
            }
        }

        return $keys;
    }

    public function symbol(): mixed
    {
        return $this->raw['symbol'] ?? null;
    }

    public function money(int|float|null $value): string
    {
        return Money::format($value, $this->symbol());
    }

    // --------------------------------------------------------------- totals

    public function subTotal(): float
    {
        return (float) ($this->raw['subTotal'] ?? 0);
    }

    public function discount(): float
    {
        return (float) ($this->raw['discount'] ?? 0);
    }

    public function pointsDiscount(): float
    {
        return (float) ($this->raw['pointsDiscount'] ?? 0);
    }

    public function deliveryFees(): float
    {
        return (float) ($this->raw['deliveryFees'] ?? 0);
    }

    public function vat(): float
    {
        return (float) ($this->raw['vat'] ?? 0);
    }

    public function codFee(): float
    {
        return (float) ($this->raw['cashOnDeliveryFee'] ?? 0);
    }

    public function total(): float
    {
        return (float) ($this->raw['total'] ?? 0);
    }

    public function amountToPay(): float
    {
        return (float) ($this->raw['amountToPay'] ?? $this->total());
    }

    public function totalQuantity(): int
    {
        return (int) ($this->raw['totalQuantity'] ?? 0);
    }

    public function minimumOrderAmount(): float
    {
        return (float) ($this->raw['minimumOrderAmount'] ?? 0);
    }

    public function meetsMinimum(): bool
    {
        $minimum = $this->minimumOrderAmount();

        return $minimum <= 0 || $this->subTotal() >= $minimum;
    }

    /**
     * Whether delivery has actually been worked out yet. Before an address is
     * chosen the API returns zero, which must not be shown as "free".
     */
    public function deliveryResolved(): bool
    {
        return ! empty($this->raw['location']['area'])
            || ! empty($this->raw['addressId'])
            || $this->isPickup()
            || $this->deliveryFees() > 0;
    }

    public function isPickup(): bool
    {
        return (bool) ($this->raw['isStorePickup'] ?? $this->raw['pickup'] ?? false);
    }

    public function canPlaceOrder(): bool
    {
        return (bool) ($this->raw['validToCreateOrder'] ?? false) && $this->meetsMinimum();
    }

    public function supportsCashOnDelivery(): bool
    {
        return (bool) ($this->raw['availableCashOnDelivery'] ?? false);
    }

    // -------------------------------------------------------------- voucher

    public function voucherCode(): ?string
    {
        $voucher = $this->raw['voucher'] ?? null;

        if (is_array($voucher)) {
            return $voucher['code'] ?? null;
        }

        return is_string($voucher) && $voucher !== '' ? $voucher : null;
    }

    public function voucherAccepted(): bool
    {
        return (bool) ($this->raw['voucherStatus'] ?? true);
    }

    public function voucherMessage(): ?string
    {
        return ($this->raw['voucherMsg'] ?? '') ?: null;
    }

    // ----------------------------------------------------- promotions

    /**
     * Live progress toward a "buy X get Y" gift, as the API computes it.
     *
     * The promotion itself is configured in the Overzaki dashboard — scope
     * (whole store / a category / chosen products), quantities and dates all
     * live there. This only reads back what the cart checker decided, so the
     * banner can never promise a gift the order will not actually include.
     *
     * @return array{voucherId:?string,inCart:bool,percent:int,quantity:int,giftName:?string,giftImage:?string,remainingQuantity:int,remainingAmount:float}|null
     */
    public function freeGift(): ?array
    {
        $gift = $this->raw['promotionProgress']['freeGift'] ?? null;

        if (! is_array($gift) || $gift === []) {
            return null;
        }

        $product = $gift['giftProduct'] ?? null;

        return [
            'voucherId' => $gift['voucherId'] ?? null,
            'inCart' => (bool) ($gift['giftInCart'] ?? false),
            'percent' => (int) round(min(100, max(0, (float) ($gift['progressPercentage'] ?? 0)))),
            'quantity' => (int) ($gift['giftQuantity'] ?? 1),
            'giftName' => is_array($product) ? Loc::text($product['title'] ?? null) : null,
            'giftImage' => is_array($product) ? ($product['mainImage'] ?? null) : null,
            'remainingQuantity' => (int) ($gift['remainingQuantity'] ?? 0),
            'remainingAmount' => (float) ($gift['remainingAmount'] ?? 0),
        ];
    }

    public function hasPromotion(): bool
    {
        return (bool) ($this->raw['promotionProgress']['hasPromotion'] ?? false);
    }

    /** The active promotion's own description, when the API supplies one. */
    public function promotionName(): ?string
    {
        $promotion = $this->raw['promotionProgress']['promotion'] ?? null;

        if (! is_array($promotion)) {
            return null;
        }

        return Loc::text($promotion['name'] ?? null) ?: null;
    }

    /**
     * Progress toward free delivery. Returns null unless the store actually
     * has a shipping threshold configured — no invented "spend 20 KWD" line.
     *
     * @return array{threshold:float,remaining:float,percent:int,unlocked:bool}|null
     */
    public function freeShipping(): ?array
    {
        $threshold = (float) ($this->raw['shippingWaiverAmount'] ?? 0);

        if ($threshold <= 0) {
            return null;
        }

        $subTotal = $this->subTotal();
        $remaining = max($threshold - $subTotal, 0);

        return [
            'threshold' => $threshold,
            'remaining' => $remaining,
            'percent' => (int) round(min(100, $threshold > 0 ? $subTotal / $threshold * 100 : 0)),
            'unlocked' => $remaining <= 0 || $this->shippingDiscount() > 0,
        ];
    }

    public function shippingDiscount(): float
    {
        return (float) ($this->raw['shippingDiscountAmount'] ?? $this->raw['shippingDiscount'] ?? 0);
    }

    /** Add-ons such as gift wrapping, priced by the API. */
    public function serviceAddonsTotal(): float
    {
        return (float) ($this->raw['serviceAddonsTotal'] ?? 0);
    }

    /** @return array<int,string> */
    public function selectedAddonIds(): array
    {
        return array_values(array_filter(array_map(
            fn ($addon) => is_array($addon) ? ($addon['_id'] ?? $addon['addonId'] ?? null) : $addon,
            $this->raw['selectedServiceAddons'] ?? []
        )));
    }

    /** @return array<int,string> */
    public function problems(): array
    {
        $problems = [];

        foreach ($this->raw['checkerErrors'] ?? [] as $error) {
            $message = is_array($error) ? ($error['msg'] ?? $error['message'] ?? null) : $error;

            if (is_string($message) && $message !== '') {
                $problems[] = $message;
            }
        }

        foreach ($this->lines() as $line) {
            if (! $line['available'] && $line['message']) {
                $problems[] = $line['message'];
            }
        }

        if (($this->raw['msg'] ?? '') !== '') {
            $problems[] = $this->raw['msg'];
        }

        return array_values(array_unique($problems));
    }
}
