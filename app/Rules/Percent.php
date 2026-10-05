<?php

namespace App\Rules;

use App\Support\Money;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A percentage from 0 to 100 with up to two decimals.
 */
class Percent implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (Money::parsePercentBasisPoints(is_scalar($value) ? (string) $value : null) === null) {
            $fail('panel.validation.percent')->translate();
        }
    }
}
