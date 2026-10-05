<?php

namespace App\Contracts\Store;

/**
 * Customer accounts: signing in and out, and the account's own data.
 */
interface Customers
{
    /** @return array{ok:bool,message:?string} */
    public function login(string $email, string $password): array;

    /**
     * @param  array{fullName:string,email:string,password:string,phoneNumber:string}  $attributes
     * @return array{ok:bool,message:?string,authenticated?:bool}
     */
    public function register(array $attributes): array;

    /** @return array{ok:bool,message:?string} */
    public function forgotPassword(string $email): array;

    public function logout(): void;

    /**
     * @param  array{fullName:string}  $validated
     * @return array{ok:bool,message:?string}
     */
    public function updateProfile(array $validated): array;

    /** @return array<int,array<string,mixed>> */
    public function addresses(): array;
}
