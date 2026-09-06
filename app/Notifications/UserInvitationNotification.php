<?php

namespace App\Notifications;

use App\Support\UserInvitations;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class UserInvitationNotification extends Notification
{
    use Queueable;

    public function __construct(private string $url) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Пристап до порталот на FinanceBuddy')
            ->greeting('Здраво '.$notifiable->name.',')
            ->line('Канцеларијата ви отвори пристап до порталот.')
            ->action('Постави лозинка', $this->url)
            ->line('Линкот важи '.UserInvitations::DAYS_VALID.' дена и може да се употреби само еднаш.')
            ->salutation('Поздрав, FinanceBuddy');
    }
}
