<?php

namespace App\Services\Overzaki;

use App\Contracts\Store\Inbox;

/**
 * Contact messages and newsletter sign-ups, forwarded to Overzaki.
 */
class OverzakiInbox implements Inbox
{
    public function __construct(protected OverzakiClient $client) {}

    /**
     * @param  array{name:string,email:string,phone:?string,message:string}  $message
     * @return array{ok:bool,message:?string}
     */
    public function sendContact(array $message): array
    {
        $response = $this->client->postRaw(config('overzaki.endpoints.contactUs'), [
            'name' => $message['name'],
            'email' => $message['email'],
            'phoneNumber' => $message['phone'] ?? null,
            'message' => $message['message'],
        ]);

        return ['ok' => $response['ok'], 'message' => $response['message']];
    }

    /** @return array{ok:bool,message:?string} */
    public function subscribe(string $email): array
    {
        $response = $this->client->postRaw(config('overzaki.endpoints.newsletter'), ['email' => $email]);

        return ['ok' => $response['ok'], 'message' => $response['message']];
    }
}
