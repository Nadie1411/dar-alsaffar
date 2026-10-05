<?php

namespace App\Services\Store\Payments;

use Illuminate\Support\Facades\Cache;

/**
 * The ways to pay online that the shop offers at checkout: whichever of KNET,
 * cards, Apple Pay and Google Pay MyFatoorah says are enabled on the account.
 *
 * When that list cannot be had — the key is not allowed to read it, or
 * MyFatoorah is briefly down — checkout offers one plain "pay online" choice
 * and MyFatoorah's own page then shows whatever is enabled. Never guessed.
 */
class PaymentMethods
{
    /** The id that means "let MyFatoorah's page offer every enabled method". */
    public const ANY = 'all';

    /**
     * The only method names MyFatoorah's create-payment call accepts, in the
     * order they are shown.
     *
     * @var array<string,array{ar:string,en:string}>
     */
    protected const KNOWN = [
        'KNET' => ['ar' => 'كي نت', 'en' => 'KNET'],
        'CARD' => ['ar' => 'بطاقة (فيزا / ماستركارد)', 'en' => 'Card (Visa / Mastercard)'],
        'APPLE_PAY' => ['ar' => 'أبل باي', 'en' => 'Apple Pay'],
        'GOOGLE_PAY' => ['ar' => 'جوجل باي', 'en' => 'Google Pay'],
    ];

    public function __construct(protected MyFatoorahClient $client) {}

    /**
     * @return array<int,array{id:string,type:string,label:string}> empty when online payment is not available
     */
    public function enabled(): array
    {
        if (! $this->client->isConfigured()) {
            return [];
        }

        $key = 'myfatoorah:methods:'.app()->getLocale();
        $cached = Cache::get($key);

        if (is_array($cached)) {
            return $cached;
        }

        // One call to MyFatoorah either way. A list that could not be read is
        // remembered only briefly, so a short outage is not baked in for hours.
        $listed = $this->listed();
        $options = $this->options($listed);

        Cache::put($key, $options, now()->addMinutes($listed === null ? 10 : (int) config('myfatoorah.methods_cache_minutes')));

        return $options;
    }

    /** Whether a method id from the checkout form is one this shop offers. */
    public function offers(string $id): bool
    {
        return in_array($id, array_column($this->enabled(), 'id'), true);
    }

    /**
     * @return array<int,string>|null the enabled method names we can use, null when the list is unavailable
     */
    protected function listed(): ?array
    {
        try {
            return collect($this->client->paymentMethods())
                ->pluck('apiName')
                ->map(fn (string $name) => strtoupper($name))
                ->filter(fn (string $name) => isset(self::KNOWN[$name]))
                ->unique()
                ->values()
                ->all();
        } catch (MyFatoorahException) {
            return null;
        }
    }

    /**
     * @param  array<int,string>|null  $listed
     * @return array<int,array{id:string,type:string,label:string}>
     */
    protected function options(?array $listed): array
    {
        $locale = app()->getLocale() === 'en' ? 'en' : 'ar';

        if ($listed === null || $listed === []) {
            return [['id' => self::ANY, 'type' => 'online', 'label' => __('storefront.checkout.onlineAny')]];
        }

        return collect(array_keys(self::KNOWN))
            ->filter(fn (string $name) => in_array($name, $listed, true))
            ->map(fn (string $name) => ['id' => $name, 'type' => strtolower($name), 'label' => self::KNOWN[$name][$locale]])
            ->values()
            ->all();
    }
}
