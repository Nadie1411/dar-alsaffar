<?php

namespace App\Services\Store\Payments;

use App\Models\GatewayCredential;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\QueryException;

/**
 * What MyFatoorah is called with: the key, the address, the webhook secret and
 * whether online payment is on at all.
 *
 * An owner can save these in the admin panel; otherwise they come from the
 * server's environment (config/myfatoorah.php). What was saved in the panel wins
 * when it is there, and removing it falls back to the server's setting.
 *
 * Nothing here ever hands a secret to anything but the code that calls the
 * gateway — the panel is told where each value comes from, never what it is.
 */
class GatewayConfig
{
    public const SOURCE_PANEL = 'panel';

    public const SOURCE_ENVIRONMENT = 'environment';

    public const SOURCE_NONE = 'none';

    protected ?GatewayCredential $saved = null;

    protected bool $loaded = false;

    protected bool $unreadable = false;

    public function apiKey(): string
    {
        return $this->secret('api_key') ?? (string) config('myfatoorah.api_key');
    }

    public function webhookSecret(): string
    {
        return $this->secret('webhook_secret') ?? (string) config('myfatoorah.webhook_secret');
    }

    public function apiUrl(): string
    {
        $chosen = $this->saved()?->api_url;

        // Only an address from the list is ever used, so a value that was not
        // put there by the panel's own form cannot send the key somewhere else.
        if (is_string($chosen) && in_array($chosen, $this->endpoints(), true)) {
            return $chosen;
        }

        return (string) config('myfatoorah.api_url');
    }

    public function enabled(): bool
    {
        $saved = $this->saved()?->enabled;

        return $saved ?? (bool) config('myfatoorah.enabled');
    }

    /** Whether the owner has switched the Apple Pay button on the shop's own page on. */
    public function applePayOnSite(): bool
    {
        return $this->saved()?->apple_pay_on_site ?? (bool) config('myfatoorah.apple_pay_on_site');
    }

    public function applePayOnSiteSource(): string
    {
        return $this->saved()?->apple_pay_on_site === null ? self::SOURCE_ENVIRONMENT : self::SOURCE_PANEL;
    }

    /**
     * The script that draws MyFatoorah's embedded button: the one that belongs
     * to the endpoint in use, and null for an address that is not on the list.
     */
    public function sdkUrl(): ?string
    {
        $environment = array_search(rtrim($this->apiUrl(), '/'), (array) config('myfatoorah.endpoints'), true);
        $url = $environment === false ? null : config('myfatoorah.sdk.'.$environment);

        return is_string($url) && str_starts_with($url, 'https://') ? $url : null;
    }

    /** Whether Apple Pay is taken on the shop's own page: switched on, and the button's script is known. */
    public function takesApplePayOnSite(): bool
    {
        return $this->applePayOnSite() && $this->sdkUrl() !== null;
    }

    /** The domain verification file as an owner pasted it, or null when there is none. */
    public function applePayDomainFile(): ?string
    {
        $file = $this->saved()?->apple_pay_domain_file;

        return is_string($file) && trim($file) !== '' ? $file : null;
    }

    /** The domain MyFatoorah last agreed to show Apple Pay on, and when. */
    public function applePayDomain(): ?string
    {
        return $this->saved()?->apple_pay_domain;
    }

    public function applePayRegisteredAt(): ?CarbonInterface
    {
        return $this->saved()?->apple_pay_registered_at;
    }

    /** Where the key in use comes from: the panel, the server's environment, or nowhere. */
    public function keySource(): string
    {
        return $this->sourceOf('api_key', 'api_key');
    }

    public function secretSource(): string
    {
        return $this->sourceOf('webhook_secret', 'webhook_secret');
    }

    public function urlSource(): string
    {
        return in_array($this->saved()?->api_url, $this->endpoints(), true) ? self::SOURCE_PANEL : self::SOURCE_ENVIRONMENT;
    }

    public function enabledSource(): string
    {
        return $this->saved()?->enabled === null ? self::SOURCE_ENVIRONMENT : self::SOURCE_PANEL;
    }

    /** Whether something is saved in the panel that can no longer be read — the application key changed. */
    public function hasUnreadableSecret(): bool
    {
        $this->secret('api_key');
        $this->secret('webhook_secret');

        return $this->unreadable;
    }

    /** Who saved the panel's settings last, and when — for the page to say, never the values. */
    public function lastSaved(): ?GatewayCredential
    {
        return $this->saved()?->loadMissing('updater');
    }

    /** Forgets what was read, so the next call looks again — after the panel has saved something. */
    public function refresh(): void
    {
        $this->saved = null;
        $this->loaded = false;
        $this->unreadable = false;
    }

    /** @return array<int,string> */
    protected function endpoints(): array
    {
        return array_values((array) config('myfatoorah.endpoints'));
    }

    protected function sourceOf(string $attribute, string $configKey): string
    {
        if ($this->secret($attribute) !== null) {
            return self::SOURCE_PANEL;
        }

        return filled(config('myfatoorah.'.$configKey)) ? self::SOURCE_ENVIRONMENT : self::SOURCE_NONE;
    }

    /** A value saved in the panel, decrypted, or null when there is none or it cannot be read. */
    protected function secret(string $attribute): ?string
    {
        try {
            $value = $this->saved()?->{$attribute};
        } catch (DecryptException) {
            $this->unreadable = true;

            return null;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    protected function saved(): ?GatewayCredential
    {
        if (! $this->loaded) {
            $this->loaded = true;

            try {
                $this->saved = GatewayCredential::query()->where('provider', GatewayCredential::MYFATOORAH)->first();
            } catch (QueryException) {
                // The table is not there yet, so nothing has been saved in the panel.
                $this->saved = null;
            }
        }

        return $this->saved;
    }
}
