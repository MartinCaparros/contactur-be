<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Correo transaccional de una notificación (R18). No implementa ShouldQueue: lo
 * envía SendNotificationEmail de forma síncrona con la fila del outbox bloqueada.
 * Nunca recibe datos de contacto ni documentos; las líneas ya vienen armadas por
 * NotificationService y la vista las escapa (sin Markdown, para que texto libre
 * como la descripción del servicio no pueda inyectar enlaces). Las líneas llegan
 * armadas en el idioma del destinatario y se envía con `->locale()` de ese idioma.
 */
final class ProfileNotificationMail extends Mailable
{
    /** @param  list<string>  $lines */
    public function __construct(
        public readonly string $subjectLine,
        public readonly string $heading,
        public readonly array $lines,
        public readonly ?string $actionLabel,
        public readonly ?string $actionUrl,
        public readonly ?string $footer = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.profile-notification',
            text: 'mail.profile-notification-text',
        );
    }
}
