<?php

namespace Tests\Unit\Support;

use App\Support\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MoneyParsingTest extends TestCase
{
    /**
     * @return array<string,array{0:?string,1:?int}>
     */
    public static function dinars(): array
    {
        return [
            'whole dinars' => ['5', 5_000],
            'a point and three decimals' => ['12.345', 12_345],
            'fewer decimals are padded' => ['12.5', 12_500],
            'one fil' => ['0.001', 1],
            'a leading point' => ['.5', null],
            'zero' => ['0', 0],
            'a decimal comma' => ['12,5', 12_500],
            'Arabic-Indic digits' => ['١٢٫٥٠٠', 12_500],
            'Persian digits' => ['۱۲٫۵', 12_500],
            'an Arabic thousands mark and spaces are ignored' => ['1 234٫5', 1_234_500],
            'the largest amount accepted' => ['9999999.999', 9_999_999_999],
            'surrounding space' => ['  7.250  ', 7_250],
            'four decimals' => ['12.5555', null],
            'too many digits' => ['12345678', null],
            'a negative amount' => ['-5', null],
            'a plus sign' => ['+5', null],
            'a currency symbol' => ['5 KWD', null],
            'letters' => ['abc', null],
            'two points' => ['1.2.3', null],
            'empty' => ['', null],
            'null' => [null, null],
            'only a point' => ['.', null],
            'scientific notation' => ['1e3', null],
        ];
    }

    #[DataProvider('dinars')]
    public function test_what_a_person_types_becomes_whole_fils_or_nothing(?string $typed, ?int $fils): void
    {
        $this->assertSame($fils, Money::parseFils($typed));
    }

    public function test_the_conversion_is_exact_where_a_float_would_not_be(): void
    {
        // 0.1 + 0.2 style traps: every one of these must land on the fil it names.
        foreach ([['0.1', 100], ['0.7', 700], ['1.005', 1_005], ['19.999', 19_999], ['4.35', 4_350], ['8.2', 8_200]] as [$typed, $fils]) {
            $this->assertSame($fils, Money::parseFils($typed), $typed);
        }
    }

    /**
     * @return array<string,array{0:?string,1:?int}>
     */
    public static function percentages(): array
    {
        return [
            'whole' => ['10', 1_000],
            'a half' => ['12.5', 1_250],
            'two decimals' => ['7.25', 725],
            'a hundred' => ['100', 10_000],
            'a hundred with zeros' => ['100.00', 10_000],
            'zero' => ['0', 0],
            'Arabic digits' => ['٥٠', 5_000],
            'a decimal comma' => ['2,5', 250],
            'just over a hundred' => ['100.01', null],
            'over a hundred' => ['120', null],
            'three decimals' => ['7.255', null],
            'negative' => ['-5', null],
            'a percent sign' => ['10%', null],
            'empty' => ['', null],
            'null' => [null, null],
        ];
    }

    #[DataProvider('percentages')]
    public function test_a_percentage_becomes_basis_points_or_nothing(?string $typed, ?int $basisPoints): void
    {
        $this->assertSame($basisPoints, Money::parsePercentBasisPoints($typed));
    }
}
