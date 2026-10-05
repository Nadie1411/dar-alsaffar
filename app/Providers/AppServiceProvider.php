<?php

namespace App\Providers;

use App\Enums\OrderStatus;
use App\Http\Middleware\SetLocale;
use App\Models\ContactMessage;
use App\Models\Order;
use App\Services\Settings;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Locale view data is normally set by SetLocale, but that middleware
        // only runs for matched routes — an unmatched URI renders the 404 view
        // without it. Sharing Arabic defaults here means every view, including
        // the error pages, always has them; the middleware overrides later.
        View::share([
            'locale' => 'ar',
            'localeCode' => SetLocale::DEFAULT,
            'dir' => 'rtl',
            'isRtl' => true,
            'altLocaleCode' => 'en-KW',
        ]);

        // Published contact points, admin-editable with the site's own
        // config as the fallback — computed once per request, the first
        // time a view actually renders, so the layout, the footer and the
        // contact page all read the same values.
        //
        // A composer, not View::share() at boot: share() would run this on
        // every application bootstrap, including `artisan package:discover`
        // during `composer install`/`composer dump-autoload` — a point
        // where there is no .env yet and no database file on disk. Settings
        // reads through the cache, which defaults to the database driver,
        // so that eager read crashed the Composer install step outright.
        View::composer('*', function ($view): void {
            static $shared = null;

            if ($shared === null) {
                $settings = $this->app->make(Settings::class);

                $shared = [
                    'contact' => [
                        'phone' => $settings->get('contact.phone', config('brand.contact.phone')),
                        'whatsapp' => $settings->get('contact.whatsapp', config('brand.contact.whatsapp')),
                        'email' => $settings->get('contact.email', config('brand.contact.email')),
                    ],
                    'social' => [
                        'instagram' => $settings->get('social.instagram', config('brand.social.instagram')),
                        'tiktok' => $settings->get('social.tiktok', config('brand.social.tiktok')),
                    ],
                ];
            }

            $view->with($shared);
        });

        // The two counts the panel's sidebar and bell show on every page.
        View::composer('panel.layout', function ($view): void {
            $view->with([
                'newOrders' => Order::query()->where('status', OrderStatus::New->value)->count(),
                'unreadMessages' => ContactMessage::query()->unread()->count(),
            ]);
        });

        Paginator::defaultView('vendor.pagination.darsaffar');
    }
}
