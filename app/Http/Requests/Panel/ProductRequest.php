<?php

namespace App\Http\Requests\Panel;

use App\Models\OptionGroup;
use App\Models\Product;
use App\Rules\Dinars;
use App\Rules\Percent;
use App\Support\Money;
use App\Support\PanelFormat;
use App\Support\Slug;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Everything the product form can send. Money arrives as what a person typed
 * (dinars, any digits) and is turned into whole fils by the editor, never here
 * and never through a float.
 */
class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // An address that came from the old storefront keeps its exact spelling:
        // links people already have are case-sensitive, so it is only tidied
        // when someone has actually typed a different one.
        $slug = (string) $this->input('slug', '');

        $this->merge(['slug' => $slug === (string) $this->route('product')?->slug ? $slug : Slug::tidy($slug)]);
    }

    /**
     * @return array<string,array<int,mixed>>
     */
    public function rules(): array
    {
        $product = $this->route('product');
        $image = ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'];

        return [
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:190', Rule::unique('products', 'slug')->ignore($product?->id)],
            'sku' => ['nullable', 'string', 'max:100'],
            'description_ar' => ['nullable', 'string', 'max:20000'],
            'description_en' => ['nullable', 'string', 'max:20000'],
            'tags' => ['nullable', 'string', 'max:500'],
            'video' => ['nullable', 'url:http,https', 'max:500'],

            'price' => ['required', new Dinars],
            'discount_type' => ['required', Rule::in([Product::DISCOUNT_NONE, Product::DISCOUNT_FIXED, Product::DISCOUNT_PERCENTAGE])],
            'discount_amount' => ['nullable', new Dinars],
            'discount_percent' => ['nullable', new Percent],
            'discount_starts_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'discount_ends_at' => ['nullable', 'date_format:Y-m-d\TH:i'],

            'track_stock' => ['nullable', 'boolean'],
            'stock' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'low_stock_threshold' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'max_per_order' => ['nullable', 'integer', 'min:1', 'max:999'],

            'is_active' => ['nullable', 'boolean'],
            'is_featured' => ['nullable', 'boolean'],
            'is_new' => ['nullable', 'boolean'],
            'is_popular' => ['nullable', 'boolean'],
            'cod_enabled' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:1000000'],

            'categories' => ['nullable', 'array', 'max:30'],
            'categories.*' => ['integer', 'exists:categories,id'],
            'related' => ['nullable', 'array', 'max:24'],
            'related.*' => ['integer', 'exists:products,id', Rule::notIn(array_filter([$product?->id]))],

            'main_image' => ['nullable', ...$image],
            'remove_main_image' => ['nullable', 'boolean'],
            'gallery' => ['nullable', 'array', 'max:12'],
            'gallery.*' => $image,
            'remove_gallery' => ['nullable', 'array'],
            'remove_gallery.*' => ['integer'],

            'options' => ['nullable', 'array', 'max:20'],
            'options.*.id' => ['nullable', 'integer'],
            'options.*.name_ar' => ['required', 'string', 'max:190'],
            'options.*.name_en' => ['required', 'string', 'max:190'],
            'options.*.layout' => ['required', Rule::in([OptionGroup::LAYOUT_RADIO, OptionGroup::LAYOUT_CHECKBOX])],
            'options.*.is_required' => ['nullable', 'boolean'],
            'options.*.is_active' => ['nullable', 'boolean'],
            'options.*.min_choices' => ['nullable', 'integer', 'min:0', 'max:99'],
            'options.*.max_choices' => ['nullable', 'integer', 'min:0', 'max:99'],
            'options.*.values' => ['required', 'array', 'min:1', 'max:60'],
            'options.*.values.*.id' => ['nullable', 'integer'],
            'options.*.values.*.name_ar' => ['required', 'string', 'max:190'],
            'options.*.values.*.name_en' => ['required', 'string', 'max:190'],
            'options.*.values.*.price' => ['nullable', new Dinars],
            'options.*.values.*.is_active' => ['nullable', 'boolean'],

            'tiers' => ['nullable', 'array', 'max:10'],
            'tiers.*.id' => ['nullable', 'integer'],
            'tiers.*.min_quantity' => ['required', 'integer', 'min:1', 'max:9999', 'distinct'],
            'tiers.*.discount_type' => ['required', Rule::in([Product::DISCOUNT_NONE, Product::DISCOUNT_FIXED, Product::DISCOUNT_PERCENTAGE])],
            'tiers.*.discount_amount' => ['nullable', new Dinars],
            'tiers.*.discount_percent' => ['nullable', new Percent],
            'tiers.*.free_delivery' => ['nullable', 'boolean'],
            'tiers.*.label_ar' => ['nullable', 'string', 'max:190'],
            'tiers.*.label_en' => ['nullable', 'string', 'max:190'],
        ];
    }

    /**
     * The checks that depend on more than one field.
     *
     * @return array<int,callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $this->checkDiscount($validator);
            $this->checkOptions($validator);
            $this->checkTiers($validator);
        }];
    }

    protected function checkDiscount(Validator $validator): void
    {
        $type = $this->input('discount_type');
        $price = Money::parseFils((string) $this->input('price'));

        if ($type === Product::DISCOUNT_FIXED) {
            $amount = Money::parseFils((string) $this->input('discount_amount'));

            if ($amount === null || $amount < 1) {
                $validator->errors()->add('discount_amount', __('panel.products.discountAmountRequired'));
            } elseif ($amount > $price) {
                $validator->errors()->add('discount_amount', __('panel.products.discountTooLarge'));
            }
        }

        if ($type === Product::DISCOUNT_PERCENTAGE) {
            $basisPoints = Money::parsePercentBasisPoints((string) $this->input('discount_percent'));

            if ($basisPoints === null || $basisPoints < 1) {
                $validator->errors()->add('discount_percent', __('panel.products.discountPercentRequired'));
            }
        }

        $starts = $this->input('discount_starts_at');
        $ends = $this->input('discount_ends_at');

        if ($type !== Product::DISCOUNT_NONE && $starts && $ends
            && Carbon::createFromFormat('Y-m-d\TH:i', $ends, PanelFormat::timezone())
                ->lt(Carbon::createFromFormat('Y-m-d\TH:i', $starts, PanelFormat::timezone()))) {
            $validator->errors()->add('discount_ends_at', __('panel.products.discountEndsBeforeStart'));
        }
    }

    protected function checkOptions(Validator $validator): void
    {
        foreach ((array) $this->input('options', []) as $index => $group) {
            if (($group['layout'] ?? null) !== OptionGroup::LAYOUT_CHECKBOX) {
                continue;
            }

            $min = (int) ($group['min_choices'] ?? 0);
            $max = (int) ($group['max_choices'] ?? 0);

            if ($max > 0 && $min > $max) {
                $validator->errors()->add("options.$index.max_choices", __('panel.products.maxBelowMin'));
            }

            if ($min > count($group['values'] ?? [])) {
                $validator->errors()->add("options.$index.min_choices", __('panel.products.minAboveValues'));
            }
        }
    }

    protected function checkTiers(Validator $validator): void
    {
        foreach ((array) $this->input('tiers', []) as $index => $tier) {
            $type = $tier['discount_type'] ?? null;

            if ($type === Product::DISCOUNT_FIXED) {
                $amount = Money::parseFils((string) ($tier['discount_amount'] ?? ''));

                if ($amount === null || $amount < 1) {
                    $validator->errors()->add("tiers.$index.discount_amount", __('panel.products.discountAmountRequired'));
                }
            } elseif ($type === Product::DISCOUNT_PERCENTAGE) {
                $basisPoints = Money::parsePercentBasisPoints((string) ($tier['discount_percent'] ?? ''));

                if ($basisPoints === null || $basisPoints < 1) {
                    $validator->errors()->add("tiers.$index.discount_percent", __('panel.products.discountPercentRequired'));
                }
            }
        }
    }
}
