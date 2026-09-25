<?php

namespace App\Services\Overzaki;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin transport layer over the Overzaki storefront API.
 *
 * Every call carries the tenant and currency headers the API resolves the
 * store by. Responses are unwrapped from the `{success, statusCode, data}`
 * envelope so callers deal in plain arrays.
 */
class OverzakiClient
{
    protected ?string $token = null;

    public function withToken(?string $token): static
    {
        $clone = clone $this;
        $clone->token = $token;

        return $clone;
    }

    public function get(string $path, array $query = []): array
    {
        return $this->unwrap($this->request()->get($this->url($path), $query), 'GET', $path);
    }

    public function post(string $path, array $body = [], array $query = []): array
    {
        $url = $this->url($path);

        if ($query !== []) {
            $url .= '?'.http_build_query($query);
        }

        return $this->unwrap($this->request()->post($url, $body), 'POST', $path);
    }

    public function patch(string $path, array $body = []): array
    {
        return $this->unwrap($this->request()->patch($this->url($path), $body), 'PATCH', $path);
    }

    public function delete(string $path, array $body = []): array
    {
        return $this->unwrap($this->request()->delete($this->url($path), $body), 'DELETE', $path);
    }

    /**
     * Like post(), but hands validation failures back to the caller instead of
     * throwing — checkout and auth both need to show the API's own messages.
     */
    public function postRaw(string $path, array $body = [], array $query = []): array
    {
        $url = $this->url($path);

        if ($query !== []) {
            $url .= '?'.http_build_query($query);
        }

        $response = $this->request()->post($url, $body);
        $json = $response->json() ?? [];

        return [
            'ok' => $response->successful() && ($json['success'] ?? true) !== false,
            'status' => $response->status(),
            'data' => $json['data'] ?? null,
            'message' => $this->messageFrom($json),
            'raw' => $json,
        ];
    }

    /**
     * Fetch several GET endpoints at once.
     *
     * The catalogue list omits option groups, so products priced entirely by
     * their options need a detail call each. Running them concurrently keeps
     * that to roughly one round trip instead of one per product.
     *
     * @param  array<string,string>  $paths  keyed by whatever the caller wants back
     * @return array<string,array>
     */
    public function pool(array $paths): array
    {
        if ($paths === []) {
            return [];
        }

        $headers = [
            'x-tenant-id' => config('overzaki.tenant_id'),
            'x-currency-id' => config('overzaki.currency_id'),
            'Accept' => 'application/json',
            'Accept-Language' => app()->getLocale(),
        ];

        $timeout = config('overzaki.timeout');

        $responses = Http::pool(fn (Pool $pool) => array_map(
            fn (string $path, string $key) => $pool->as($key)
                ->withHeaders($headers)
                ->timeout($timeout)
                ->acceptJson()
                ->get($this->url($path)),
            array_values($paths),
            array_keys($paths)
        ));

        $out = [];

        foreach ($paths as $key => $path) {
            $response = $responses[$key] ?? null;

            if (! $response instanceof Response || $response->failed()) {
                continue;
            }

            $json = $response->json();

            if (! is_array($json)) {
                continue;
            }

            $data = array_key_exists('data', $json) ? $json['data'] : $json;

            if (is_array($data)) {
                $out[$key] = $data;
            }
        }

        return $out;
    }

    protected function request(): PendingRequest
    {
        $headers = [
            'x-tenant-id' => config('overzaki.tenant_id'),
            'x-currency-id' => config('overzaki.currency_id'),
            'Accept' => 'application/json',
            'Accept-Language' => app()->getLocale(),
        ];

        if ($this->token) {
            $headers['Authorization'] = 'Bearer '.$this->token;
        }

        return Http::withHeaders($headers)
            ->timeout(config('overzaki.timeout'))
            ->retry(config('overzaki.retries'), 200, throw: false)
            ->acceptJson()
            ->asJson();
    }

    protected function url(string $path): string
    {
        return rtrim(config('overzaki.base_url'), '/').'/'.ltrim($path, '/');
    }

    /**
     * Unwrap the API envelope. A failed call logs and degrades to an empty
     * array so one slow upstream section cannot take a whole page down.
     */
    protected function unwrap(Response $response, string $method, string $path): array
    {
        if ($response->failed()) {
            Log::warning('Overzaki request failed', [
                'method' => $method,
                'path' => $path,
                'status' => $response->status(),
                'body' => mb_substr((string) $response->body(), 0, 400),
            ]);

            return [];
        }

        $json = $response->json();

        if (! is_array($json)) {
            return [];
        }

        $data = array_key_exists('data', $json) ? $json['data'] : $json;

        return is_array($data) ? $data : [];
    }

    /** Pull a human-readable message out of a NestJS i18n validation error. */
    protected function messageFrom(array $json): ?string
    {
        $message = $json['message'] ?? null;

        if (is_string($message)) {
            return $message;
        }

        if (is_array($message)) {
            $flat = [];

            array_walk_recursive($message, function ($value) use (&$flat) {
                if (is_string($value)) {
                    $flat[] = $value;
                }
            });

            return $flat === [] ? null : implode(' — ', array_slice($flat, 0, 3));
        }

        return null;
    }
}
