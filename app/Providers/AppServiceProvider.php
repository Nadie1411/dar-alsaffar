<?php

namespace App\Providers;

use App\Http\Middleware\SetLocale;
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

        Paginator::defaultView('vendor.pagination.darsaffar');
    }
}
