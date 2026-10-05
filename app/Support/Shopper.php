<?php

namespace App\Support;

use App\Services\Overzaki\AuthService;
use Illuminate\Support\Facades\Auth;

/**
 * Who is shopping right now, whichever backend holds the accounts.
 *
 * Views and controllers ask this instead of reaching for a backend's own
 * session helpers, so switching the store over does not mean touching them.
 */
class Shopper
{
    public const GUARD = 'customer';

    public static function usesLocalAccounts(): bool
    {
        return config('store.backend') === 'local';
    }

    public static function check(): bool
    {
        return self::usesLocalAccounts()
            ? Auth::guard(self::GUARD)->check()
            : AuthService::check();
    }

    /**
     * The signed-in customer in the shape the views read: fullName, email,
     * phoneNumber (and name, for the account header).
     *
     * @return array<string,mixed>|null
     */
    public static function customer(): ?array
    {
        if (! self::usesLocalAccounts()) {
            return AuthService::customer();
        }

        $customer = Auth::guard(self::GUARD)->user();

        if ($customer === null) {
            return null;
        }

        return [
            'id' => (string) $customer->getKey(),
            'fullName' => $customer->name,
            'name' => $customer->name,
            'email' => $customer->email,
            'phoneNumber' => $customer->phone,
        ];
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
}
