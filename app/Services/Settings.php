<?php

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

/**
 * Editable storefront settings.
 *
 * These are the handful of things this application owns rather than Overzaki:
 * the announcement strip, the pop-up's own copy, and the add-to-home-screen
 * prompt. Everything commercial — products, prices, offers, delivery — stays
 * in the Overzaki dashboard, so nothing here can contradict what a shopper is
 * actually charged.
 *
 * Stored as a single JSON file so there is no migration to run on deploy and
 * the whole configuration can be copied or backed up as one file.
 */
class Settings
{
    protected const CACHE_KEY = 'settings.store';

    /** Only these keys may be written, and only in these shapes. */
    public const SCHEMA = [
        'strip.enabled' => 'bool',
        'popup.enabled' => 'bool',
        'popup.title' => 'string',
        'popup.body' => 'string',
        'popup.image' => 'string',
        'popup.cta_label' => 'string',
        'popup.cta_path' => 'string',
        'popup.delay' => 'int',
        'popup.snooze_days' => 'int',
        'install.enabled' => 'bool',
        'install.delay' => 'int',
        'announcement' => 'string',
        'orders.alert' => 'bool',
        'orders.poll' => 'int',
        'orders.last_seen_at' => 'int',
    ];

    public function all(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            if (! File::exists($this->path())) {
                return [];
            }

            $decoded = json_decode((string) File::get($this->path()), true);

            return is_array($decoded) ? $decoded : [];
        });
    }

    /** A stored value, falling back to the matching config default. */
    public function get(string $key, mixed $default = null): mixed
    {
        $stored = Arr::get($this->all(), $key);

        if ($stored !== null && $stored !== '') {
            return $stored;
        }

        return $default ?? Arr::get(config('promo'), $this->configKey($key));
    }

    public function bool(string $key, bool $default = true): bool
    {
        $stored = Arr::get($this->all(), $key);

        if ($stored === null) {
            $fallback = Arr::get(config('promo'), $this->configKey($key));

            return $fallback === null ? $default : (bool) $fallback;
        }

        return (bool) $stored;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->get($key);

        return $value === null ? $default : (int) $value;
    }

    public function save(array $values): void
    {
        $current = $this->all();

        foreach ($values as $key => $value) {
            if (! isset(self::SCHEMA[$key])) {
                continue;   // ignore anything not in the schema
            }

            $cast = match (self::SCHEMA[$key]) {
                'bool' => (bool) $value,
                'int' => (int) $value,
                default => is_string($value) ? trim($value) : '',
            };

            Arr::set($current, $key, $cast);
        }

        File::ensureDirectoryExists(dirname($this->path()));
        File::put($this->path(), json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        Cache::forget(self::CACHE_KEY);
    }

    public function forget(): void
    {
        File::delete($this->path());
        Cache::forget(self::CACHE_KEY);
    }

    protected function path(): string
    {
        return storage_path('app/storefront-settings.json');
    }

    /** `popup.cta_label` has no config twin; map the ones that do. */
    protected function configKey(string $key): string
    {
        return match ($key) {
            'popup.cta_label', 'popup.cta_path' => 'popup.cta',
            default => $key,
        };
    }
}
