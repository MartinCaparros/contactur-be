<?php

namespace Tests\Feature;

use App\Mail\ProfileNotificationMail;
use App\Mail\SupportInquiryMail;
use App\Notifications\EmailVerificationNotification;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * T27: los correos muestran el logo oficial a color (logo-01) servido por el frontend
 * público, con medidas fijas y `alt`, y el logo enlaza al sitio y no a la API.
 */
final class BrandEmailLogoTest extends TestCase
{
    private const FRONTEND = 'https://contactur.example';

    private const LOGO = '<img src="https://contactur.example/brand/contactur-logo-email.png" width="120" height="48" alt="Contactur"';

    protected function setUp(): void
    {
        parent::setUp();
        config(['contactur.frontend_url' => self::FRONTEND, 'app.name' => 'Contactur', 'app.url' => 'https://api.contactur.example']);
    }

    public function test_account_emails_show_the_logo_linked_to_the_public_site_and_keep_the_text_part_plain(): void
    {
        // Canal de correo real (cola sync, transporte `array`): mismo armado que el worker.
        Notification::swap(new ChannelManager($this->app));
        Notification::route('mail', 'brand-check@example.test')->notify(new EmailVerificationNotification('token-de-prueba'));

        $email = $this->onlyDeliveredEmail();
        $html = (string) $email->getHtmlBody();
        $text = (string) $email->getTextBody();

        // El tema inlinea su CSS en el `style` del enlace: se valida enlace + logo, no el estilo.
        $this->assertMatchesRegularExpression('~<td class="header"[^>]*>\s*<a href="'.preg_quote(self::FRONTEND, '~').'" style="[^"]*">\s*'.preg_quote(self::LOGO, '~').'~', $html);
        $this->assertStringNotContainsString('laravel.com/img', $html);
        $this->assertStringNotContainsString('href="https://api.contactur.example"', $html);
        $this->assertStringNotContainsString('contactur-logo-email.png', $text);
        $this->assertStringContainsString('Confirmá tu dirección de email para continuar en Contactur.', $text);
    }

    public function test_profile_notification_and_support_emails_show_the_logo_above_the_heading(): void
    {
        $notification = (new ProfileNotificationMail(
            subjectLine: 'Solicitud aceptada',
            heading: 'Aceptaron tu solicitud',
            lines: ['Una línea de prueba.'],
            actionLabel: 'Ver la solicitud',
            actionUrl: self::FRONTEND.'/app/requests',
        ))->render();
        $support = (new SupportInquiryMail(
            inquiryId: '01JTESTINQUIRY0000000000000',
            inquirerName: 'Persona Ficticia',
            inquirerEmail: 'persona@example.test',
            inquiryCategory: null,
            inquiryMessage: 'Consulta de prueba.',
        ))->render();

        foreach (['notificación de perfil' => [$notification, 'Aceptaron tu solicitud'], 'consulta de soporte' => [$support, 'Nueva consulta de soporte']] as $label => [$html, $heading]) {
            $logoAt = strpos($html, self::LOGO);
            $this->assertNotFalse($logoAt, "falta el logo en el correo de {$label}");
            $this->assertLessThan(strpos($html, '<h1'), $logoAt, "el logo va antes del título en el correo de {$label}");
            $this->assertStringContainsString($heading, $html);
        }
    }

    private function onlyDeliveredEmail(): Email
    {
        $transport = app('mail.manager')->mailer()->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);
        $messages = $transport->messages()->map(fn (SentMessage $sent) => $sent->getOriginalMessage())->values();
        $this->assertCount(1, $messages);
        $email = $messages->first();
        $this->assertInstanceOf(Email::class, $email);

        return $email;
    }
}
