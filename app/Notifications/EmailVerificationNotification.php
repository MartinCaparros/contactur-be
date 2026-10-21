<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class EmailVerificationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $token,
        public readonly bool $emailChange = false,
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
            .'/?auth=verify-email#token='.rawurlencode($this->token);

        return (new MailMessage)
            ->subject($this->emailChange ? __('Verificá tu nuevo email') : __('Verificá tu email'))
            ->greeting(__('Hola'))
            ->line($this->emailChange
                ? __('Confirmá esta dirección antes de cambiar el email de tu cuenta.')
                : __('Confirmá tu dirección de email para continuar en Contactur.'))
            ->action(__('Verificar email'), $url)
            ->line(__('Si no solicitaste esta acción, ignorá este mensaje.'))
            ->salutation(__('Saludos, el equipo de Contactur'));
    }
}
