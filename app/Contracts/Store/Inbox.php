<?php

namespace App\Contracts\Store;

/**
 * What shoppers send the shop: contact messages and newsletter sign-ups.
 */
interface Inbox
{
    /**
     * @param  array{name:string,email:string,phone:?string,message:string}  $message
     * @return array{ok:bool,message:?string}
     */
    public function sendContact(array $message): array;

    /** @return array{ok:bool,message:?string} */
    public function subscribe(string $email): array;
}
