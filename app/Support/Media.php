<?php

namespace App\Support;

/**
 * Image and file URLs for what the store keeps in its own uploads folder.
 *
 * A stored value is either a path under public/ (uploads/catalog/...) or, for
 * anything not yet copied across, a full URL. Both resolve here.
 */
class Media
{
    public static function url(?string $path): ?string
    {
        if ($path === null || trim($path) === '') {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return asset(ltrim($path, '/'));
    }
}
