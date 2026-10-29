<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class KulisyVerifyEmail extends Notification
{
    public function __construct(private readonly string $url) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Подтвердите почту для кулис kudab')
            ->line('Чтобы войти за кулисы kudab, подтвердите почту. Ссылка работает сутки.')
            ->action('Подтвердить почту', $this->url)
            ->line('Если вы не регистрировались, ничего не делайте.');
    }
}
