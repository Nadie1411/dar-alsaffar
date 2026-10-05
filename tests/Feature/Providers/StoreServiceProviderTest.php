<?php

namespace Tests\Feature\Providers;

use App\Contracts\Store\Cart;
use App\Contracts\Store\Catalog;
use App\Contracts\Store\Customers;
use App\Contracts\Store\Inbox;
use App\Contracts\Store\Orders;
use App\Contracts\Store\Promotions;
use App\Contracts\Store\Wishlist;
use App\Providers\StoreServiceProvider;
use App\Services\Overzaki\AuthService;
use App\Services\Overzaki\CartService;
use App\Services\Overzaki\CatalogService;
use App\Services\Overzaki\OrderService;
use App\Services\Overzaki\OverzakiInbox;
use App\Services\Overzaki\PromotionService;
use App\Services\Overzaki\WishlistService;
use App\Services\Store\LocalCart;
use App\Services\Store\LocalCatalog;
use App\Services\Store\LocalCustomers;
use App\Services\Store\LocalInbox;
use App\Services\Store\LocalOrders;
use App\Services\Store\LocalPromotions;
use App\Services\Store\LocalWishlist;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StoreServiceProviderTest extends TestCase
{
    /**
     * @return array<string, array{class-string,class-string,class-string}>
     */
    public static function backends(): array
    {
        return [
            'catalogue' => [Catalog::class, CatalogService::class, LocalCatalog::class],
            'cart' => [Cart::class, CartService::class, LocalCart::class],
            'orders' => [Orders::class, OrderService::class, LocalOrders::class],
            'customers' => [Customers::class, AuthService::class, LocalCustomers::class],
            'wishlist' => [Wishlist::class, WishlistService::class, LocalWishlist::class],
            'promotions' => [Promotions::class, PromotionService::class, LocalPromotions::class],
            'inbox' => [Inbox::class, OverzakiInbox::class, LocalInbox::class],
        ];
    }

    public function test_the_store_runs_on_overzaki_until_it_is_switched(): void
    {
        $this->assertSame('overzaki', config('store.backend'));
    }

    /**
     * @param  class-string  $contract
     * @param  class-string  $overzaki
     * @param  class-string  $local
     */
    #[DataProvider('backends')]
    public function test_each_contract_resolves_to_overzaki_by_default_and_to_the_local_service_once_switched(string $contract, string $overzaki, string $local): void
    {
        $this->assertInstanceOf($overzaki, $this->app->make($contract));

        $this->app->getProvider(StoreServiceProvider::class)->useBackend('local');

        $this->assertInstanceOf($local, $this->app->make($contract));
    }

    public function test_every_contract_has_a_local_implementation_so_the_switch_leaves_nothing_on_overzaki(): void
    {
        $this->app->getProvider(StoreServiceProvider::class)->useBackend('local');

        foreach (array_keys(self::backends()) as $name) {
            [$contract] = self::backends()[$name];

            $this->assertStringStartsWith('App\\Services\\Store\\', $this->app->make($contract)::class, $name);
        }
    }

    public function test_the_local_catalogue_and_wishlist_are_shared_within_a_request_so_they_read_once(): void
    {
        $this->app->getProvider(StoreServiceProvider::class)->useBackend('local');

        $this->assertSame($this->app->make(Catalog::class), $this->app->make(Catalog::class));
        $this->assertSame($this->app->make(Wishlist::class), $this->app->make(Wishlist::class));
    }
}
