<?php

namespace Tests\Feature\Http\Controllers\Panel;

use App\Contracts\Store\Catalog;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\IsolatesUploads;
use Tests\Concerns\SignsInStaff;
use Tests\TestCase;

class CategoryManagementTest extends TestCase
{
    use IsolatesUploads, LazilyRefreshDatabase, SignsInStaff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->signInAs('manager');
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function form(array $overrides = []): array
    {
        return array_merge([
            'name_ar' => 'العود', 'name_en' => 'Oud', 'sort_order' => '3', 'is_active' => '1',
        ], $overrides);
    }

    public function test_the_list_shows_top_level_categories_with_their_children_beneath_and_product_counts(): void
    {
        $parent = Category::factory()->create(['name_en' => 'Perfumes', 'sort_order' => 1]);
        $child = Category::factory()->childOf($parent)->create(['name_en' => 'Oud Perfumes']);
        Category::factory()->create(['name_en' => 'Gifts', 'sort_order' => 2]);
        $child->products()->attach(Product::factory()->count(2)->create());

        $response = $this->get(route('panel.categories.index'))->assertOk();

        $response->assertSeeInOrder(['Perfumes', 'Oud Perfumes', 'Gifts']);
        $this->assertSame(2, $response->viewData('children')->get($parent->id)->first()->products_count);
    }

    public function test_an_empty_shop_invites_the_first_category(): void
    {
        $this->get(route('panel.categories.index'))->assertSee('No categories yet');
    }

    public function test_a_category_is_created_with_a_slug_made_from_its_english_name(): void
    {
        $this->post(route('panel.categories.store'), $this->form(['is_featured' => '1']))->assertRedirect(route('panel.categories.index'));

        $category = Category::query()->firstOrFail();

        $this->assertSame('oud', $category->slug);
        $this->assertSame('العود', $category->name_ar);
        $this->assertSame(3, $category->sort_order);
        $this->assertTrue($category->is_active);
        $this->assertTrue($category->is_featured);
        $this->assertNull($category->parent_id);
        $this->assertDatabaseHas('activity_logs', ['action' => 'category.created', 'subject_label' => 'العود']);
    }

    public function test_slugs_are_made_unique_and_a_typed_slug_is_tidied_and_checked(): void
    {
        $this->post(route('panel.categories.store'), $this->form());
        $this->post(route('panel.categories.store'), $this->form());
        $this->post(route('panel.categories.store'), $this->form(['name_en' => 'Other', 'slug' => ' Fancy  Slug ']));

        $this->assertSame(['oud', 'oud-2', 'fancy-slug'], Category::query()->orderBy('id')->pluck('slug')->all());

        $this->post(route('panel.categories.store'), $this->form(['slug' => 'oud']))->assertSessionHasErrors('slug');
    }

    public function test_both_names_are_required(): void
    {
        $this->post(route('panel.categories.store'), $this->form(['name_ar' => '', 'name_en' => '']))
            ->assertSessionHasErrors(['name_ar', 'name_en']);
    }

    public function test_a_category_can_sit_under_a_top_level_one_and_no_deeper(): void
    {
        $top = Category::factory()->create();
        $child = Category::factory()->childOf($top)->create();

        $this->post(route('panel.categories.store'), $this->form(['parent_id' => $top->id]))->assertSessionHasNoErrors();
        $this->assertSame($top->id, Category::query()->where('name_en', 'Oud')->firstOrFail()->parent_id);

        // A child is not a valid parent, and neither is a category that does not exist.
        $this->post(route('panel.categories.store'), $this->form(['name_en' => 'Deep', 'parent_id' => $child->id]))->assertSessionHasErrors('parent_id');
        $this->post(route('panel.categories.store'), $this->form(['name_en' => 'Ghost', 'parent_id' => 999]))->assertSessionHasErrors('parent_id');
    }

    public function test_a_category_cannot_become_its_own_parent_or_move_down_while_it_has_children(): void
    {
        $top = Category::factory()->create();
        $other = Category::factory()->create();
        Category::factory()->childOf($top)->create();

        $this->put(route('panel.categories.update', $top), $this->form(['parent_id' => $top->id]))->assertSessionHasErrors('parent_id');
        $this->put(route('panel.categories.update', $top), $this->form(['parent_id' => $other->id]))->assertSessionHasErrors('parent_id');

        $this->assertNull($top->fresh()->parent_id);
    }

    public function test_the_parent_list_leaves_out_the_category_being_edited_and_offers_nothing_to_one_with_children(): void
    {
        $top = Category::factory()->create();
        $other = Category::factory()->create();
        $lonely = Category::factory()->create();
        Category::factory()->childOf($top)->create();

        $this->assertEqualsCanonicalizing([$top->id, $other->id], $this->get(route('panel.categories.edit', $lonely))->viewData('parents')->modelKeys());
        $this->assertCount(0, $this->get(route('panel.categories.edit', $top))->viewData('parents'));
    }

    public function test_editing_keeps_an_address_that_came_from_the_old_storefront_exactly_as_it_was(): void
    {
        $category = Category::factory()->create(['slug' => 'Al-Oud', 'name_en' => 'Al Oud']);

        $this->put(route('panel.categories.update', $category), $this->form(['name_en' => 'Renamed', 'slug' => 'Al-Oud']))
            ->assertSessionHasNoErrors();

        $this->assertSame('Al-Oud', $category->fresh()->slug);
        $this->assertSame('Renamed', $category->fresh()->name_en);
    }

    public function test_a_picture_is_stored_replaced_and_removed(): void
    {
        $this->post(route('panel.categories.store'), $this->form(['image' => UploadedFile::fake()->image('oud.jpg', 500, 500)]));
        $category = Category::query()->firstOrFail();

        $this->assertMatchesRegularExpression('#^uploads/catalog/[A-Za-z0-9]{32}\.jpg$#', $category->image);
        $first = $category->image;
        $this->assertTrue($this->storedFileExists($first));

        $this->put(route('panel.categories.update', $category), $this->form(['image' => UploadedFile::fake()->image('new.png', 500, 500)]));
        $second = $category->fresh()->image;
        $this->assertNotSame($first, $second);
        $this->assertFalse($this->storedFileExists($first));
        $this->assertTrue($this->storedFileExists($second));

        $this->put(route('panel.categories.update', $category), $this->form(['remove_image' => '1']));
        $this->assertNull($category->fresh()->image);
        $this->assertFalse($this->storedFileExists($second));
    }

    public function test_only_pictures_are_accepted(): void
    {
        $this->post(route('panel.categories.store'), $this->form(['image' => UploadedFile::fake()->create('x.php', 5, 'application/x-php')]))
            ->assertSessionHasErrors('image');
        $this->post(route('panel.categories.store'), $this->form(['image' => UploadedFile::fake()->create('big.png', 5_000, 'image/png')]))
            ->assertSessionHasErrors('image');

        $this->assertSame(0, Category::query()->count());
    }

    public function test_deleting_a_category_keeps_its_products_and_removes_its_picture(): void
    {
        $file = $this->existingUpload('uploads/catalog/cat.jpg');
        $category = Category::factory()->create(['image' => $file]);
        $product = Product::factory()->create();
        $category->products()->attach($product);

        $this->delete(route('panel.categories.destroy', $category))->assertRedirect(route('panel.categories.index'));

        $this->assertNull(Category::query()->find($category->id));
        $this->assertNotNull(Product::query()->find($product->id));
        $this->assertSame(0, $product->categories()->count());
        $this->assertFalse($this->storedFileExists($file));
        $this->assertDatabaseHas('activity_logs', ['action' => 'category.deleted']);
    }

    public function test_a_category_with_sub_categories_cannot_be_deleted(): void
    {
        $top = Category::factory()->create();
        Category::factory()->childOf($top)->create();

        $this->delete(route('panel.categories.destroy', $top))->assertSessionHas('warning');

        $this->assertNotNull(Category::query()->find($top->id));
    }

    public function test_a_category_that_does_not_exist_is_a_404(): void
    {
        $this->get(route('panel.categories.edit', 999))->assertNotFound();
        $this->delete(route('panel.categories.destroy', 999))->assertNotFound();
    }

    public function test_a_category_switched_off_here_disappears_from_the_storefront(): void
    {
        $category = Category::factory()->create(['name_en' => 'Visible Shelf', 'slug' => 'visible-shelf']);
        $catalog = fn () => app(Catalog::class)->category('visible-shelf');

        $this->assertNotNull($catalog());

        $this->put(route('panel.categories.update', $category), $this->form(['name_en' => 'Visible Shelf', 'is_active' => null]));
        $this->startNewRequest();

        $this->assertNull($catalog());
        $this->get('/en-KW/categories/visible-shelf')->assertNotFound();
    }
}
