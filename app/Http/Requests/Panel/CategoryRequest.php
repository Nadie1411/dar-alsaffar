<?php

namespace App\Http\Requests\Panel;

use App\Models\Category;
use App\Support\Slug;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CategoryRequest extends FormRequest
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

        $this->merge(['slug' => $slug === (string) $this->route('category')?->slug ? $slug : Slug::tidy($slug)]);
    }

    /**
     * @return array<string,array<int,mixed>>
     */
    public function rules(): array
    {
        $category = $this->route('category');

        return [
            'name_ar' => ['required', 'string', 'max:190'],
            'name_en' => ['required', 'string', 'max:190'],
            'slug' => ['nullable', 'string', 'max:190', Rule::unique('categories', 'slug')->ignore($category?->id)],
            'parent_id' => ['nullable', 'integer', $this->parentRule($category)],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'is_featured' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'remove_image' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Categories go two deep: a category sits at the top or under a top-level
     * one, and one that has others under it cannot itself move down.
     */
    protected function parentRule(?Category $category): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($category): void {
            if ($value === null || $value === '') {
                return;
            }

            $parent = Category::query()->find($value);

            if ($parent === null || $parent->parent_id !== null || $parent->is($category)) {
                $fail(__('panel.categories.invalidParent'));

                return;
            }

            if ($category !== null && $category->children()->exists()) {
                $fail(__('panel.categories.hasChildren'));
            }
        };
    }
}
