<?php

namespace App\Support;

/**
 * Overzaki returns every human-facing string as a localised map, e.g.
 * ['en' => 'Perfumes', 'ar' => 'العطور', 'localized' => '...'].
 * These helpers pick the right variant for the active locale.
 */
class Loc
{
    public static function text(mixed $value, ?string $locale = null, string $fallback = ''): string
    {
        if (is_string($value)) {
            return trim($value);
        }

        if (! is_array($value)) {
            return $fallback;
        }

        $locale ??= app()->getLocale();

        foreach ([$locale, 'localized', 'ar', 'en'] as $key) {
            if (! empty($value[$key]) && is_string($value[$key])) {
                return trim($value[$key]);
            }
        }

        return $fallback;
    }

    /** Strip the rich-text HTML Overzaki stores for descriptions down to plain text. */
    public static function plain(mixed $value, ?int $limit = null, ?string $locale = null): string
    {
        // Block tags become spaces first, otherwise "…النعناع</p><p>قلب…"
        // collapses into one run-on word.
        $html = preg_replace('#<(br|/p|/div|/li|/h[1-6]|/tr)\b[^>]*>#i', ' ', self::text($value, $locale)) ?? '';
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        if ($limit !== null && mb_strlen($text) > $limit) {
            // A hard character cut landed mid-word (and, in Arabic, often
            // right after a conjunction like "و") often enough to read as
            // broken rather than trimmed. Back up to the last whole word.
            $cut = mb_substr($text, 0, $limit);
            $lastSpace = mb_strrpos($cut, ' ');
            if ($lastSpace !== false && $lastSpace > 0) {
                $cut = mb_substr($cut, 0, $lastSpace);
            }
            $text = rtrim($cut, ' ,.;:!?،؛؟-—').'…';
        }

        return $text;
    }

    /**
     * Description HTML is authored in the Overzaki dashboard, so it is trusted
     * content — but we still drop scripts, styles and inline handlers, and the
     * hardcoded font sizes that fight the site's own typography.
     */
    public static function html(mixed $value, ?string $locale = null): string
    {
        $html = self::text($value, $locale);

        if ($html === '') {
            return '';
        }

        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $html) ?? '';
        $html = preg_replace('/\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? '';
        $html = preg_replace('/\sstyle\s*=\s*("[^"]*"|\'[^\']*\')/i', '', $html) ?? '';

        return trim($html);
    }
}
