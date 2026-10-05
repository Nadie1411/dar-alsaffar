<?php

namespace App\Support;

/**
 * Phone numbers as the store handles them.
 */
class Phone
{
    /** Local 8-digit Kuwaiti numbers become +965XXXXXXXX. */
    public static function e164(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        $dial = config('brand.country.dial');

        if (str_starts_with($digits, $dial) && strlen($digits) > config('brand.country.phone_len')) {
            return '+'.$digits;
        }

        return '+'.$dial.$digits;
    }
}
