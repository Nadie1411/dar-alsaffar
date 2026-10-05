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

    /** Fils in one dinar. Money is stored and added up as whole fils. */
    public const FILS = 1000;

    /** @var array{ar:string,en:string} The dinar's symbol, as the localised map the views read. */
    public const KWD = ['ar' => 'د.ك', 'en' => 'KWD'];

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

    /** An amount in dinars as whole fils, rounded to the nearest fils. */
    public static function toFils(int|float|string|null $amount): int
    {
        return (int) round(((float) ($amount ?? 0)) * self::FILS);
    }

    /** Whole fils as dinars, for the places that still speak in decimals. */
    public static function fromFils(int $fils): int|float
    {
        return $fils % self::FILS === 0 ? intdiv($fils, self::FILS) : $fils / self::FILS;
    }

    /**
     * What a person typed as an amount in dinars, as whole fils — or null when
     * it is not an amount. Done on the digits themselves, never through a
     * float, so 12.345 is exactly 12,345 fils.
     *
     * Arabic-Indic digits and the Arabic decimal separator are understood, as
     * is a decimal comma, because that is what an Arabic keyboard produces.
     */
    public static function parseFils(?string $input): ?int
    {
        $text = self::normaliseNumber($input);

        if (preg_match('/^\d{1,7}(\.\d{1,3})?$/', $text) !== 1) {
            return null;
        }

        [$whole, $fraction] = array_pad(explode('.', $text, 2), 2, '');

        return (int) $whole * self::FILS + (int) str_pad($fraction, self::DECIMALS, '0');
    }

    /**
     * A percentage typed by a person, as basis points (12.5% is 1250) so it
     * can be stored and applied without floating point — or null when it is
     * not a percentage between 0 and 100.
     */
    public static function parsePercentBasisPoints(?string $input): ?int
    {
        $text = self::normaliseNumber($input);

        if (preg_match('/^\d{1,3}(\.\d{1,2})?$/', $text) !== 1) {
            return null;
        }

        [$whole, $fraction] = array_pad(explode('.', $text, 2), 2, '');
        $basisPoints = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');

        return $basisPoints <= 10_000 ? $basisPoints : null;
    }

    /** Western digits, a point for the decimal separator, and no grouping marks or spaces. */
    protected static function normaliseNumber(?string $input): string
    {
        $text = strtr(trim((string) $input), [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٫' => '.', ',' => '.', '٬' => '', ' ' => '',
        ]);

        return $text;
    }
}
