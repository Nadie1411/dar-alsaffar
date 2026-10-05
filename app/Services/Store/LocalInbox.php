<?php

namespace App\Services\Store;

use App\Contracts\Store\Inbox;
use App\Models\ContactMessage;
use App\Models\NewsletterSubscriber;
use Illuminate\Support\Str;

/**
 * What shoppers send the shop, kept in this application's own database for
 * the admin panel to read.
 */
class LocalInbox implements Inbox
{
    /**
     * @param  array{name:string,email:string,phone:?string,message:string}  $message
     * @return array{ok:bool,message:?string}
     */
    public function sendContact(array $message): array
    {
        ContactMessage::query()->create([
            'name' => trim($message['name']),
            'email' => Str::lower(trim($message['email'])),
            'phone' => $message['phone'] ?? null,
            'message' => trim($message['message']),
        ]);

        return ['ok' => true, 'message' => null];
    }

    /** @return array{ok:bool,message:?string} */
    public function subscribe(string $email): array
    {
        $subscriber = NewsletterSubscriber::query()->firstOrCreate(
            ['email' => Str::lower(trim($email))],
            ['locale' => app()->getLocale() === 'en' ? 'en' : 'ar'],
        );

        // Someone who unsubscribed and now signs up again has asked to be back.
        if ($subscriber->unsubscribed_at !== null) {
            $subscriber->update(['unsubscribed_at' => null]);
        }

        return ['ok' => true, 'message' => null];
    }
}
