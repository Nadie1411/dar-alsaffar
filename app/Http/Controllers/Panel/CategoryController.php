<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\CategoryRequest;
use App\Models\Category;
use App\Services\Store\ActivityLogger;
use App\Services\Store\Uploads;
use App\Support\Slug;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class CategoryController extends Controller
{
    public function __construct(
        protected Uploads $uploads,
        protected ActivityLogger $log,
    ) {}

    public function index(): View
    {
        $categories = Category::query()
            ->withCount('products')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $children = $categories->whereNotNull('parent_id')->groupBy('parent_id');

        return view('panel.categories.index', [
            'tree' => $categories->whereNull('parent_id')->values(),
            'children' => $children,
        ]);
    }

    public function create(): View
    {
        return view('panel.categories.form', [
            'category' => new Category(['is_active' => true, 'sort_order' => 0]),
            'parents' => $this->parents(null),
        ]);
    }

    public function store(CategoryRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $category = new Category;

        $this->fill($category, $request);
        $category->slug = $data['slug'] !== '' && $data['slug'] !== null
            ? $data['slug']
            : Slug::unique('categories', $data['name_en'] ?: $data['name_ar'], null, 'category');
        $category->save();

        $this->log->record($request->user('staff'), 'category.created', $category);

        return redirect()->route('panel.categories.index')->with('status', __('panel.categories.created'));
    }

    public function edit(Category $category): View
    {
        return view('panel.categories.form', [
            'category' => $category,
            'parents' => $this->parents($category),
        ]);
    }

    public function update(CategoryRequest $request, Category $category): RedirectResponse
    {
        $data = $request->validated();

        $replaced = $this->fill($category, $request);

        // Left blank, the slug stays as it was: a category's address should not
        // change just because it was edited.
        if (($data['slug'] ?? '') !== '') {
            $category->slug = $data['slug'];
        }

        $category->save();

        // The old picture goes once nothing points at it — which the delete itself checks.
        $this->uploads->delete($replaced);

        $this->log->record($request->user('staff'), 'category.updated', $category);

        return redirect()->route('panel.categories.index')->with('status', __('panel.categories.updated'));
    }

    public function destroy(Request $request, Category $category): RedirectResponse
    {
        if ($category->children()->exists()) {
            return back()->with('warning', __('panel.categories.deleteHasChildren'));
        }

        $image = $category->image;
        $label = $category->name_ar;

        $category->products()->detach();
        $category->delete();
        $this->uploads->delete($image);

        $this->log->record($request->user('staff'), 'category.deleted', null, [], $label);

        return redirect()->route('panel.categories.index')->with('status', __('panel.categories.deleted'));
    }

    /**
     * Sets what the form sets, apart from the slug, which create and update
     * treat differently.
     *
     * @return string|null the picture this category had before, to be removed once the change is saved
     */
    protected function fill(Category $category, CategoryRequest $request): ?string
    {
        $data = $request->validated();
        $previous = $category->image;

        $category->fill([
            'name_ar' => trim($data['name_ar']),
            'name_en' => trim($data['name_en']),
            'parent_id' => $data['parent_id'] ?? null,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'is_featured' => $request->boolean('is_featured'),
            'is_active' => $request->boolean('is_active'),
        ]);

        if ($request->hasFile('image')) {
            $category->image = $this->uploads->store($request->file('image'), 'catalog');
        } elseif ($request->boolean('remove_image')) {
            $category->image = null;
        }

        return $previous;
    }

    /**
     * Top-level categories a category could sit under — not itself, and none
     * at all for one that already has children of its own.
     *
     * @return Collection<int,Category>
     */
    protected function parents(?Category $category)
    {
        if ($category !== null && $category->children()->exists()) {
            return collect();
        }

        return Category::query()
            ->whereNull('parent_id')
            ->when($category?->exists, fn ($query) => $query->whereKeyNot($category->id))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }
}
