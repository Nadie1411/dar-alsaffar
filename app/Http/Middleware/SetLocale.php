<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * URLs carry a locale-country segment — /ar-KW, /en-KW — exactly as the
 * previous storefront published them, so existing links and search results
 * keep working. Arabic is the default and the primary experience.
 */
class SetLocale
{
    public const SUPPORTED = ['ar-KW' => 'ar', 'en-KW' => 'en'];

    public const DEFAULT = 'ar-KW';

    public function handle(Request $request, Closure $next): Response
    {
        $segment = $request->route('locale') ?? self::DEFAULT;
        $locale = self::SUPPORTED[$segment] ?? 'ar';

        app()->setLocale($locale);

        view()->share([
            'locale' => $locale,
            'localeCode' => $segment,
            'dir' => $locale === 'ar' ? 'rtl' : 'ltr',
            'isRtl' => $locale === 'ar',
            'altLocaleCode' => $locale === 'ar' ? 'en-KW' : 'ar-KW',
        ]);

        return $next($request);
    }
}
