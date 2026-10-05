<?php

namespace App\Services\Store;

use App\Models\OptionGroup;
use App\Models\OptionValue;
use App\Models\Product;
use App\Models\ProductQuantityTier;
use App\Support\Html;
use App\Support\Money;
use App\Support\PanelFormat;
use App\Support\Slug;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Saves a product the way the admin form describes it: the product itself, its
 * pictures, categories, option groups, quantity tiers and related products,
 * all or nothing.
 *
 * Money is parsed from what was typed, digit by digit, into whole fils. Ids
 * sent with an option or tier are only honoured for rows that already belong
 * to this product, so a form cannot reach into someone else's.
 */
class ProductEditor
{
    public function __construct(protected Uploads $uploads) {}

    /**
     * @param  array<string,mixed>  $data  a validated product form
     */
    public function save(?Product $product, array $data): Product
    {
        $replaced = [];

        $product = DB::transaction(function () use ($product, $data, &$replaced): Product {
            $product ??= new Product;
            $creating = ! $product->exists;

            $this->fillProduct($product, $data);
            $this->setSlug($product, $data, $creating);
            $this->setMainImage($product, $data, $replaced);
            $product->save();

            $this->syncGallery($product, $data, $replaced);
            $this->useFirstPictureAsMain($product);

            $product->categories()->sync(array_map('intval', $data['categories'] ?? []));
            $this->syncRelated($product, $data['related'] ?? []);
            $this->syncOptions($product, $data['options'] ?? []);
            $this->syncTiers($product, $data['tiers'] ?? []);

            return $product;
        });

        // Only once the change is committed: a rolled-back save must not have
        // thrown the old pictures away.
        foreach ($replaced as $path) {
            $this->uploads->delete($path);
        }

        return $product;
    }

    /**
     * A copy to start a similar product from. It is switched off, so it cannot
     * be sold half-finished, and it shares the original's picture files.
     */
    public function duplicate(Product $product): Product
    {
        return DB::transaction(function () use ($product): Product {
            $product->load(['images', 'categories', 'optionGroups.values', 'quantityTiers']);

            $copy = $product->replicate(['slug', 'overzaki_id', 'sales_count', 'rating_average', 'rating_count']);
            $copy->name_ar = $product->name_ar.' (نسخة)';
            $copy->name_en = $product->name_en.' (copy)';
            $copy->slug = Slug::unique('products', $product->slug.'-copy', null, 'product');
            $copy->is_active = false;
            $copy->save();

            foreach ($product->images as $image) {
                $copy->images()->create(['path' => $image->path, 'sort_order' => $image->sort_order]);
            }

            $copy->categories()->sync($product->categories->modelKeys());

            foreach ($product->optionGroups as $group) {
                $newGroup = $group->replicate(['overzaki_id']);
                $newGroup->product_id = $copy->id;
                $newGroup->save();

                foreach ($group->values as $value) {
                    $newValue = $value->replicate(['overzaki_id']);
                    $newValue->option_group_id = $newGroup->id;
                    $newValue->save();
                }
            }

            foreach ($product->quantityTiers as $tier) {
                $newTier = $tier->replicate();
                $newTier->product_id = $copy->id;
                $newTier->save();
            }

            return $copy;
        });
    }

    /** Removes the product and, once nothing else uses them, its picture files. */
    public function delete(Product $product): void
    {
        $paths = $product->images()->pluck('path')->push($product->main_image)->filter()->unique()->all();

        DB::transaction(fn () => $product->delete());

        foreach ($paths as $path) {
            $this->uploads->delete($path);
        }
    }

    // ------------------------------------------------------------------ pieces

    /**
     * @param  array<string,mixed>  $data
     */
    protected function fillProduct(Product $product, array $data): void
    {
        $type = $data['discount_type'] ?? Product::DISCOUNT_NONE;
        $discounted = $type !== Product::DISCOUNT_NONE;

        $product->fill([
            'name_ar' => trim($data['name_ar']),
            'name_en' => trim($data['name_en']),
            'sku' => $this->text($data['sku'] ?? null),
            'description_ar' => $this->description($data['description_ar'] ?? null),
            'description_en' => $this->description($data['description_en'] ?? null),
            'sell_price_fils' => (int) Money::parseFils((string) $data['price']),
            'discount_type' => $type,
            'discount_fils' => $type === Product::DISCOUNT_FIXED ? (int) Money::parseFils((string) $data['discount_amount']) : 0,
            'discount_percent' => $type === Product::DISCOUNT_PERCENTAGE ? $this->percent($data['discount_percent'] ?? null) : '0.00',
            'discount_starts_at' => $discounted ? $this->moment($data['discount_starts_at'] ?? null) : null,
            'discount_ends_at' => $discounted ? $this->moment($data['discount_ends_at'] ?? null) : null,
            'track_stock' => (bool) ($data['track_stock'] ?? false),
            'stock' => (int) ($data['stock'] ?? 0),
            'low_stock_threshold' => (int) ($data['low_stock_threshold'] ?? 0),
            'max_per_order' => filled($data['max_per_order'] ?? null) ? (int) $data['max_per_order'] : null,
            'video' => $this->text($data['video'] ?? null),
            'tags' => $this->tags($data['tags'] ?? null),
            'is_active' => (bool) ($data['is_active'] ?? false),
            'is_featured' => (bool) ($data['is_featured'] ?? false),
            'is_new' => (bool) ($data['is_new'] ?? false),
            'is_popular' => (bool) ($data['is_popular'] ?? false),
            'cod_enabled' => (bool) ($data['cod_enabled'] ?? false),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ]);
    }

    /**
     * A new product's address comes from its English name unless one was
     * typed. An existing product keeps its address unless a new one was typed:
     * renaming a product must not break the links people already have.
     *
     * @param  array<string,mixed>  $data
     */
    protected function setSlug(Product $product, array $data, bool $creating): void
    {
        $typed = (string) ($data['slug'] ?? '');

        if ($typed !== '') {
            $product->slug = $typed;
        } elseif ($creating) {
            $product->slug = Slug::unique('products', $data['name_en'] ?: $data['name_ar'], null, 'product');
        }
    }

    /**
     * @param  array<string,mixed>  $data
     * @param  array<int,string|null>  $replaced  collects the pictures to remove once saved
     */
    protected function setMainImage(Product $product, array $data, array &$replaced): void
    {
        if (($data['main_image'] ?? null) instanceof UploadedFile) {
            $replaced[] = $product->main_image;
            $product->main_image = $this->uploads->store($data['main_image'], 'catalog');
        } elseif ($data['remove_main_image'] ?? false) {
            $replaced[] = $product->main_image;
            $product->main_image = null;
        }
    }

    /**
     * @param  array<string,mixed>  $data
     * @param  array<int,string|null>  $replaced
     */
    protected function syncGallery(Product $product, array $data, array &$replaced): void
    {
        $remove = array_map('intval', $data['remove_gallery'] ?? []);

        if ($remove !== []) {
            foreach ($product->images()->whereIn('id', $remove)->get() as $image) {
                $replaced[] = $image->path;
                $image->delete();
            }
        }

        $next = (int) $product->images()->max('sort_order') + 1;

        foreach ($data['gallery'] ?? [] as $file) {
            if ($file instanceof UploadedFile) {
                $product->images()->create(['path' => $this->uploads->store($file, 'catalog'), 'sort_order' => $next++]);
            }
        }
    }

    /** A product with pictures but no main one would show a blank card: the first picture stands in. */
    protected function useFirstPictureAsMain(Product $product): void
    {
        if ($product->main_image === null && ($first = $product->images()->orderBy('sort_order')->first()) !== null) {
            $product->forceFill(['main_image' => $first->path])->save();
        }
    }

    /**
     * @param  array<int,int|string>  $ids
     */
    protected function syncRelated(Product $product, array $ids): void
    {
        $sync = [];

        foreach (array_values(array_unique(array_map('intval', $ids))) as $position => $id) {
            if ($id !== $product->id) {
                $sync[$id] = ['sort_order' => $position];
            }
        }

        $product->related()->sync($sync);
    }

    /**
     * @param  array<int,array<string,mixed>>  $rows
     */
    protected function syncOptions(Product $product, array $rows): void
    {
        $existing = $product->optionGroups()->get()->keyBy('id');
        $kept = [];

        foreach (array_values($rows) as $position => $row) {
            $group = $existing->get((int) ($row['id'] ?? 0)) ?? new OptionGroup;
            $isCheckbox = $row['layout'] === OptionGroup::LAYOUT_CHECKBOX;

            $group->fill([
                'name_ar' => trim($row['name_ar']),
                'name_en' => trim($row['name_en']),
                'layout' => $row['layout'],
                'is_required' => (bool) ($row['is_required'] ?? false),
                'min_choices' => $isCheckbox ? (int) ($row['min_choices'] ?? 0) : 0,
                'max_choices' => $isCheckbox ? (int) ($row['max_choices'] ?? 0) : 0,
                'sort_order' => $position,
                'is_active' => (bool) ($row['is_active'] ?? false),
            ]);
            $group->product_id = $product->id;
            $group->save();

            $kept[] = $group->id;
            $this->syncValues($group, $row['values'] ?? []);
        }

        $product->optionGroups()->whereNotIn('id', $kept)->delete();
    }

    /**
     * @param  array<int,array<string,mixed>>  $rows
     */
    protected function syncValues(OptionGroup $group, array $rows): void
    {
        $existing = $group->values()->get()->keyBy('id');
        $kept = [];

        foreach (array_values($rows) as $position => $row) {
            $value = $existing->get((int) ($row['id'] ?? 0)) ?? new OptionValue;

            $value->fill([
                'name_ar' => trim($row['name_ar']),
                'name_en' => trim($row['name_en']),
                'price_fils' => (int) Money::parseFils(filled($row['price'] ?? null) ? (string) $row['price'] : '0'),
                'sort_order' => $position,
                'is_active' => (bool) ($row['is_active'] ?? false),
            ]);
            $value->option_group_id = $group->id;
            $value->save();

            $kept[] = $value->id;
        }

        $group->values()->whereNotIn('id', $kept)->delete();
    }

    /**
     * @param  array<int,array<string,mixed>>  $rows
     */
    protected function syncTiers(Product $product, array $rows): void
    {
        $existing = $product->quantityTiers()->get()->keyBy('id');
        $kept = [];

        foreach ($rows as $row) {
            $tier = $existing->get((int) ($row['id'] ?? 0)) ?? new ProductQuantityTier;
            $type = $row['discount_type'];

            $tier->fill([
                'min_quantity' => (int) $row['min_quantity'],
                'discount_type' => $type,
                'discount_fils' => $type === Product::DISCOUNT_FIXED ? (int) Money::parseFils((string) $row['discount_amount']) : 0,
                'discount_percent' => $type === Product::DISCOUNT_PERCENTAGE ? $this->percent($row['discount_percent'] ?? null) : '0.00',
                'free_delivery' => (bool) ($row['free_delivery'] ?? false),
                'label_ar' => $this->text($row['label_ar'] ?? null),
                'label_en' => $this->text($row['label_en'] ?? null),
            ]);
            $tier->product_id = $product->id;
            $tier->save();

            $kept[] = $tier->id;
        }

        $product->quantityTiers()->whereNotIn('id', $kept)->delete();
    }

    // ------------------------------------------------------------------ values

    protected function text(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    /**
     * Plain text is kept exactly as typed; anything with markup in it is
     * stripped down to the few safe tags a description needs.
     */
    protected function description(mixed $value): ?string
    {
        $text = trim((string) $value);

        if ($text === '') {
            return null;
        }

        return str_contains($text, '<') ? (Html::clean($text) ?: null) : $text;
    }

    /** @return array<int,string>|null */
    protected function tags(mixed $value): ?array
    {
        $tags = collect(preg_split('/[,،\n]+/u', (string) $value) ?: [])
            ->map(fn (string $tag) => trim($tag))
            ->filter()
            ->unique()
            ->take(20)
            ->values()
            ->all();

        return $tags === [] ? null : $tags;
    }

    /** A percentage typed by a person, as the two-decimal string the column holds. */
    protected function percent(mixed $value): string
    {
        $basisPoints = (int) Money::parsePercentBasisPoints((string) $value);

        return number_format($basisPoints / 100, 2, '.', '');
    }

    /** A date and time typed in the shop's own clock, stored as UTC. */
    protected function moment(mixed $value): ?Carbon
    {
        if (! filled($value)) {
            return null;
        }

        return Carbon::createFromFormat('Y-m-d\TH:i', (string) $value, PanelFormat::timezone())->utc();
    }
}
