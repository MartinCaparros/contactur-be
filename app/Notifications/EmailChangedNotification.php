<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class EmailChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $newEmail)
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
            ->subject(__('El email de tu cuenta cambió'))
            ->greeting(__('Aviso de seguridad'))
            ->line(__('El email de acceso de tu cuenta Contactur cambió a :email.', ['email' => $this->newEmail]))
            ->line(__('Si no realizaste este cambio, contactá a soporte.'))
            ->salutation(__('Saludos, el equipo de Contactur'));
    }
}
