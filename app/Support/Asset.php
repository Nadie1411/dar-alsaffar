<?php

namespace App\Support;

/**
 * Cache-busted asset URLs.
 *
 * CSS and JS are hand-authored and served straight from public/, with no build
 * step to fingerprint them. Appending the file's modification time means a
 * deploy — or an edit during development — is picked up immediately, including
 * by the service worker, which otherwise serves its cached copy forever.
 *
 * That only holds if the stamp is read fresh on every request. It used to be
 * cached forever in production — cheap to compute (one filemtime() stat) and
 * meant to save that stat, but on this deploy model the cache store lives in
 * storage/, which is a bind-mounted volume that survives every deploy. The
 * stamp for a file got computed once, the first time it was ever requested
 * after the volume was created, and then never again — every deploy since
 * updated the file on disk but kept handing out that same first-ever ?v=,
 * so the browser, and the service worker sitting in front of it caching by
 * that same URL, never had a reason to fetch the new one.
 */
class Asset
{
    public static function url(string $path): string
    {
        $path = ltrim($path, '/');
        $version = self::stamp($path);

        return asset($path).($version ? '?v='.$version : '');
    }

    protected static function stamp(string $path): ?string
    {
        $file = public_path($path);

        return is_file($file) ? (string) filemtime($file) : null;
    }
}
