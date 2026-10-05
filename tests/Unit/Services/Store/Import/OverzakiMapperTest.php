<?php

namespace Tests\Unit\Services\Store\Import;

use App\Services\Store\Import\OverzakiMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\LoadsFixtures;
use Tests\TestCase;

class OverzakiMapperTest extends TestCase
{
    use LoadsFixtures;

    private function mapper(): OverzakiMapper
    {
        return new OverzakiMapper;
    }

    /**
     * @return array<string,mixed>
     */
    private function rawProduct(string $name): array
    {
        return $this->fixture('overzaki/product-'.$name)['data']['product'];
    }

    public function test_maps_a_discounted_product_to_whole_fils_and_utc_dates(): void
    {
        $mapped = $this->mapper()->product($this->rawProduct('albustan'));

        $this->assertSame([
            'slug' => 'ALBUSTAN',
            'sku' => 'ALB-1',
            'name_ar' => 'البستان',
            'name_en' => 'ALBUSTAN',
            'description_ar' => '<p>حمضيات منعشة</p>',
            'description_en' => '<p>Fresh citrus</p>',
            'sell_price_fils' => 35_000,
            'discount_type' => 'fixed',
            'discount_fils' => 16_000,
            'discount_percent' => 0.0,
            'discount_starts_at' => '2025-10-01 00:00:00',
            'discount_ends_at' => '2026-10-19 00:00:00',
            'track_stock' => false,
            'stock' => 0,
            'low_stock_threshold' => 0,
            'max_per_order' => null,
            'main_image' => 'https://cdn.example.com/img/albustan.jpg',
            'video' => null,
            'tags' => ['5 نجوم'],
            'is_active' => true,
            'is_featured' => true,
            'is_new' => false,
            'is_popular' => false,
            'cod_enabled' => true,
            'sort_order' => 1,
            'sales_count' => 17,
            'rating_average' => 0.0,
            'rating_count' => 0,
            'overzaki_id' => 'p-albustan',
        ], $mapped);
    }

    public function test_maps_stock_limits_ratings_and_empty_fields(): void
    {
        $mapped = $this->mapper()->product($this->rawProduct('jar'));

        $this->assertTrue($mapped['track_stock']);
        $this->assertSame(4, $mapped['stock']);
        $this->assertSame(2, $mapped['low_stock_threshold']);
        $this->assertSame(3, $mapped['max_per_order']);
        $this->assertFalse($mapped['cod_enabled']);
        $this->assertSame(4.5, $mapped['rating_average']);
        $this->assertSame(2, $mapped['rating_count']);
        $this->assertNull($mapped['description_en']);
        $this->assertNull($mapped['sku']);
        $this->assertNull($mapped['tags']);
        $this->assertNull($mapped['main_image']);
    }

    public function test_maps_a_percentage_discount_without_a_fixed_amount(): void
    {
        $mapped = $this->mapper()->product($this->rawProduct('package'));

        $this->assertSame('percentage', $mapped['discount_type']);
        $this->assertSame(12.5, $mapped['discount_percent']);
        $this->assertSame(0, $mapped['discount_fils']);
        $this->assertNull($mapped['discount_starts_at']);
        $this->assertNull($mapped['description_ar']);
    }

    /**
     * @return array<string, array{array<string,mixed>, array{string,int,float}}>
     */
    public static function discounts(): array
    {
        return [
            'a zero fixed discount is no discount' => [['discountType' => 'fixed_amount', 'discountValue' => 0], ['none', 0, 0.0]],
            'a percentage above 100 is capped at 100' => [['discountType' => 'percentage', 'discountValue' => 150], ['percentage', 0, 100.0]],
            'a decimal fixed amount becomes exact fils' => [['discountType' => 'fixed_amount', 'discountValue' => 2.25], ['fixed', 2_250, 0.0]],
            'an unknown discount type is ignored' => [['discountType' => 'bogus', 'discountValue' => 5], ['none', 0, 0.0]],
            'a missing discount is no discount' => [[], ['none', 0, 0.0]],
        ];
    }

    /**
     * @param  array<string,mixed>  $raw
     * @param  array{string,int,float}  $expected
     */
    #[DataProvider('discounts')]
    public function test_maps_discount_types_to_the_stored_form(array $raw, array $expected): void
    {
        $mapped = $this->mapper()->product(['_id' => 'p', 'slug' => 's', 'title' => ['en' => 'X'], 'sellPrice' => 10] + $raw);

        $this->assertSame($expected, [$mapped['discount_type'], $mapped['discount_fils'], $mapped['discount_percent']]);
    }

    public function test_a_deleted_or_inactive_product_is_not_live(): void
    {
        $base = ['_id' => 'p', 'slug' => 's', 'title' => ['en' => 'X']];

        $this->assertFalse($this->mapper()->product($base + ['status' => false])['is_active']);
        $this->assertFalse($this->mapper()->product($base + ['status' => true, 'isDelete' => true])['is_active']);
        $this->assertTrue($this->mapper()->product($base + ['status' => true, 'isDelete' => false])['is_active']);
    }

    public function test_gallery_leaves_out_the_main_image_and_repeats(): void
    {
        $raw = [
            'mainImage' => 'https://cdn.example.com/a.jpg',
            'images' => [
                'https://cdn.example.com/b.jpg',
                'https://cdn.example.com/a.jpg',
                ['url' => 'https://cdn.example.com/c.jpg'],
                'https://cdn.example.com/b.jpg',
                '',
                null,
            ],
        ];

        $this->assertSame(
            ['https://cdn.example.com/b.jpg', 'https://cdn.example.com/c.jpg'],
            $this->mapper()->gallery($raw)
        );
    }

    public function test_related_ids_come_from_bare_ids_or_embedded_products_without_repeats(): void
    {
        $raw = ['relatedProducts' => ['a', '', 7, null, 'b', ['_id' => 'c', 'slug' => 'c'], ['slug' => 'no-id'], 'a']];

        $this->assertSame(['a', 'b', 'c'], $this->mapper()->relatedIds($raw));
    }

    public function test_maps_quantity_packages_to_tiers_and_ignores_unusable_ones(): void
    {
        $tiers = $this->mapper()->quantityTiers($this->rawProduct('albustan'));

        $this->assertSame([
            ['min_quantity' => 1, 'discount_type' => 'none', 'discount_fils' => 0, 'discount_percent' => 0.0, 'free_delivery' => false, 'label_ar' => 'زجاجة', 'label_en' => '1 bottle'],
            ['min_quantity' => 2, 'discount_type' => 'fixed', 'discount_fils' => 5_000, 'discount_percent' => 0.0, 'free_delivery' => false, 'label_ar' => 'زجاجتان', 'label_en' => '2 bottles'],
            ['min_quantity' => 3, 'discount_type' => 'percentage', 'discount_fils' => 0, 'discount_percent' => 10.0, 'free_delivery' => true, 'label_ar' => '3 زجاجات', 'label_en' => '3 bottles'],
        ], $tiers);
    }

    public function test_packages_that_are_switched_off_or_absent_give_no_tiers(): void
    {
        $this->assertSame([], $this->mapper()->quantityTiers(['oneTimeDiscount' => ['enabled' => false, 'packages' => [['quantity' => 2, 'discountType' => 'fixed_amount', 'discountValue' => 5]]]]));
        $this->assertSame([], $this->mapper()->quantityTiers(['oneTimeDiscount' => null]));
        $this->assertSame([], $this->mapper()->quantityTiers([]));
    }

    public function test_a_checkbox_group_keeps_its_choice_limits_and_other_layouts_become_radio(): void
    {
        $checkbox = $this->mapper()->optionGroup([
            '_id' => 'g', 'name' => ['en' => 'Choose (3)', 'ar' => 'اختر (3)'], 'layout' => 'checkbox',
            'isRequired' => true, 'minimumChoises' => 3, 'maximumChoises' => 3, 'sortIndex' => 1,
        ]);
        $dropdown = $this->mapper()->optionGroup(['_id' => 'h', 'name' => ['en' => 'Size'], 'layout' => 'dropdown']);

        $this->assertSame('checkbox', $checkbox['layout']);
        $this->assertSame([3, 3, true], [$checkbox['min_choices'], $checkbox['max_choices'], $checkbox['is_required']]);
        $this->assertSame('radio', $dropdown['layout']);
        $this->assertFalse($dropdown['is_required']);
    }

    public function test_an_option_value_price_keeps_its_fils(): void
    {
        $mapped = $this->mapper()->optionValue(['_id' => 'v', 'name' => ['en' => '5 tola', 'ar' => '5 تولة'], 'price' => 145.5]);

        $this->assertSame(145_500, $mapped['price_fils']);
        $this->assertSame('5 تولة', $mapped['name_ar']);
    }

    public function test_a_name_falls_back_to_the_other_language(): void
    {
        $mapper = $this->mapper();

        $this->assertSame('Perfumes', $mapper->text(['en' => 'Perfumes', 'ar' => ''], 'ar'));
        $this->assertSame('العطور', $mapper->text(['ar' => 'العطور'], 'en'));
        $this->assertSame('Shown', $mapper->text(['localized' => 'Shown'], 'ar'));
        $this->assertSame('Plain', $mapper->text('  Plain ', 'en'));
        $this->assertSame('', $mapper->text(null, 'en'));
    }

    public function test_a_category_that_is_inactive_or_deleted_is_not_live(): void
    {
        $base = ['_id' => 'c', 'slug' => 'c', 'name' => ['en' => 'C']];

        $this->assertFalse($this->mapper()->category($base + ['isActive' => false])['is_active']);
        $this->assertFalse($this->mapper()->category($base + ['isActive' => true, 'isDelete' => true])['is_active']);
        $this->assertSame(4, $this->mapper()->category($base + ['sortIndex' => 4])['sort_order']);
    }

    public function test_maps_a_city_an_area_and_an_add_on(): void
    {
        $locations = $this->fixture('overzaki/locations')['data']['cities'][0];
        $addon = $this->fixture('overzaki/addons')['data'][0];

        $city = $this->mapper()->city($locations, 2);
        $closedArea = $this->mapper()->area($locations['areas'][1], 1);
        $mappedAddon = $this->mapper()->addon($addon, 0);

        $this->assertSame(['حولي', 'Hawalli', 2, 'city-hawalli'], [$city['name_ar'], $city['name_en'], $city['sort_order'], $city['overzaki_id']]);
        $this->assertSame([false, 1, 'area-closed'], [$closedArea['is_active'], $closedArea['sort_order'], $closedArea['overzaki_id']]);
        $this->assertSame(1_500, $mappedAddon['price_fils']);
        $this->assertSame('Wrapped by hand', $mappedAddon['description_en']);
    }
}
