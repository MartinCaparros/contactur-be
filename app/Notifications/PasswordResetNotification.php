<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class PasswordResetNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $token,
        public readonly string $email,
    ) {
        $this->afterCommit();
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = (string) config('contactur.frontend_url')
            .'/?auth=reset-password#token='.rawurlencode($this->token)
            .'&email='.rawurlencode($this->email);

        return (new MailMessage)
            ->subject(__('Restablecé tu contraseña'))
            ->greeting(__('Hola'))
            ->line(__('Recibimos una solicitud para restablecer la contraseña de tu cuenta Contactur.'))
            ->action(__('Restablecer contraseña'), $url)
            ->line(__('Si no solicitaste esta acción, ignorá este mensaje.'))
            ->salutation(__('Saludos, el equipo de Contactur'));
    }
}
