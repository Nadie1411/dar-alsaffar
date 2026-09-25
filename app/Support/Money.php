<?php

namespace App\Support;

/**
 * Kuwaiti Dinar formatting. The API returns the symbol as a localised map
 * (د.ك / KWD) alongside every priced payload, so we prefer whatever it sent
 * and only fall back to the configured default.
 */
class Money
{
    public const DECIMALS = 3;

    public static function symbol(mixed $symbol = null): string
    {
        $resolved = Loc::text($symbol);

        if ($resolved !== '') {
            return $resolved;
        }

        return app()->getLocale() === 'ar' ? 'د.ك' : 'KWD';
    }

    /**
     * KWD carries three decimals (fils), but whole-dinar prices read better
     * without the trailing zeros on a luxury storefront.
     */
    public static function amount(int|float|string|null $value): string
    {
        $number = (float) ($value ?? 0);
        $decimals = fmod($number, 1.0) === 0.0 ? 0 : self::DECIMALS;

        $formatted = number_format($number, $decimals, '.', ',');

        if ($decimals > 0) {
            $formatted = rtrim(rtrim($formatted, '0'), '.');
        }

        return $formatted;
    }

    public static function format(int|float|string|null $value, mixed $symbol = null): string
    {
        return self::amount($value).' '.self::symbol($symbol);
    }
}
