<?php

namespace App\Providers;

use App\Http\Middleware\SetLocale;
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
        // config as the fallback — shared once so the layout, the footer
        // and the contact page all read the same values.
        $settings = $this->app->make(Settings::class);

        View::share('contact', [
            'phone' => $settings->get('contact.phone', config('brand.contact.phone')),
            'whatsapp' => $settings->get('contact.whatsapp', config('brand.contact.whatsapp')),
            'email' => $settings->get('contact.email', config('brand.contact.email')),
        ]);

        View::share('social', [
            'instagram' => $settings->get('social.instagram', config('brand.social.instagram')),
            'tiktok' => $settings->get('social.tiktok', config('brand.social.tiktok')),
        ]);

        Paginator::defaultView('vendor.pagination.darsaffar');
    }
}
