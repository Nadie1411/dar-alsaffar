<?php

namespace App\Services\Store\Pricing;

use App\Models\DeliveryArea;
use App\Models\OptionGroup;
use App\Models\OptionValue;
use App\Models\Product;
use App\Models\ServiceAddon;
use App\Services\Store\ProductPresenter;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * The one place this application works out what an order costs.
 *
 * Everything is whole fils, so there is no rounding drift between what the
 * cart shows, what the checkout summary shows and what is charged. The cart,
 * the checkout page and the placed order all read the same PricedCart — which
 * is why none of them does any arithmetic of its own.
 *
 * What it applies, in order:
 *   1. each line: the product's own price (after its live discount) plus the
 *      options chosen, times the quantity, less any quantity-tier discount —
 *      or a reason the line cannot be sold;
 *   2. a voucher, if one was entered and is valid;
 *   3. delivery to the chosen area, unless a voucher or the free-delivery
 *      threshold waives it;
 *   4. chosen service add-ons, such as gift wrapping;
 *   5. the cash-on-delivery fee, when that is how the shopper will pay.
 */
class PricingEngine
{
    public function __construct(
        protected CommerceSettings $settings,
        protected VoucherRules $vouchers,
    ) {}

    /**
     * @param  array<string,array{productId:string,quantity:int,varientId?:?string,options?:array<int,mixed>}>  $basket  the session's basket, keyed by line
     */
    public function price(array $basket, PricingContext $context): PricedCart
    {
        $products = $this->loadProducts($basket);

        $lines = [];

        foreach ($basket as $key => $item) {
            $lines[] = $this->priceLine((string) $key, $item, $products->get((string) $item['productId']));
        }

        $subTotal = array_sum(array_map(
            fn (PricedLine $line) => $line->available ? $line->totalFils() : 0,
            $lines
        ));

        $voucher = $this->vouchers->evaluate($context->voucherCode, $subTotal, $context);
        $problems = [];

        $waivedByTier = collect($lines)->contains(fn (PricedLine $line) => $line->available && $line->freeDelivery);

        [$area, $deliveryResolved, $deliveryFee, $shippingDiscount, $areaProblem] =
            $this->delivery($context, $subTotal, $voucher->freeShipping || $waivedByTier);

        if ($areaProblem !== null) {
            $problems[] = $areaProblem;
        }

        [$addons, $addonProblem] = $this->addons($context);

        if ($addonProblem !== null) {
            $problems[] = $addonProblem;
        }

        $minimumOrder = $this->settings->minimumOrderFils();

        if ($minimumOrder > 0 && $subTotal < $minimumOrder && $lines !== []) {
            $problems[] = __('storefront.cart.minimumOrder', [
                'amount' => Money::format(Money::fromFils($minimumOrder), Money::KWD),
            ]);
        }

        return new PricedCart(
            lines: $lines,
            subTotalFils: $subTotal,
            discountFils: $voucher->discountFils,
            deliveryFeeFils: $deliveryFee,
            shippingDiscountFils: $shippingDiscount,
            addonsTotalFils: array_sum(array_map(fn (ServiceAddon $addon) => $addon->price_fils, $addons)),
            codFeeFils: $context->cashOnDelivery ? $this->settings->codFeeFils() : 0,
            minimumOrderFils: $minimumOrder,
            freeShippingThresholdFils: $this->settings->freeShippingThresholdFils(),
            deliveryResolved: $deliveryResolved,
            area: $area,
            cashOnDeliveryAvailable: $this->cashOnDeliveryAvailable($lines),
            voucher: $voucher,
            addons: $addons,
            problems: $problems,
        );
    }

    // ------------------------------------------------------------------ lines

    /**
     * Products are looked up by the id the basket holds, which is either this
     * store's own id or — for a basket started before the switch from
     * Overzaki — the id the product had there.
     *
     * @param  array<string,array{productId:string}>  $basket
     * @return Collection<string,Product> keyed by the id as the basket holds it
     */
    protected function loadProducts(array $basket): Collection
    {
        $references = collect($basket)->pluck('productId')->map(fn ($id) => (string) $id)->unique()->values();

        if ($references->isEmpty()) {
            return collect();
        }

        $products = Product::query()
            ->with(ProductPresenter::RELATIONS)
            ->where(function ($query) use ($references): void {
                $query->whereIn('id', $references->filter(fn (string $id) => ctype_digit($id))->all())
                    ->orWhereIn('overzaki_id', $references->all());
            })
            ->get();

        return $references->mapWithKeys(fn (string $reference) => [
            $reference => $products->first(
                fn (Product $product) => (string) $product->id === $reference || $product->overzaki_id === $reference
            ),
        ])->filter();
    }

    /**
     * @param  array{productId:string,quantity:int,options?:array<int,mixed>}  $item
     */
    protected function priceLine(string $key, array $item, ?Product $product): PricedLine
    {
        $quantity = max(1, min((int) ($item['quantity'] ?? 1), 999));
        $productId = (string) $item['productId'];

        if ($product === null || ! $product->is_active) {
            return new PricedLine($key, $productId, $product, $quantity, 0, 0, false, __('storefront.cart.lineGone'));
        }

        [$optionsFils, $selected, $optionProblem] = $this->options($product, $item['options'] ?? []);

        $listUnit = (int) $product->sell_price_fils + $optionsFils;
        $unit = $product->priceFils() + $optionsFils;
        $message = $optionProblem ?? $this->quantityProblem($product, $quantity);

        // "Buy more, save more": the highest tier the line reaches takes its
        // discount off the line once. A line that cannot be sold earns none.
        $tier = $message === null ? $product->quantityTierFor($quantity) : null;

        return new PricedLine(
            $key, $productId, $product, $quantity, $listUnit, $unit, $message === null, $message, $selected,
            $tier?->discountFor($unit * $quantity) ?? 0,
            $tier?->free_delivery ?? false,
        );
    }

    protected function quantityProblem(Product $product, int $quantity): ?string
    {
        if ($product->track_stock && $product->stock <= 0) {
            return __('storefront.cart.lineOutOfStock');
        }

        if ($product->track_stock && $quantity > $product->stock) {
            return __('storefront.cart.lineOnlyLeft', ['count' => $product->stock]);
        }

        if ($product->max_per_order !== null && $quantity > $product->max_per_order) {
            return __('storefront.cart.lineMaxPerOrder', ['count' => $product->max_per_order]);
        }

        return null;
    }

    /**
     * Check the shopper's choices against the product's own option groups and
     * add up what they cost.
     *
     * @param  array<int,mixed>  $selections  [['optionId' => ..., 'values' => [['valueId' => ..., 'quantity' => ...]]]]
     * @return array{0:int,1:array<int,array<string,mixed>>,2:?string} extra fils, the choices resolved, a problem if any
     */
    protected function options(Product $product, array $selections): array
    {
        $groups = $product->optionGroups
            ->filter(fn (OptionGroup $group) => $group->is_active && $group->values->contains('is_active', true));

        $extra = 0;
        $resolved = [];
        $chosenGroups = [];
        $problem = __('storefront.cart.lineOptionsInvalid');

        foreach ($selections as $selection) {
            $group = $this->findByReference($groups, (string) ($selection['optionId'] ?? ''));

            if ($group === null || isset($chosenGroups[$group->id])) {
                return [0, [], $problem];
            }

            $chosenGroups[$group->id] = true;
            $values = [];
            $count = 0;

            foreach ($selection['values'] ?? [] as $pick) {
                /** @var OptionValue|null $value */
                $value = $this->findByReference(
                    $group->values->filter(fn (OptionValue $value) => $value->is_active),
                    (string) ($pick['valueId'] ?? '')
                );

                if ($value === null) {
                    return [0, [], $problem];
                }

                $quantity = max(1, min((int) ($pick['quantity'] ?? 1), 99));
                $count += $quantity;
                $extra += $value->price_fils * $quantity;
                $values[] = [
                    'valueId' => (string) $value->id,
                    'quantity' => $quantity,
                    'unitPrice' => Money::fromFils($value->price_fils),
                    'value' => ['name' => $value->localizedMap('name')],
                ];
            }

            if (! $this->choiceCountIsAllowed($group, $count)) {
                return [0, [], $problem];
            }

            $resolved[] = [
                'optionId' => (string) $group->id,
                'values' => $values,
                'option' => ['name' => $group->localizedMap('name'), 'layout' => $group->layout],
            ];
        }

        foreach ($groups as $group) {
            if ($group->is_required && ! isset($chosenGroups[$group->id])) {
                return [0, [], $problem];
            }
        }

        return [$extra, $resolved, null];
    }

    protected function choiceCountIsAllowed(OptionGroup $group, int $count): bool
    {
        if ($count < 1) {
            return false;
        }

        if ($group->layout === OptionGroup::LAYOUT_CHECKBOX) {
            return $count >= $group->min_choices && ($group->max_choices === 0 || $count <= $group->max_choices);
        }

        return $count === 1;
    }

    /**
     * @template T of OptionGroup|OptionValue
     *
     * @param  Collection<int,T>  $candidates
     * @return T|null
     */
    protected function findByReference(Collection $candidates, string $reference): OptionGroup|OptionValue|null
    {
        return $candidates->first(
            fn ($candidate) => (string) $candidate->id === $reference || $candidate->overzaki_id === $reference
        );
    }

    // -------------------------------------------------------------- delivery

    /**
     * @return array{0:?DeliveryArea,1:bool,2:int,3:int,4:?string} the area, whether delivery
     *                                                             is worked out yet, the fee charged, the fee waived, and a problem if any
     */
    protected function delivery(PricingContext $context, int $subTotal, bool $waivedByVoucher): array
    {
        if ($context->areaId === null) {
            return [null, false, 0, 0, null];
        }

        $area = DeliveryArea::query()->with('city')->find($context->areaId);

        if ($area === null || ! $area->is_active || ! $area->city?->is_active) {
            return [null, false, 0, 0, __('storefront.cart.areaUnavailable')];
        }

        $fee = $area->fee_fils ?? $this->settings->deliveryFeeFils();
        $threshold = $this->settings->freeShippingThresholdFils();
        $waived = $waivedByVoucher || ($threshold > 0 && $subTotal >= $threshold);

        return [$area, true, $waived ? 0 : $fee, $waived ? $fee : 0, null];
    }

    // ---------------------------------------------------------------- add-ons

    /**
     * @return array{0:array<int,ServiceAddon>,1:?string}
     */
    protected function addons(PricingContext $context): array
    {
        $ids = array_values(array_unique(array_map('intval', $context->addonIds)));

        if ($ids === []) {
            return [[], null];
        }

        $addons = ServiceAddon::query()->active()->whereIn('id', $ids)->orderBy('sort_order')->get()->all();

        return [$addons, count($addons) === count($ids) ? null : __('storefront.cart.addonUnavailable')];
    }

    // ------------------------------------------------------------------- COD

    /**
     * Cash on delivery needs the shop-wide switch on and every product in the
     * basket to allow it.
     *
     * @param  array<int,PricedLine>  $lines
     */
    protected function cashOnDeliveryAvailable(array $lines): bool
    {
        if (! $this->settings->cashOnDeliveryEnabled()) {
            return false;
        }

        return collect($lines)->every(fn (PricedLine $line) => $line->product === null || $line->product->cod_enabled);
    }
}
