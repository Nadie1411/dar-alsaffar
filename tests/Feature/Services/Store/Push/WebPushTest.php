<?php

namespace Tests\Feature\Services\Store\Push;

use App\Models\PushKey;
use App\Models\PushSubscription;
use App\Services\Store\Push\VapidKeys;
use App\Services\Store\Push\WebPush;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WebPushTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function decodeBase64Url(string $value): string
    {
        return base64_decode(strtr($value, '-_', '+/'));
    }

    public function test_the_signing_key_is_made_once_and_kept(): void
    {
        $first = app(VapidKeys::class)->publicKey();

        $this->app->forgetInstance(VapidKeys::class);

        $this->assertSame($first, app(VapidKeys::class)->publicKey());
        $this->assertSame(1, PushKey::query()->count());
        $this->assertSame(65, strlen($this->decodeBase64Url($first)));
        $this->assertSame("\x04", $this->decodeBase64Url($first)[0]);
    }

    public function test_the_private_half_is_encrypted_at_rest_and_never_serialised(): void
    {
        $pem = app(VapidKeys::class)->privateKeyPem();

        $stored = (string) DB::table('push_keys')->value('private_key');

        $this->assertStringContainsString('BEGIN', $pem);
        $this->assertStringNotContainsString('BEGIN', $stored);
        $this->assertArrayNotHasKey('private_key', PushKey::query()->firstOrFail()->toArray());
    }

    public function test_a_message_is_signed_so_the_push_service_can_check_it_against_the_public_key(): void
    {
        Http::fake(['*' => Http::response('', 201)]);
        $subscription = PushSubscription::factory()->create();

        $this->assertTrue(app(WebPush::class)->send($subscription));

        Http::assertSent(function (Request $request) use ($subscription) {
            $this->assertSame($subscription->endpoint, $request->url());
            $this->assertSame('', $request->body());
            $this->assertSame('high', $request->header('Urgency')[0]);

            preg_match('/^vapid t=([\w-]+)\.([\w-]+)\.([\w-]+), k=([\w-]+)$/', $request->header('Authorization')[0], $parts);
            $this->assertNotEmpty($parts, 'the header has the VAPID shape');

            [, $header, $claims, $signature, $key] = $parts;
            $this->assertSame(app(VapidKeys::class)->publicKey(), $key);
            $this->assertSame('ES256', json_decode($this->decodeBase64Url($header), true)['alg']);
            $this->assertSame('https://fcm.googleapis.com', json_decode($this->decodeBase64Url($claims), true)['aud']);
            $this->assertGreaterThan(time(), json_decode($this->decodeBase64Url($claims), true)['exp']);

            // Turn the raw signature back into what OpenSSL checks and verify it with the public point.
            $raw = $this->decodeBase64Url($signature);
            $this->assertSame(64, strlen($raw));
            $der = $this->toDer(substr($raw, 0, 32), substr($raw, 32));
            $point = $this->decodeBase64Url($key);
            $publicPem = $this->publicPem($point);

            $this->assertSame(1, openssl_verify($header.'.'.$claims, $der, $publicPem, OPENSSL_ALGO_SHA256));

            return true;
        });
        $this->assertNotNull($subscription->fresh()->last_sent_at);
    }

    public function test_a_device_the_push_service_no_longer_knows_is_forgotten(): void
    {
        Http::fake(['*' => Http::response('', 410)]);
        $subscription = PushSubscription::factory()->create();

        $this->assertFalse(app(WebPush::class)->send($subscription));

        $this->assertModelMissing($subscription);
    }

    public function test_a_temporary_failure_is_counted_and_the_device_is_given_up_on_after_five(): void
    {
        Http::fake(['*' => Http::response('', 503)]);
        $subscription = PushSubscription::factory()->create();

        for ($attempt = 1; $attempt <= 4; $attempt++) {
            app(WebPush::class)->send($subscription->fresh());
            $this->assertSame($attempt, $subscription->fresh()->failures);
        }

        app(WebPush::class)->send($subscription->fresh());

        $this->assertModelMissing($subscription);
    }

    public function test_a_success_clears_earlier_failures(): void
    {
        Http::fake(['*' => Http::response('', 201)]);
        $subscription = PushSubscription::factory()->create(['failures' => 3]);

        app(WebPush::class)->send($subscription);

        $this->assertSame(0, $subscription->fresh()->failures);
    }

    /**
     * @return array<string,array{0:string,1:bool}>
     */
    public static function endpoints(): array
    {
        return [
            'chrome' => ['https://fcm.googleapis.com/fcm/send/abc', true],
            'firefox' => ['https://updates.push.services.mozilla.com/wpush/v2/abc', true],
            'safari' => ['https://web.push.apple.com/abc', true],
            'edge' => ['https://wns2-par02p.notify.windows.com/w/?token=abc', true],
            'plain http' => ['http://fcm.googleapis.com/fcm/send/abc', false],
            'other host' => ['https://example.com/push', false],
            'internal address' => ['https://127.0.0.1/push', false],
            'look-alike suffix' => ['https://evilfcm.googleapis.com.example.com/x', false],
            'look-alike prefix' => ['https://notfcm.googleapis.com.evil.test/x', false],
            'a host merely ending in the same letters' => ['https://xpush.apple.com.example/x', false],
            'userinfo trick' => ['https://fcm.googleapis.com@evil.test/x', false],
            'odd port' => ['https://fcm.googleapis.com:8443/x', false],
            'not a url' => ['javascript:alert(1)', false],
        ];
    }

    #[DataProvider('endpoints')]
    public function test_only_the_browsers_push_services_are_ever_posted_to(string $endpoint, bool $allowed): void
    {
        $this->assertSame($allowed, WebPush::isAllowedEndpoint($endpoint));
    }

    public function test_a_stored_address_that_is_not_a_push_service_is_dropped_without_a_request(): void
    {
        Http::fake();
        $subscription = PushSubscription::factory()->create(['endpoint' => 'https://internal.example/admin', 'endpoint_hash' => 'x']);

        $this->assertFalse(app(WebPush::class)->send($subscription));

        Http::assertNothingSent();
        $this->assertModelMissing($subscription);
    }

    private function toDer(string $r, string $s): string
    {
        $integer = fn (string $n) => "\x02".chr(strlen($n = (ord($n[0]) > 0x7F ? "\0".$n : ltrim($n, "\0")))).$n;
        $body = $integer($r).$integer($s);

        return "\x30".chr(strlen($body)).$body;
    }

    private function publicPem(string $point): string
    {
        // SubjectPublicKeyInfo for prime256v1, followed by the uncompressed point.
        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200').$point;

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64)."-----END PUBLIC KEY-----\n";
    }
}
