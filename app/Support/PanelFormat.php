<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * How the panel writes down money and moments.
 */
class PanelFormat
{
    /** The shop's own clock — see config/store.php. */
    public static function timezone(): string
    {
        return (string) config('store.timezone', 'Asia/Kuwait');
    }

    /** Whole fils as dinars with the three decimals a price in KWD is quoted in: 12.500. */
    public static function amount(int $fils): string
    {
        return number_format($fils / Money::FILS, 3, '.', ',');
    }

    public static function money(int $fils): string
    {
        return self::amount($fils).' '.Money::symbol();
    }

    public static function dateTime(?CarbonInterface $at): string
    {
        return self::at($at, 'D MMM YYYY، h:mm A');
    }

    public static function date(?CarbonInterface $at): string
    {
        return self::at($at, 'D MMM YYYY');
    }

    public static function time(?CarbonInterface $at): string
    {
        return self::at($at, 'h:mm A');
    }

    public static function ago(?CarbonInterface $at): string
    {
        return $at === null ? '—' : $at->copy()->locale(app()->getLocale())->diffForHumans();
    }

    /** A value for `<input type="datetime-local">`, in the shop's time. */
    public static function inputDateTime(?CarbonInterface $at): string
    {
        return $at === null ? '' : $at->copy()->timezone(self::timezone())->format('Y-m-d\TH:i');
    }

    /** A page of the storefront, in the language the panel is being read in. */
    public static function storeUrl(string $path = ''): string
    {
        $code = app()->getLocale() === 'en' ? 'en-KW' : 'ar-KW';

        return url('/'.$code.($path === '' ? '' : '/'.ltrim($path, '/')));
    }

    protected static function at(?CarbonInterface $at, string $format): string
    {
        if ($at === null) {
            return '—';
        }

        return $at->copy()->timezone(self::timezone())->locale(app()->getLocale())->isoFormat($format);
    }
}
