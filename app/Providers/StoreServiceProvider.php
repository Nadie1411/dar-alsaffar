<?php

namespace App\Providers;

use App\Contracts\Store\Cart;
use App\Contracts\Store\Catalog;
use App\Contracts\Store\Customers;
use App\Contracts\Store\Inbox;
use App\Contracts\Store\Orders;
use App\Contracts\Store\Promotions;
use App\Contracts\Store\Wishlist;
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
use App\Services\Store\Payments\GatewayConfig;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

/**
 * Chooses what stands behind the storefront: Overzaki, or this application's
 * own database. `STORE_BACKEND` decides, and it defaults to Overzaki so that
 * nothing changes until the owner flips it.
 */
class StoreServiceProvider extends ServiceProvider
{
    /**
     * @return array<class-string,class-string>
     */
    protected function overzaki(): array
    {
        return [
            Catalog::class => CatalogService::class,
            Cart::class => CartService::class,
            Orders::class => OrderService::class,
            Customers::class => AuthService::class,
            Wishlist::class => WishlistService::class,
            Promotions::class => PromotionService::class,
            Inbox::class => OverzakiInbox::class,
        ];
    }

    /**
     * What stands behind each contract once the store runs on its own
     * database. Request-scoped, so the header, the footer and the page itself
     * share one read of the catalogue instead of each making their own.
     *
     * @return array<class-string,class-string>
     */
    protected function local(): array
    {
        return [
            Catalog::class => LocalCatalog::class,
            Cart::class => LocalCart::class,
            Customers::class => LocalCustomers::class,
            Orders::class => LocalOrders::class,
            Wishlist::class => LocalWishlist::class,
            Promotions::class => LocalPromotions::class,
            Inbox::class => LocalInbox::class,
        ];
    }

    public function register(): void
    {
        $this->useBackend((string) config('store.backend'));

        // What the gateway is called with is read once per request (or per job)
        // and then remembered, rather than once per question asked of it.
        $this->app->scoped(GatewayConfig::class);
    }

    /**
     * Point every contract at the given backend's implementation: the local
     * one where it exists when the backend is "local", Overzaki's otherwise.
     */
    public function useBackend(string $backend): void
    {
        $local = $backend === 'local' ? $this->local() : [];

        foreach ($this->overzaki() as $contract => $overzaki) {
            isset($local[$contract])
                ? $this->app->scoped($contract, $local[$contract])
                : $this->app->bind($contract, $overzaki);
        }
    }

    public function boot(): void
    {
        // Sign-in, sign-up and password reset are what a password-guessing
        // script goes after. Keyed on the address and the email together, so a
        // shared network is not locked out by one person's typos, and one
        // account cannot be hammered from many places on the same network.
        // Webhooks come from MyFatoorah's servers, a handful at a time; this
        // is only a ceiling against someone using the address to flood us.
        RateLimiter::for('payment-webhook', fn (Request $request) => Limit::perMinute(120)->by($request->ip()));

        RateLimiter::for('customer-auth', fn (Request $request) => Limit::perMinute(5)->by(
            Str::lower((string) $request->input('email')).'|'.$request->ip()
        ));
    }
}
