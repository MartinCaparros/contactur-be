<?php

namespace App\Enums;

/**
 * Tipos de notificación (R18). Mismos valores que `NotificationPreferenceInput`
 * del contrato y que AccountController acepta en PATCH /me.
 *
 * Política de correo:
 * - `welcome`: solo in-app. El correo de verificación ya sale al registrarse;
 *   un segundo correo de bienvenida no agrega información.
 * - `email_verified`: solo in-app acá. El correo ya lo envía AuthService
 *   (EmailVerifiedNotification) y es esencial: confirma un cambio de seguridad
 *   de la cuenta, así que ignora el opt-out.
 * - `administrative_change`: correo esencial. Una suspensión, un cambio de acceso
 *   o una revisión documental cambian lo que la cuenta puede hacer; ocultarlos por
 *   preferencia dejaría al titular sin explicación de un bloqueo.
 * - `contact_request_*`: correo opcional según `users.notification_preferences`
 *   (una entrada ausente = habilitado).
 */
enum NotificationType: string
{
    case Welcome = 'welcome';
    case EmailVerified = 'email_verified';
    case ContactRequestReceived = 'contact_request_received';
    case ContactRequestAccepted = 'contact_request_accepted';
    case ContactRequestRejected = 'contact_request_rejected';
    case ContactRequestExpired = 'contact_request_expired';
    case AdministrativeChange = 'administrative_change';

    /** Este outbox envía correo para el tipo (los demás tienen otro canal o ninguno). */
    public function sendsEmail(): bool
    {
        return match ($this) {
            self::Welcome, self::EmailVerified => false,
            default => true,
        };
    }

    /** Esencial: el correo ignora el opt-out de preferencias. */
    public function isEssential(): bool
    {
        return match ($this) {
            self::EmailVerified, self::AdministrativeChange => true,
            default => false,
        };
    }
}
