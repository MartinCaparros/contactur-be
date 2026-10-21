<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Str;

/**
 * Consulta pública reenviada al soporte configurado (R23). Reply-To es quien
 * consultó, así soporte responde directo sin exponer la casilla de soporte en el
 * formulario. Lo envía SendSupportInquiryEmail de forma síncrona.
 */
final class SupportInquiryMail extends Mailable
{
    public function __construct(
        public readonly string $inquiryId,
        public readonly string $inquirerName,
        public readonly string $inquirerEmail,
        public readonly ?string $inquiryCategory,
        public readonly string $inquiryMessage,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            replyTo: [new Address($this->inquirerEmail, $this->inquirerName)],
            // Texto del usuario en un header: sin saltos ni espacios de control.
            subject: 'Nueva consulta de soporte'.($this->inquiryCategory !== null ? ' — '.Str::squish($this->inquiryCategory) : ''),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.support-inquiry',
            text: 'mail.support-inquiry-text',
        );
    }
}
