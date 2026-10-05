<?php

namespace App\Services\Overzaki;

use App\Contracts\Store\Customers;
use Illuminate\Support\Facades\Session;

/**
 * Customer sessions.
 *
 * Accounts live in Overzaki, not in this application's database — there is no
 * local users table behind the storefront. Sign-in exchanges credentials for a
 * bearer token which is kept in the PHP session and replayed on every
 * customer-scoped API call (orders, addresses, wishlist).
 */
class AuthService implements Customers
{
    protected const TOKEN_KEY = 'overzaki.token';

    protected const REFRESH_KEY = 'overzaki.refresh';

    protected const CUSTOMER_KEY = 'overzaki.customer';

    public function __construct(protected OverzakiClient $client) {}

    // ------------------------------------------------------------- session

    public static function token(): ?string
    {
        return Session::get(self::TOKEN_KEY);
    }

    public static function check(): bool
    {
        return (bool) self::token();
    }

    public static function customer(): ?array
    {
        return Session::get(self::CUSTOMER_KEY);
    }

    public static function name(): ?string
    {
        $customer = self::customer();

        return $customer['fullName'] ?? $customer['name'] ?? null;
    }

    public static function id(): ?string
    {
        $customer = self::customer();

        return $customer['_id'] ?? $customer['id'] ?? null;
    }

    // -------------------------------------------------------------- actions

    /**
     * @return array{ok:bool,message:?string}
     */
    public function login(string $email, string $password): array
    {
        $response = $this->client->postRaw(config('overzaki.endpoints.signIn'), [
            'email' => $email,
            'password' => $password,
            'deviceName' => $this->deviceName(),
        ]);

        if (! $response['ok']) {
            return ['ok' => false, 'message' => $response['message'] ?? __('storefront.auth.failed')];
        }

        $this->store($response['data'] ?? []);

        return ['ok' => true, 'message' => null];
    }

    /**
     * @param  array{fullName:string,email:string,password:string,phoneNumber:string}  $attributes
     * @return array{ok:bool,message:?string}
     */
    public function register(array $attributes): array
    {
        $response = $this->client->postRaw(config('overzaki.endpoints.signUp'), [
            'fullName' => $attributes['fullName'],
            'email' => $attributes['email'],
            'password' => $attributes['password'],
            'phoneNumber' => $attributes['phoneNumber'],
            'country' => config('overzaki.country_id'),
            'deviceName' => $this->deviceName(),
        ]);

        if (! $response['ok']) {
            return ['ok' => false, 'message' => $response['message'] ?? __('storefront.auth.registerFailed')];
        }

        // Some tenants require OTP verification before a token is issued; when
        // one comes back we sign the shopper straight in, otherwise they are
        // sent to the sign-in form.
        $stored = $this->store($response['data'] ?? []);

        return ['ok' => true, 'message' => null, 'authenticated' => $stored];
    }

    public function forgotPassword(string $email): array
    {
        $response = $this->client->postRaw(config('overzaki.endpoints.forgotPassword'), [
            'email' => $email,
        ]);

        return [
            'ok' => $response['ok'],
            'message' => $response['message'],
        ];
    }

    public function logout(): void
    {
        Session::forget([self::TOKEN_KEY, self::REFRESH_KEY, self::CUSTOMER_KEY]);
        Session::forget('wishlist.ids');
    }

    /**
     * @param  array{fullName:string}  $validated
     * @return array{ok:bool,message:?string}
     */
    public function updateProfile(array $validated): array
    {
        $response = $this->client->withToken(self::token())
            ->postRaw('/customers/update_profile', $validated);

        if (! $response['ok']) {
            return ['ok' => false, 'message' => $response['message']];
        }

        Session::put(self::CUSTOMER_KEY, array_merge(self::customer() ?? [], $validated));

        return ['ok' => true, 'message' => null];
    }

    /** @return array<int,array<string,mixed>> */
    public function addresses(): array
    {
        $response = $this->client->withToken(self::token())
            ->get(config('overzaki.endpoints.myAddresses'));

        return $response['data'] ?? (is_array($response) ? $response : []);
    }

    /** Persist whatever token/customer shape the API returned. */
    protected function store(array $data): bool
    {
        $token = $data['accessToken']
            ?? $data['access_token']
            ?? $data['token']
            ?? ($data['tokens']['accessToken'] ?? null);

        if (! is_string($token) || $token === '') {
            return false;
        }

        Session::put(self::TOKEN_KEY, $token);

        if ($refresh = ($data['refreshToken'] ?? $data['refresh_token'] ?? null)) {
            Session::put(self::REFRESH_KEY, $refresh);
        }

        $customer = $data['customer'] ?? $data['user'] ?? $data['data'] ?? null;

        if (is_array($customer)) {
            Session::put(self::CUSTOMER_KEY, $customer);
        }

        Session::regenerate();

        return true;
    }

    protected function deviceName(): string
    {
        return 'web-'.substr(sha1(request()->userAgent().request()->ip()), 0, 12);
    }
}
