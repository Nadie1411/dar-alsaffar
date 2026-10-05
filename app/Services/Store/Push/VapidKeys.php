<?php

namespace App\Services\Store\Push;

use App\Models\PushKey;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * The shop's signing key for push messages (VAPID, RFC 8292): an ECDSA P-256
 * pair, made the first time it is needed and kept in the database, the private
 * half encrypted. Browsers are given the public half when they subscribe, and
 * a message signed with anything else is refused by the push service.
 */
class VapidKeys
{
    protected ?PushKey $key = null;

    /** The public key as the browser wants it: the uncompressed point, URL-safe base64. */
    public function publicKey(): string
    {
        return $this->key()->public_key;
    }

    public function privateKeyPem(): string
    {
        return $this->key()->private_key;
    }

    protected function key(): PushKey
    {
        return $this->key ??= PushKey::query()->first() ?? $this->generate();
    }

    protected function generate(): PushKey
    {
        // Two requests arriving together on a fresh install must not each make a pair.
        return Cache::lock('push-keys', 10)->block(10, function (): PushKey {
            return PushKey::query()->first() ?? $this->make();
        });
    }

    protected function make(): PushKey
    {
        $pair = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);

        if ($pair === false || ! openssl_pkey_export($pair, $pem)) {
            throw new RuntimeException('Could not create a push signing key: '.openssl_error_string());
        }

        $ec = openssl_pkey_get_details($pair)['ec'];
        $point = "\x04".str_pad($ec['x'], 32, "\0", STR_PAD_LEFT).str_pad($ec['y'], 32, "\0", STR_PAD_LEFT);

        return PushKey::query()->create(['public_key' => self::base64Url($point), 'private_key' => $pem]);
    }

    public static function base64Url(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }
}
