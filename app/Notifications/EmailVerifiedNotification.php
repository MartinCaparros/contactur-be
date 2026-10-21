<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class EmailVerifiedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->afterCommit();
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Email verificado'))
            ->greeting(__('Email verificado'))
            ->line(__('Tu dirección quedó verificada correctamente en Contactur.'))
            ->action(__('Ir a Contactur'), (string) config('contactur.frontend_url'))
            ->salutation(__('Saludos, el equipo de Contactur'));
    }
}
