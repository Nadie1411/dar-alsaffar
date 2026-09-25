<?php

namespace App\Support;

use App\Http\Middleware\SetLocale;

/**
 * Locale-aware URL building. Every internal link goes through here so the
 * active /ar-KW or /en-KW prefix is never dropped by accident.
 */
class Nav
{
    public static function code(): string
    {
        return view()->shared('localeCode', SetLocale::DEFAULT);
    }

    public static function url(string $path = '', array $query = []): string
    {
        $path = trim($path, '/');
        $url = url('/'.self::code().($path !== '' ? '/'.$path : ''));

        return $query === [] ? $url : $url.'?'.http_build_query($query);
    }

    /**
     * The same page in the other language. Swapping only the first segment
     * keeps the shopper where they were instead of dumping them on the home
     * page — which is what the old site did.
     */
    public static function switchTo(string $targetCode): string
    {
        $segments = request()->segments();

        if ($segments === []) {
            return url('/'.$targetCode);
        }

        $segments[0] = $targetCode;
        $url = url('/'.implode('/', $segments));
        $query = request()->getQueryString();

        return $query ? $url.'?'.$query : $url;
    }

    public static function isActive(string $path): bool
    {
        return request()->is(self::code().'/'.trim($path, '/'))
            || request()->is(self::code().'/'.trim($path, '/').'/*');
    }

    public static function isHome(): bool
    {
        return request()->path() === self::code() || request()->path() === '/';
    }
}
