<?php

namespace App\Services\Store;

use App\Contracts\Store\Customers;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Support\Shopper;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

/**
 * Customer accounts held in this application's own database.
 */
class LocalCustomers implements Customers
{
    protected function guard(): StatefulGuard
    {
        return Auth::guard(Shopper::GUARD);
    }

    /** Emails are matched case-insensitively, so they are only ever stored in lower case. */
    protected function normalise(string $email): string
    {
        return Str::lower(trim($email));
    }

    /** @return array{ok:bool,message:?string} */
    public function login(string $email, string $password): array
    {
        if (! $this->guard()->attempt(['email' => $this->normalise($email), 'password' => $password])) {
            return ['ok' => false, 'message' => __('storefront.auth.failed')];
        }

        // A new session id after signing in, so one fixed beforehand is useless.
        Session::regenerate();

        return ['ok' => true, 'message' => null];
    }

    /**
     * @param  array{fullName:string,email:string,password:string,phoneNumber:string}  $attributes
     * @return array{ok:bool,message:?string,authenticated?:bool}
     */
    public function register(array $attributes): array
    {
        $email = $this->normalise($attributes['email']);
        $failure = ['ok' => false, 'message' => __('storefront.auth.registerFailed')];

        if (Customer::query()->where('email', $email)->exists()) {
            return $failure;
        }

        try {
            $customer = Customer::query()->create([
                'name' => trim($attributes['fullName']),
                'email' => $email,
                'phone' => $attributes['phoneNumber'],
                'password' => $attributes['password'],
            ]);
        } catch (UniqueConstraintViolationException) {
            // Someone registered the same address between the check and the insert.
            return $failure;
        }

        $this->guard()->login($customer);
        Session::regenerate();

        return ['ok' => true, 'message' => null, 'authenticated' => true];
    }

    /** @return array{ok:bool,message:?string} */
    public function forgotPassword(string $email): array
    {
        Password::broker('customers')->sendResetLink(['email' => $this->normalise($email)]);

        // The same answer whether or not the address is registered, so the
        // form cannot be used to find out who has an account.
        return ['ok' => true, 'message' => null];
    }

    public function logout(): void
    {
        $this->guard()->logout();

        // A fresh session id, but the same session: the basket and anything a
        // guest saved should survive signing out.
        Session::regenerate();
        Session::forget('wishlist.ids');
    }

    /**
     * @param  array{fullName:string}  $validated
     * @return array{ok:bool,message:?string}
     */
    public function updateProfile(array $validated): array
    {
        /** @var Customer $customer */
        $customer = $this->guard()->user();
        $customer->update(['name' => trim($validated['fullName'])]);

        return ['ok' => true, 'message' => null];
    }

    /** @return array<int,array<string,mixed>> */
    public function addresses(): array
    {
        /** @var Customer|null $customer */
        $customer = $this->guard()->user();

        if ($customer === null) {
            return [];
        }

        return $customer->addresses()
            ->with(['city', 'area.city'])
            ->get()
            ->map(fn (CustomerAddress $address) => [
                'id' => (string) $address->id,
                'city' => ['name' => ($address->city ?? $address->area?->city)?->localizedMap('name')],
                'area' => ['name' => $address->area?->localizedMap('name')],
                'block' => $address->block,
                'street' => $address->street,
                'avenue' => $address->avenue,
                'building' => $address->building,
                'floor' => $address->floor,
                'apartment' => $address->apartment,
            ])
            ->all();
    }
}
