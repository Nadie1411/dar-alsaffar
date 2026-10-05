<?php

namespace App\Models\Concerns;

/**
 * Models that keep Arabic and English copy side by side in `{field}_ar` and
 * `{field}_en` columns. Reading a field returns the active locale's text and
 * falls back to the other language rather than showing a blank.
 */
trait HasLocalizedFields
{
    public function localized(string $field, ?string $locale = null): string
    {
        $locale ??= app()->getLocale();
        $other = $locale === 'ar' ? 'en' : 'ar';

        $value = $this->getAttribute($field.'_'.$locale);

        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        $value = $this->getAttribute($field.'_'.$other);

        return is_string($value) ? trim($value) : '';
    }

    /** @return array{ar:string,en:string} the `{ar, en}` map the storefront's view models expect */
    public function localizedMap(string $field): array
    {
        return [
            'ar' => (string) $this->getAttribute($field.'_ar'),
            'en' => (string) $this->getAttribute($field.'_en'),
        ];
    }
}
