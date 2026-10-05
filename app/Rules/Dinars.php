<?php

namespace App\Rules;

use App\Support\Money;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * An amount in dinars with up to three decimals, as a person would type it.
 */
class Dinars implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (Money::parseFils(is_scalar($value) ? (string) $value : null) === null) {
            $fail('panel.validation.dinars')->translate();
        }
    }
}
