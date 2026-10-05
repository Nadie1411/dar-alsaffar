<?php

namespace App\Notifications;

use App\Support\Nav;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CustomerResetPassword extends Notification
{
    public function __construct(public string $token) {}

    /**
     * @return array<int,string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = Nav::url('reset-password/'.$this->token, ['email' => $notifiable->getEmailForPasswordReset()]);

        return (new MailMessage)
            ->subject(__('storefront.auth.mailSubject'))
            ->greeting(__('storefront.auth.greeting', ['name' => $notifiable->name]))
            ->line(__('storefront.auth.mailIntro'))
            ->action(__('storefront.auth.mailAction'), $url)
            ->line(__('storefront.auth.mailOutro'));
    }
}
