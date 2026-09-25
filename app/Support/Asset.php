<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Cache-busted asset URLs.
 *
 * CSS and JS are hand-authored and served straight from public/, with no build
 * step to fingerprint them. Appending the file's modification time means a
 * deploy — or an edit during development — is picked up immediately, including
 * by the service worker, which otherwise serves its cached copy forever.
 */
class Asset
{
    public static function url(string $path): string
    {
        $path = ltrim($path, '/');

        $version = app()->isProduction()
            ? Cache::rememberForever('asset:'.$path, fn () => self::stamp($path))
            : self::stamp($path);

        return asset($path).($version ? '?v='.$version : '');
    }

    protected static function stamp(string $path): ?string
    {
        $file = public_path($path);

        return is_file($file) ? (string) filemtime($file) : null;
    }
}
