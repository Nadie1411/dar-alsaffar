<?php

namespace App\Services\Store\Push;

use App\Models\PushSubscription;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends a Web Push message with no content.
 *
 * A message with a body has to be encrypted for the browser; one without needs
 * only the shop's signature (VAPID). The device wakes up, asks the panel what
 * happened — as the signed-in member of staff, so nothing about an order ever
 * passes through the push service — and shows it.
 */
class WebPush
{
    public function __construct(protected VapidKeys $keys) {}

    /** Whether an address is one of the browsers' push services, and so safe to post to. */
    public static function isAllowedEndpoint(string $endpoint): bool
    {
        $parts = parse_url($endpoint);

        if (($parts['scheme'] ?? '') !== 'https' || ! isset($parts['host']) || isset($parts['user']) || isset($parts['port'])) {
            return false;
        }

        $host = strtolower($parts['host']);

        foreach ((array) config('store.push.hosts') as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Tell one device something has happened. A device the push service says is
     * gone is forgotten; one that fails otherwise is counted and given up on
     * after a few failures in a row.
     */
    public function send(PushSubscription $subscription): bool
    {
        if (! self::isAllowedEndpoint($subscription->endpoint)) {
            $subscription->delete();

            return false;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => $this->authorization($subscription->endpoint),
                'TTL' => '3600',
                'Urgency' => 'high',
                'Content-Length' => '0',
            ])
                ->timeout((int) config('store.push.timeout'))
                ->withBody('', 'application/octet-stream')
                ->post($subscription->endpoint);
        } catch (ConnectionException $e) {
            return $this->failed($subscription, 'unreachable');
        }

        if ($response->successful()) {
            $subscription->update(['failures' => 0, 'last_sent_at' => now()]);

            return true;
        }

        // Gone, or never valid for this signing key: it will not start working.
        if (in_array($response->status(), [400, 401, 403, 404, 410], true)) {
            $subscription->delete();

            return false;
        }

        return $this->failed($subscription, (string) $response->status());
    }

    protected function failed(PushSubscription $subscription, string $why): bool
    {
        Log::warning('Push notification not delivered', ['why' => $why, 'subscription' => $subscription->id]);

        $failures = $subscription->failures + 1;

        $failures >= 5 ? $subscription->delete() : $subscription->update(['failures' => $failures]);

        return false;
    }

    protected function authorization(string $endpoint): string
    {
        $parts = parse_url($endpoint);
        $audience = $parts['scheme'].'://'.$parts['host'];

        $signing = VapidKeys::base64Url(json_encode(['typ' => 'JWT', 'alg' => 'ES256']))
            .'.'.VapidKeys::base64Url(json_encode([
                'aud' => $audience,
                'exp' => time() + 12 * 3600,
                'sub' => 'mailto:'.config('mail.from.address'),
            ]));

        openssl_sign($signing, $der, $this->keys->privateKeyPem(), OPENSSL_ALGO_SHA256);

        return 'vapid t='.$signing.'.'.VapidKeys::base64Url(self::derToRaw($der)).', k='.$this->keys->publicKey();
    }

    /** OpenSSL gives an ECDSA signature as ASN.1; a JWT wants the two 32-byte numbers side by side. */
    public static function derToRaw(string $der): string
    {
        $offset = 3;                                    // 0x30, length, 0x02 — or 0x30 0x81 length 0x02
        if (ord($der[1]) === 0x81) {
            $offset = 4;
        }

        $rLength = ord($der[$offset]);
        $r = substr($der, $offset + 1, $rLength);
        $s = substr($der, $offset + 3 + $rLength, ord($der[$offset + 2 + $rLength]));

        return str_pad(ltrim($r, "\0"), 32, "\0", STR_PAD_LEFT).str_pad(ltrim($s, "\0"), 32, "\0", STR_PAD_LEFT);
    }
}
