<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * URL slugs that are unique within a table.
 */
class Slug
{
    /**
     * @param  string  $name  what the slug is made from — the English name when there is one
     * @param  string  $fallback  used when the name leaves nothing usable, such as Arabic that does not transliterate
     */
    public static function unique(string $table, string $name, ?int $ignoreId = null, string $fallback = 'item'): string
    {
        $base = Str::slug($name) ?: $fallback;
        $candidate = $base;

        for ($suffix = 2; self::taken($table, $candidate, $ignoreId); $suffix++) {
            $candidate = $base.'-'.$suffix;
        }

        return $candidate;
    }

    /** A slug someone typed, tidied into the shape slugs take. */
    public static function tidy(string $slug): string
    {
        return Str::slug(trim($slug));
    }

    protected static function taken(string $table, string $slug, ?int $ignoreId): bool
    {
        return DB::table($table)
            ->where('slug', $slug)
            ->when($ignoreId !== null, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->exists();
    }
}
