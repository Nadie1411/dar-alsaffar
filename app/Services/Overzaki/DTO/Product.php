<?php

namespace App\Services\Overzaki\DTO;

use App\Support\Loc;
use App\Support\Money;
use Illuminate\Contracts\Support\Arrayable;

/**
 * A view-friendly read model over an Overzaki product document.
 *
 * Pricing mirrors what the API's own cart checker computes: `sellPrice` is the
 * list price and `discountValue` comes off it either as a fixed amount or a
 * percentage, but only while the discount window is open. Nothing here invents
 * a number — the cart and checkout still take their totals from the API.
 */
class Product implements Arrayable
{
    public function __construct(public readonly array $raw) {}

    public static function make(?array $raw): ?self
    {
        return $raw ? new self($raw) : null;
    }

    /** @return array<int,self> */
    public static function collect(?array $rows): array
    {
        return array_values(array_filter(array_map(
            fn ($row) => is_array($row) ? new self($row) : null,
            $rows ?? []
        )));
    }

    public function id(): string
    {
        return (string) ($this->raw['_id'] ?? $this->raw['id'] ?? '');
    }

    public function slug(): string
    {
        return (string) ($this->raw['slug'] ?? $this->id());
    }

    public function name(): string
    {
        return Loc::text($this->raw['title'] ?? null, fallback: $this->slug());
    }

    public function descriptionHtml(): string
    {
        return Loc::html($this->raw['description'] ?? null);
    }

    public function excerpt(int $limit = 150): string
    {
        return Loc::plain($this->raw['description'] ?? null, $limit);
    }

    public function sku(): ?string
    {
        return ($this->raw['sku'] ?? null) ?: null;
    }

    // ---------------------------------------------------------------- pricing

    public function listPrice(): float
    {
        return (float) ($this->raw['sellPrice'] ?? 0);
    }

    public function price(): float
    {
        $price = $this->listPrice();

        if (! $this->hasDiscount()) {
            return $price;
        }

        $value = (float) ($this->raw['discountValue'] ?? 0);

        $price = ($this->raw['discountType'] ?? null) === 'percentage'
            ? $price - ($price * $value / 100)
            : $price - $value;

        return max($price, 0);
    }

    public function hasDiscount(): bool
    {
        if ((float) ($this->raw['discountValue'] ?? 0) <= 0) {
            return false;
        }

        return $this->discountWindowIsOpen();
    }

    /** Percentage off, rounded for display on the product badge. */
    public function discountPercent(): int
    {
        $list = $this->listPrice();

        if (! $this->hasDiscount() || $list <= 0) {
            return 0;
        }

        return (int) round(($list - $this->price()) / $list * 100);
    }

    public function savings(): float
    {
        return max($this->listPrice() - $this->price(), 0);
    }

    protected function discountWindowIsOpen(): bool
    {
        $now = time();
        $start = $this->timestamp($this->raw['discountStartDate'] ?? null);
        $end = $this->timestamp($this->raw['discountEndDate'] ?? null);

        if ($start !== null && $now < $start) {
            return false;
        }

        if ($end !== null && $now > $end) {
            return false;
        }

        return true;
    }

    protected function timestamp(mixed $value): ?int
    {
        if (empty($value) || ! is_string($value)) {
            return null;
        }

        $parsed = strtotime($value);

        return $parsed === false ? null : $parsed;
    }

    public function symbol(): mixed
    {
        return $this->raw['symbol'] ?? null;
    }

    public function formattedPrice(): string
    {
        return Money::format($this->price(), $this->symbol());
    }

    public function formattedListPrice(): string
    {
        return Money::format($this->listPrice(), $this->symbol());
    }

    // ----------------------------------------------------------------- media

    public function image(): ?string
    {
        return ($this->raw['mainImage'] ?? null) ?: ($this->gallery()[0] ?? null);
    }

    /** @return array<int,string> */
    public function gallery(): array
    {
        $images = [];

        foreach ([$this->raw['mainImage'] ?? null, ...($this->raw['images'] ?? [])] as $image) {
            $url = is_array($image) ? ($image['url'] ?? $image['image'] ?? null) : $image;

            if (is_string($url) && $url !== '' && ! in_array($url, $images, true)) {
                $images[] = $url;
            }
        }

        return $images;
    }

    /** The second image powers the product card's hover swap, when there is one. */
    public function hoverImage(): ?string
    {
        return $this->gallery()[1] ?? null;
    }

    public function video(): ?string
    {
        return ($this->raw['video'] ?? null) ?: null;
    }

    // ------------------------------------------------------------- taxonomy

    /** @return array<int,array{id:string,name:string,slug:string,level:int}> */
    public function categories(): array
    {
        $seen = [];
        $out = [];

        foreach ($this->raw['categories'] ?? [] as $category) {
            if (! is_array($category)) {
                continue;
            }

            $id = (string) ($category['_id'] ?? '');

            if ($id === '' || isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $out[] = [
                'id' => $id,
                'name' => Loc::text($category['name'] ?? null),
                'slug' => (string) ($category['slug'] ?? ''),
                'level' => (int) ($category['level'] ?? 1),
            ];
        }

        return $out;
    }

    /** The top-level category, used as the card's kicker line. */
    public function primaryCategory(): ?array
    {
        foreach ($this->categories() as $category) {
            if ($category['level'] === 1) {
                return $category;
            }
        }

        return $this->categories()[0] ?? null;
    }

    /** @return array<int,string> */
    public function tags(): array
    {
        return array_values(array_filter(array_map(
            fn ($tag) => is_array($tag) ? Loc::text($tag['name'] ?? $tag) : (is_string($tag) ? $tag : null),
            $this->raw['tags'] ?? []
        )));
    }

    // ---------------------------------------------------------------- stock

    public function inStock(): bool
    {
        if ($this->raw['isUnLimited'] ?? false) {
            return true;
        }

        if ($this->raw['isStockEmpty'] ?? false) {
            return false;
        }

        if (($this->raw['isStockLimited'] ?? false) === false) {
            return true;
        }

        return (int) ($this->raw['quantity'] ?? 0) > 0;
    }

    public function isLowStock(): bool
    {
        return (bool) ($this->raw['isLowStock'] ?? false) && $this->inStock();
    }

    public function quantityAvailable(): ?int
    {
        if (($this->raw['isUnLimited'] ?? false) || ! ($this->raw['isQuantityAvailableToShow'] ?? false)) {
            return null;
        }

        return (int) ($this->raw['quantity'] ?? 0);
    }

    public function maxPerOrder(): ?int
    {
        if ($this->raw['unlimitedQuantityPerUser'] ?? true) {
            return null;
        }

        $max = (int) ($this->raw['maxQuantityPerUser'] ?? 0);

        return $max > 0 ? $max : null;
    }

    // ---------------------------------------------------------------- flags

    public function isNew(): bool
    {
        return (bool) ($this->raw['isNew'] ?? false);
    }

    public function isFeatured(): bool
    {
        return (bool) ($this->raw['isFeatured'] ?? false);
    }

    public function isPopular(): bool
    {
        return (bool) ($this->raw['isPopular'] ?? false);
    }

    public function hasVariants(): bool
    {
        return (bool) ($this->raw['isVarientExists'] ?? false);
    }

    /**
     * True when the shopper has something to pick before this can be added. A
     * package's "choose several" group is not that: nobody picks from it, so a
     * package adds to the bag like any other product.
     */
    public function hasOptions(): bool
    {
        if (! ($this->raw['isOptionExists'] ?? false)) {
            return false;
        }

        $groups = $this->options();

        return $groups === [] || array_filter($groups, fn (array $group) => ! self::choosesSeveral($group)) !== [];
    }

    public function rating(): float
    {
        return round((float) ($this->raw['rate'] ?? 0), 1);
    }

    public function totalOrders(): int
    {
        return (int) ($this->raw['totalOrders'] ?? 0);
    }

    // --------------------------------------------------------------- options

    /**
     * Option groups as the dashboard defines them.
     *
     * A group with a checkbox layout and a choice count is how this store
     * marks its packages — "باكج" is a product whose single group is named
     * "Choose (3)" with minimumChoises/maximumChoises of 3 ({@see isBundle()}).
     *
     * @return array<int,array<string,mixed>>
     */
    public function options(): array
    {
        $groups = [];

        foreach ($this->raw['options'] ?? [] as $group) {
            if (! is_array($group) || ($group['isDelete'] ?? false)) {
                continue;
            }

            $values = [];

            foreach ($group['values'] ?? [] as $value) {
                if (! is_array($value) || ! ($value['isActive'] ?? true) || ($value['isDelete'] ?? false)) {
                    continue;
                }

                $values[] = [
                    'id' => (string) ($value['_id'] ?? ''),
                    'name' => Loc::text($value['name'] ?? null),
                    'image' => $value['image'] ?? null,
                    'price' => (float) ($value['price'] ?? 0),
                ];
            }

            if ($values === []) {
                continue;
            }

            $min = (int) ($group['minimumChoises'] ?? 0);
            $max = (int) ($group['maximumChoises'] ?? 0);

            $groups[] = [
                'id' => (string) ($group['_id'] ?? ''),
                'name' => Loc::text($group['name'] ?? null),
                'layout' => (string) ($group['layout'] ?? 'radio'),
                'required' => (bool) ($group['isRequired'] ?? false),
                'min' => $min,
                'max' => $max,
                'values' => $values,
            ];
        }

        return $groups;
    }

    /**
     * True when this product is a package rather than a single bottle: it carries
     * a "choose several" group (a checkbox layout that takes more than one pick).
     * The group only marks it as a package — the shopper is never asked to fill
     * it in.
     */
    public function isBundle(): bool
    {
        foreach ($this->options() as $group) {
            if (self::choosesSeveral($group)) {
                return true;
            }
        }

        return false;
    }

    /** @param  array<string,mixed>  $group  one entry of {@see options()} */
    protected static function choosesSeveral(array $group): bool
    {
        return $group['layout'] === 'checkbox' && $group['max'] > 1;
    }

    /**
     * True when the product's own price is zero and its option groups carry
     * the money — an oud priced per tola, for example.
     */
    public function isPricedByOptions(): bool
    {
        return $this->listPrice() <= 0 && $this->cheapestOption() !== null;
    }

    /** The lowest total a shopper could pay, used for a "from" price. */
    public function startingPrice(): float
    {
        $cheapest = $this->cheapestOption();

        return $cheapest === null ? $this->price() : $this->price() + $cheapest;
    }

    protected function cheapestOption(): ?float
    {
        $cheapest = null;

        foreach ($this->options() as $group) {
            // Only a required single-choice group sets a floor price; an
            // optional extra does not raise what the product costs.
            if (! $group['required'] || $group['layout'] === 'checkbox') {
                continue;
            }

            $prices = array_column($group['values'], 'price');

            if ($prices === []) {
                continue;
            }

            $min = min($prices);
            $cheapest = $cheapest === null ? $min : $cheapest + $min;
        }

        return $cheapest;
    }

    public function toArray(): array
    {
        return $this->raw;
    }
}
