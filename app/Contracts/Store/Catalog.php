<?php

namespace App\Contracts\Store;

use App\Services\Overzaki\DTO\Product;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Everything the storefront reads about what is for sale.
 */
interface Catalog
{
    /** @return array<int,Product> */
    public function all(): array;

    public function find(string $slug): ?Product;

    /** @return array<int,Product> */
    public function related(string $productId, int $limit = 8): array;

    /** @return array<int,array<string,mixed>> */
    public function categories(bool $topLevelOnly = false): array;

    /** @return array<string,mixed>|null */
    public function category(string $slug): ?array;

    /** @return array<int,array<string,mixed>> */
    public function categoriesWithCounts(bool $topLevelOnly = true): array;

    /** @return array<int,Product> */
    public function bestSellers(int $limit = 8): array;

    /** @return array<int,Product> */
    public function newArrivals(int $limit = 8): array;

    /** @return array<int,Product> */
    public function onOffer(int $limit = 8): array;

    /** @return array<int,Product> */
    public function featured(int $limit = 8): array;

    /** @return array<int,Product> */
    public function search(string $term, int $limit = 24): array;

    /**
     * @param  array{category?:string|array<int,string>,min?:float|string|null,max?:float|string|null,availability?:string,offers?:bool,sort?:string,q?:string}  $filters
     */
    public function filterable(array $filters, int $perPage = 12, int $page = 1): LengthAwarePaginator;

    /** @return array{min:int,max:int} */
    public function priceRange(): array;
}
