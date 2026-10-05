<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\ProductRequest;
use App\Models\Category;
use App\Models\OptionGroup;
use App\Models\Product;
use App\Services\Store\ActivityLogger;
use App\Services\Store\ProductEditor;
use App\Support\PanelFormat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProductController extends Controller
{
    protected const PER_PAGE = 20;

    public function __construct(
        protected ProductEditor $editor,
        protected ActivityLogger $log,
    ) {}

    public function index(Request $request): View
    {
        $filters = $this->filters($request);

        $products = Product::query()
            ->with('categories')
            ->when($filters['q'] !== '', function (Builder $query) use ($filters): void {
                $term = '%'.$filters['q'].'%';

                $query->where(fn (Builder $query) => $query
                    ->where('name_ar', 'like', $term)
                    ->orWhere('name_en', 'like', $term)
                    ->orWhere('sku', 'like', $term)
                    ->orWhere('slug', 'like', $term));
            })
            ->when($filters['category'] !== '', fn (Builder $query) => $query->whereHas(
                'categories', fn (Builder $query) => $query->whereKey($filters['category'])
            ))
            ->when($filters['status'] === 'active', fn (Builder $query) => $query->where('is_active', true))
            ->when($filters['status'] === 'inactive', fn (Builder $query) => $query->where('is_active', false))
            ->when($filters['stock'] === 'out', fn (Builder $query) => $query->where('track_stock', true)->where('stock', '<=', 0))
            ->when($filters['stock'] === 'low', fn (Builder $query) => $query->where('track_stock', true)->where('stock', '>', 0)->whereColumn('stock', '<=', 'low_stock_threshold'))
            ->when($filters['stock'] === 'untracked', fn (Builder $query) => $query->where('track_stock', false))
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('panel.products.index', [
            'products' => $products,
            'filters' => $filters,
            'categories' => Category::query()->orderBy('sort_order')->orderBy('id')->get(),
            'total' => Product::query()->count(),
        ]);
    }

    public function create(): View
    {
        $product = new Product([
            'is_active' => true, 'cod_enabled' => true, 'discount_type' => Product::DISCOUNT_NONE,
            'sell_price_fils' => 0, 'stock' => 0, 'low_stock_threshold' => 0, 'sort_order' => 0,
        ]);

        return view('panel.products.form', $this->formData($product));
    }

    public function store(ProductRequest $request): RedirectResponse
    {
        $product = $this->editor->save(null, $request->validated());

        $this->log->record($request->user('staff'), 'product.created', $product);

        return redirect()->route('panel.products.edit', $product)->with('status', __('panel.products.created'));
    }

    public function edit(Product $product): View
    {
        return view('panel.products.form', $this->formData($product));
    }

    public function update(ProductRequest $request, Product $product): RedirectResponse
    {
        $this->editor->save($product, $request->validated());

        $this->log->record($request->user('staff'), 'product.updated', $product);

        return redirect()->route('panel.products.edit', $product)->with('status', __('panel.products.updated'));
    }

    public function destroy(Request $request, Product $product): RedirectResponse
    {
        $label = $product->name_ar;

        $this->editor->delete($product);

        $this->log->record($request->user('staff'), 'product.deleted', null, [], $label);

        return redirect()->route('panel.products.index')->with('status', __('panel.products.deleted'));
    }

    /** Switches a product on or off for the store without opening it. */
    public function toggle(Request $request, Product $product): RedirectResponse
    {
        $product->update(['is_active' => ! $product->is_active]);

        $this->log->record($request->user('staff'), $product->is_active ? 'product.activated' : 'product.deactivated', $product);

        return back()->with('status', $product->is_active ? __('panel.products.nowActive') : __('panel.products.nowInactive'));
    }

    public function duplicate(Request $request, Product $product): RedirectResponse
    {
        $copy = $this->editor->duplicate($product);

        $this->log->record($request->user('staff'), 'product.duplicated', $copy, ['from' => $product->id]);

        return redirect()->route('panel.products.edit', $copy)->with('status', __('panel.products.duplicated'));
    }

    /**
     * @return array{q:string,category:string,status:string,stock:string}
     */
    protected function filters(Request $request): array
    {
        $status = (string) $request->query('status', '');
        $stock = (string) $request->query('stock', '');
        $category = (string) $request->query('category', '');

        return [
            'q' => trim(str_replace(['%', '_', '\\'], ' ', (string) $request->query('q', ''))),
            'category' => ctype_digit($category) ? $category : '',
            'status' => in_array($status, ['active', 'inactive'], true) ? $status : '',
            'stock' => in_array($stock, ['in', 'low', 'out', 'untracked'], true) ? $stock : '',
        ];
    }

    /**
     * What the form shows. After a failed save the submitted rows come back as
     * they were typed; otherwise they are read from the product.
     *
     * @return array<string,mixed>
     */
    protected function formData(Product $product): array
    {
        $product->loadMissing(['images', 'categories', 'optionGroups.values', 'quantityTiers', 'related']);

        return [
            'product' => $product,
            'categories' => Category::query()->orderBy('sort_order')->orderBy('id')->get(),
            'others' => Product::query()
                ->when($product->exists, fn (Builder $query) => $query->whereKeyNot($product->id))
                ->orderBy('name_ar')
                ->get(['id', 'name_ar', 'name_en']),
            'selectedCategories' => collect(old('categories', $product->categories->modelKeys()))->map(fn ($id) => (int) $id)->all(),
            'selectedRelated' => collect(old('related', $product->related->modelKeys()))->map(fn ($id) => (int) $id)->all(),
            'optionRows' => old('options') !== null ? array_values(old('options')) : $this->optionRows($product),
            'tierRows' => old('tiers') !== null ? array_values(old('tiers')) : $this->tierRows($product),
            'price' => old('price', PanelFormat::amount($product->sell_price_fils)),
            'discountAmount' => old('discount_amount', $product->discount_fils > 0 ? PanelFormat::amount($product->discount_fils) : ''),
            'discountPercent' => old('discount_percent', (float) $product->discount_percent > 0 ? rtrim(rtrim(number_format((float) $product->discount_percent, 2, '.', ''), '0'), '.') : ''),
            'startsAt' => old('discount_starts_at', PanelFormat::inputDateTime($product->discount_starts_at)),
            'endsAt' => old('discount_ends_at', PanelFormat::inputDateTime($product->discount_ends_at)),
            'tags' => old('tags', implode('، ', $product->tags ?? [])),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    protected function optionRows(Product $product): array
    {
        return $product->optionGroups->map(fn (OptionGroup $group) => [
            'id' => $group->id,
            'name_ar' => $group->name_ar,
            'name_en' => $group->name_en,
            'layout' => $group->layout,
            'is_required' => $group->is_required,
            'is_active' => $group->is_active,
            'min_choices' => $group->min_choices,
            'max_choices' => $group->max_choices,
            'values' => $group->values->map(fn ($value) => [
                'id' => $value->id,
                'name_ar' => $value->name_ar,
                'name_en' => $value->name_en,
                'price' => PanelFormat::amount($value->price_fils),
                'is_active' => $value->is_active,
            ])->all(),
        ])->all();
    }

    /** @return array<int,array<string,mixed>> */
    protected function tierRows(Product $product): array
    {
        return $product->quantityTiers->map(fn ($tier) => [
            'id' => $tier->id,
            'min_quantity' => $tier->min_quantity,
            'discount_type' => $tier->discount_type,
            'discount_amount' => $tier->discount_fils > 0 ? PanelFormat::amount($tier->discount_fils) : '',
            'discount_percent' => (float) $tier->discount_percent > 0 ? rtrim(rtrim(number_format((float) $tier->discount_percent, 2, '.', ''), '0'), '.') : '',
            'free_delivery' => $tier->free_delivery,
            'label_ar' => $tier->label_ar,
            'label_en' => $tier->label_en,
        ])->all();
    }
}
