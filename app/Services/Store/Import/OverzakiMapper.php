<?php

namespace App\Services\Store\Import;

use App\Models\OptionGroup;
use App\Models\Product;
use App\Support\Loc;
use App\Support\Money;
use Carbon\Carbon;
use Throwable;

/**
 * Turns Overzaki documents into this application's own rows.
 *
 * Pure on purpose: it reads arrays and returns arrays, so every decision about
 * how an Overzaki field maps onto a column can be tested without a network or
 * a database. Fields the storefront never displayed (gender, season, metal
 * type and the like) are deliberately not carried over.
 */
class OverzakiMapper
{
    /**
     * @param  array<string,mixed>  $raw
     * @return array<string,mixed>
     */
    public function category(array $raw): array
    {
        return [
            'slug' => (string) ($raw['slug'] ?? $raw['_id']),
            'name_ar' => $this->text($raw['name'] ?? null, 'ar'),
            'name_en' => $this->text($raw['name'] ?? null, 'en'),
            'image' => $this->url($raw['image'] ?? null),
            'icon' => $this->url($raw['icon'] ?? null),
            'sort_order' => (int) ($raw['sortIndex'] ?? 0),
            'is_featured' => (bool) ($raw['isFeatured'] ?? false),
            'is_active' => $this->isLive($raw),
            'overzaki_id' => (string) $raw['_id'],
        ];
    }

    /**
     * @param  array<string,mixed>  $raw
     * @return array<string,mixed>
     */
    public function product(array $raw): array
    {
        [$type, $fixedFils, $percent] = $this->discount($raw);

        $limited = (bool) ($raw['isStockLimited'] ?? false);
        $unlimitedPerUser = (bool) ($raw['unlimitedQuantityPerUser'] ?? true);

        return [
            'slug' => (string) ($raw['slug'] ?? $raw['_id']),
            'sku' => $this->nonEmpty($raw['sku'] ?? null),
            'name_ar' => $this->text($raw['title'] ?? null, 'ar'),
            'name_en' => $this->text($raw['title'] ?? null, 'en'),
            'description_ar' => $this->nullableText($raw['description'] ?? null, 'ar'),
            'description_en' => $this->nullableText($raw['description'] ?? null, 'en'),
            'sell_price_fils' => Money::toFils($raw['sellPrice'] ?? 0),
            'discount_type' => $type,
            'discount_fils' => $fixedFils,
            'discount_percent' => $percent,
            'discount_starts_at' => $this->moment($raw['discountStartDate'] ?? null),
            'discount_ends_at' => $this->moment($raw['discountEndDate'] ?? null),
            'track_stock' => $limited,
            'stock' => $limited ? max((int) ($raw['quantity'] ?? 0), 0) : 0,
            'low_stock_threshold' => $limited ? max((int) ($raw['lowQuantity'] ?? 0), 0) : 0,
            'max_per_order' => $unlimitedPerUser ? null : (((int) ($raw['maxQuantityPerUser'] ?? 0)) ?: null),
            'main_image' => $this->url($raw['mainImage'] ?? null),
            'video' => $this->url($raw['video'] ?? null),
            'tags' => $this->tags($raw['tags'] ?? []) ?: null,
            'is_active' => $this->isLive($raw),
            'is_featured' => (bool) ($raw['isFeatured'] ?? false),
            'is_new' => (bool) ($raw['isNew'] ?? false),
            'is_popular' => (bool) ($raw['isPopular'] ?? false),
            'cod_enabled' => (bool) ($raw['enableCashOnDelivery'] ?? true),
            'sort_order' => (int) ($raw['sort'] ?? 0),
            'sales_count' => max((int) ($raw['totalOrders'] ?? 0), 0),
            'rating_average' => round((float) ($raw['rate'] ?? 0), 2),
            'rating_count' => max((int) ($raw['count'] ?? 0), 0),
            'overzaki_id' => (string) $raw['_id'],
        ];
    }

    /**
     * Gallery images beyond the main one, in order, without repeats.
     *
     * @param  array<string,mixed>  $raw
     * @return array<int,string>
     */
    public function gallery(array $raw): array
    {
        $main = $this->url($raw['mainImage'] ?? null);
        $urls = [];

        foreach ($raw['images'] ?? [] as $image) {
            $url = $this->url(is_array($image) ? ($image['url'] ?? $image['image'] ?? null) : $image);

            if ($url !== null && $url !== $main && ! in_array($url, $urls, true)) {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    /**
     * The list endpoint gives bare ids; the detail endpoint embeds each related
     * product in full. Either way, only the id is wanted.
     *
     * @param  array<string,mixed>  $raw
     * @return array<int,string> the Overzaki ids of the curated related products
     */
    public function relatedIds(array $raw): array
    {
        $ids = [];

        foreach ($raw['relatedProducts'] ?? [] as $related) {
            $id = is_array($related) ? ($related['_id'] ?? null) : $related;

            if (is_string($id) && $id !== '') {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Overzaki's "one time discount": quantity packages that take an amount
     * off the line once the shopper buys enough, and can waive delivery.
     *
     * @param  array<string,mixed>  $raw
     * @return array<int,array<string,mixed>>
     */
    public function quantityTiers(array $raw): array
    {
        $oneTime = $raw['oneTimeDiscount'] ?? null;

        if (! is_array($oneTime) || ! ($oneTime['enabled'] ?? false)) {
            return [];
        }

        $tiers = [];

        foreach ($oneTime['packages'] ?? [] as $package) {
            $quantity = (int) ($package['quantity'] ?? 0);

            if (! is_array($package) || $quantity < 1) {
                continue;
            }

            [$type, $fixedFils, $percent] = $this->discount($package);

            $tiers[$quantity] = [
                'min_quantity' => $quantity,
                'discount_type' => $type,
                'discount_fils' => $fixedFils,
                'discount_percent' => $percent,
                'free_delivery' => (bool) ($package['freeDelivery'] ?? false),
                'label_ar' => $this->nullableText($package['title'] ?? null, 'ar'),
                'label_en' => $this->nullableText($package['title'] ?? null, 'en'),
            ];
        }

        ksort($tiers);

        return array_values($tiers);
    }

    /**
     * @param  array<string,mixed>  $raw
     * @return array<string,mixed>
     */
    public function optionGroup(array $raw): array
    {
        return [
            'name_ar' => $this->text($raw['name'] ?? null, 'ar'),
            'name_en' => $this->text($raw['name'] ?? null, 'en'),
            // Any single-choice layout behaves as a radio group; only a
            // checkbox group takes several picks.
            'layout' => ($raw['layout'] ?? null) === OptionGroup::LAYOUT_CHECKBOX
                ? OptionGroup::LAYOUT_CHECKBOX
                : OptionGroup::LAYOUT_RADIO,
            'is_required' => (bool) ($raw['isRequired'] ?? false),
            'min_choices' => max((int) ($raw['minimumChoises'] ?? 0), 0),
            'max_choices' => max((int) ($raw['maximumChoises'] ?? 0), 0),
            'sort_order' => (int) ($raw['sortIndex'] ?? 0),
            'is_active' => $this->isLive($raw),
            'overzaki_id' => (string) $raw['_id'],
        ];
    }

    /**
     * @param  array<string,mixed>  $raw
     * @return array<string,mixed>
     */
    public function optionValue(array $raw): array
    {
        return [
            'name_ar' => $this->text($raw['name'] ?? null, 'ar'),
            'name_en' => $this->text($raw['name'] ?? null, 'en'),
            'price_fils' => Money::toFils($raw['price'] ?? 0),
            'image' => $this->url($raw['image'] ?? null),
            'sort_order' => (int) ($raw['sortIndex'] ?? 0),
            'is_active' => $this->isLive($raw),
            'overzaki_id' => (string) $raw['_id'],
        ];
    }

    /**
     * @param  array<string,mixed>  $raw
     * @return array<string,mixed>
     */
    public function city(array $raw, int $position = 0): array
    {
        return [
            'name_ar' => $this->text($raw['cityName'] ?? null, 'ar'),
            'name_en' => $this->text($raw['cityName'] ?? null, 'en'),
            'sort_order' => $position,
            'is_active' => true,
            'overzaki_id' => (string) $raw['cityId'],
        ];
    }

    /**
     * @param  array<string,mixed>  $raw
     * @return array<string,mixed>
     */
    public function area(array $raw, int $position = 0): array
    {
        return [
            'name_ar' => $this->text($raw['name'] ?? null, 'ar'),
            'name_en' => $this->text($raw['name'] ?? null, 'en'),
            'sort_order' => $position,
            'is_active' => (bool) ($raw['isActive'] ?? true),
            'overzaki_id' => (string) $raw['_id'],
        ];
    }

    /**
     * @param  array<string,mixed>  $raw
     * @return array<string,mixed>
     */
    public function addon(array $raw, int $position = 0): array
    {
        return [
            'name_ar' => $this->text($raw['name'] ?? null, 'ar'),
            'name_en' => $this->text($raw['name'] ?? null, 'en'),
            'description_ar' => Loc::plain($raw['description'] ?? null, 500, 'ar') ?: null,
            'description_en' => Loc::plain($raw['description'] ?? null, 500, 'en') ?: null,
            'price_fils' => Money::toFils($raw['price'] ?? 0),
            'image' => $this->url($raw['image'] ?? null),
            'sort_order' => $position,
            'is_active' => $this->isLive($raw),
            'overzaki_id' => (string) $raw['_id'],
        ];
    }

    // ------------------------------------------------------------- helpers

    /**
     * A localised `{ar, en}` map as one language, falling back to the other so
     * a half-translated product never shows a blank name.
     */
    public function text(mixed $value, string $locale): string
    {
        $other = $locale === 'ar' ? 'en' : 'ar';

        if (is_string($value)) {
            return trim($value);
        }

        if (! is_array($value)) {
            return '';
        }

        foreach ([$locale, 'localized', $other] as $key) {
            if (isset($value[$key]) && is_string($value[$key]) && trim($value[$key]) !== '') {
                return trim($value[$key]);
            }
        }

        return '';
    }

    public function nullableText(mixed $value, string $locale): ?string
    {
        return $this->text($value, $locale) ?: null;
    }

    public function url(mixed $value): ?string
    {
        return $this->nonEmpty($value);
    }

    public function nonEmpty(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** Active and not soft-deleted — Overzaki spells these a few different ways. */
    protected function isLive(array $raw): bool
    {
        $active = $raw['status'] ?? $raw['isActive'] ?? true;

        return (bool) $active && ! ($raw['isDelete'] ?? $raw['isDeleted'] ?? false);
    }

    /**
     * @param  array<string,mixed>  $raw
     * @return array{0:string,1:int,2:float} type, fixed amount in fils, percentage
     */
    protected function discount(array $raw): array
    {
        $value = (float) ($raw['discountValue'] ?? 0);

        if ($value <= 0) {
            return [Product::DISCOUNT_NONE, 0, 0.0];
        }

        return match ($raw['discountType'] ?? null) {
            'percentage' => [Product::DISCOUNT_PERCENTAGE, 0, round(min($value, 100), 2)],
            'fixed_amount' => [Product::DISCOUNT_FIXED, Money::toFils($value), 0.0],
            default => [Product::DISCOUNT_NONE, 0, 0.0],
        };
    }

    protected function moment(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            // The app runs in UTC, which is also what Overzaki's ISO dates use.
            return Carbon::parse($value)->utc()->toDateTimeString();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<int,string>
     */
    protected function tags(mixed $tags): array
    {
        if (! is_array($tags)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($tag) => is_array($tag) ? Loc::text($tag['name'] ?? $tag) : (is_string($tag) ? trim($tag) : ''),
            $tags
        ), fn (string $tag) => $tag !== ''));
    }
}
