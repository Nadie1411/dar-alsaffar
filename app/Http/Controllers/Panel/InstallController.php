<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

/**
 * What lets a phone keep the panel on its home screen and be woken by it: the
 * app manifest and the service worker. Both are public — a browser fetches
 * them without the session — and neither holds anything private.
 */
class InstallController extends Controller
{
    public function manifest(): Response
    {
        $locale = app()->getLocale() === 'en' ? 'en' : 'ar';

        return response(json_encode([
            'name' => __('panel.app').' — '.config('brand.name.'.$locale),
            'short_name' => config('brand.name.'.$locale),
            'lang' => $locale,
            'dir' => $locale === 'ar' ? 'rtl' : 'ltr',
            'id' => '/panel',
            'start_url' => '/panel?source=pwa',
            'scope' => '/panel',
            'display' => 'standalone',
            'orientation' => 'portrait',
            'background_color' => '#0f1412',
            'theme_color' => '#0f1412',
            'icons' => [
                ['src' => '/assets/brand/icon-192-maskable.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable'],
                ['src' => '/assets/brand/icon-512-maskable.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'],
                ['src' => '/assets/brand/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 200, [
            'Content-Type' => 'application/manifest+json',
            'Cache-Control' => 'no-cache',
        ]);
    }

    public function worker(): Response
    {
        return response()->view('panel.sw')->withHeaders([
            'Content-Type' => 'text/javascript; charset=utf-8',
            // A worker that is cached for long is a worker that is not updated.
            'Cache-Control' => 'no-cache, max-age=0',
        ]);
    }
}
