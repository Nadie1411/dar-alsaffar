<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BundleController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\ContentController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\PaymentCallbackController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\WishlistController;
use App\Http\Middleware\SetLocale;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Storefront
|--------------------------------------------------------------------------
|
| URLs keep the /{lang}-KW shape the previous storefront published, so every
| indexed product and category link still resolves. A bare "/" redirects to
| the Arabic storefront, which is the primary experience.
|
*/

/*
|--------------------------------------------------------------------------
| Store control panel
|--------------------------------------------------------------------------
|
| Covers only what this application owns — the announcement strip, the pop-up
| and the install prompt. Returns 404 entirely until a password is set with
| `php artisan store:password`.
|
*/
Route::prefix('admin')->middleware('admin.legacy')->group(function () {
    Route::get('/login', [AdminController::class, 'showLogin'])->name('admin.login');
    Route::post('/login', [AdminController::class, 'login']);
    Route::post('/logout', [AdminController::class, 'logout'])->name('admin.logout');

    Route::middleware('admin')->group(function () {
        Route::get('/', [AdminController::class, 'index'])->name('admin.index');
        Route::post('/', [AdminController::class, 'update'])->name('admin.update');
        Route::post('/cache', [AdminController::class, 'clearCache'])->name('admin.cache');

        Route::get('/orders', [AdminController::class, 'orders'])->name('admin.orders');
        Route::get('/orders/feed', [AdminController::class, 'ordersFeed'])->name('admin.orders.feed');
        Route::post('/orders/seen', [AdminController::class, 'acknowledgeOrders'])->name('admin.orders.seen');
    });
});

// Served with its own route rather than as a static file so it can carry an
// explicit Cache-Control. Nothing set one otherwise, so browsers applied
// their own heuristic freshness to it and could sit for a while treating an
// old copy as good enough — which meant an update to what the service
// worker itself caches, like the one that motivated this route, could take
// a long time to even be checked for, let alone picked up. "no-cache" does
// not mean unfetched; it means the browser must always ask first, which a
// conditional GET against the Last-Modified/ETag below answers cheaply.
Route::get('/sw.js', fn () => response()->file(resource_path('sw.js'), [
    'Content-Type' => 'application/javascript',
    'Cache-Control' => 'no-cache, must-revalidate',
]))->name('sw');

Route::get('/', fn () => redirect('/'.SetLocale::DEFAULT));

// Legacy unprefixed paths keep their link equity.
Route::get('/{path}', fn (string $path) => redirect('/'.SetLocale::DEFAULT.'/'.$path, 301))
    ->where('path', 'products|categories|about-us|contact-us|brands|cart|wishlist');

Route::prefix('{locale}')
    ->where(['locale' => 'ar-KW|en-KW'])
    ->group(function () {

        Route::get('/', [HomeController::class, 'index'])->name('home');

        // ---- catalogue ---------------------------------------------------
        Route::get('/products', [CatalogController::class, 'index'])->name('products');
        Route::get('/products/{slug}', [CatalogController::class, 'show'])->name('product');
        Route::get('/categories', [CatalogController::class, 'categories'])->name('categories');
        Route::get('/categories/{slug}', [CatalogController::class, 'category'])->name('category');
        Route::get('/offers', [CatalogController::class, 'offers'])->name('offers');
        Route::get('/best-sellers', [CatalogController::class, 'bestSellers'])->name('best-sellers');

        // ---- bundles -----------------------------------------------------
        Route::get('/packages', [BundleController::class, 'index'])->name('packages');
        Route::get('/packages/{slug}', [BundleController::class, 'show'])->name('package');

        // ---- search ------------------------------------------------------
        Route::get('/search', [SearchController::class, 'index'])->name('search');
        Route::get('/search/suggest', [SearchController::class, 'suggest'])->name('search.suggest');

        // ---- cart --------------------------------------------------------
        Route::get('/cart', [CartController::class, 'index'])->name('cart');
        Route::post('/cart/add', [CartController::class, 'add'])->name('cart.add');
        Route::patch('/cart/{key}', [CartController::class, 'update'])->name('cart.update');
        Route::delete('/cart/{key}', [CartController::class, 'remove'])->name('cart.remove');
        Route::get('/cart/drawer', [CartController::class, 'drawer'])->name('cart.drawer');
        Route::post('/cart/voucher', [CartController::class, 'voucher'])->name('cart.voucher');

        // ---- checkout ----------------------------------------------------
        Route::get('/checkout', [CheckoutController::class, 'index'])->name('checkout');
        Route::post('/checkout', [CheckoutController::class, 'store'])->name('checkout.store');
        Route::get('/checkout/areas/{cityId?}', [CheckoutController::class, 'areas'])->name('checkout.areas');
        Route::get('/checkout/thanks/{order?}', [CheckoutController::class, 'thanks'])->name('checkout.thanks');
        Route::get('/checkout/failed', [CheckoutController::class, 'failed'])->name('checkout.failed');
        Route::get('/checkout/pending/{number?}', [CheckoutController::class, 'pending'])->name('checkout.pending');
        Route::get('/checkout/pay/{number}', [CheckoutController::class, 'pay'])->name('checkout.pay');
        Route::get('/payment/return', PaymentCallbackController::class)->name('payment.return');

        // ---- wishlist ----------------------------------------------------
        Route::get('/wishlist', [WishlistController::class, 'index'])->name('wishlist');
        Route::post('/wishlist/{productId}', [WishlistController::class, 'toggle'])->name('wishlist.toggle');

        // ---- auth --------------------------------------------------------
        Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
        Route::post('/login', [AuthController::class, 'login'])->name('login.post')->middleware('throttle:customer-auth');
        Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
        Route::post('/register', [AuthController::class, 'register'])->name('register.post')->middleware('throttle:customer-auth');
        Route::get('/forgot-password', [AuthController::class, 'showForgot'])->name('forgot');
        Route::post('/forgot-password', [AuthController::class, 'forgot'])->name('forgot.post')->middleware('throttle:customer-auth');
        Route::get('/reset-password/{token}', [PasswordResetController::class, 'show'])->name('password.reset');
        Route::post('/reset-password', [PasswordResetController::class, 'update'])->name('password.update')->middleware('throttle:customer-auth');
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

        // ---- account -----------------------------------------------------
        Route::prefix('account')->name('account.')->group(function () {
            Route::get('/', [AccountController::class, 'index'])->name('index');
            Route::get('/orders', [AccountController::class, 'orders'])->name('orders');
            Route::get('/orders/{id}', [AccountController::class, 'order'])->name('order');
            Route::get('/addresses', [AccountController::class, 'addresses'])->name('addresses');
            Route::get('/settings', [AccountController::class, 'settings'])->name('settings');
            Route::patch('/settings', [AccountController::class, 'update'])->name('settings.update');
        });

        // ---- content -----------------------------------------------------
        Route::get('/about-us', [ContentController::class, 'about'])->name('about');
        Route::get('/contact-us', [ContentController::class, 'contact'])->name('contact');
        Route::post('/contact-us', [ContentController::class, 'sendContact'])->name('contact.post');
        Route::get('/shipping', [ContentController::class, 'shipping'])->name('shipping');
        Route::get('/returns', [ContentController::class, 'returns'])->name('returns');
        Route::get('/privacy', [ContentController::class, 'privacy'])->name('privacy');
        Route::get('/terms', [ContentController::class, 'terms'])->name('terms');
        Route::post('/newsletter', [ContentController::class, 'newsletter'])->name('newsletter');
    });
