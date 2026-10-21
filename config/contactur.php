<?php

return [
    'frontend_url' => rtrim((string) env('FRONTEND_URL', 'http://localhost:3000'), '/'),

    'jwt' => [
        'issuer' => (string) env('JWT_ISSUER', env('APP_URL', 'http://localhost:8080')),
        'audience' => (string) env('JWT_AUDIENCE', 'contactur-api'),
    ],

    'auth' => [
        'refresh_ttl_minutes' => (int) env('JWT_REFRESH_TTL', 20160),
        'email_verification_ttl_minutes' => (int) env('EMAIL_VERIFICATION_TTL', 1440),
        'password_reset_ttl_minutes' => (int) env('PASSWORD_RESET_TTL', 60),
        'email_change_ttl_minutes' => (int) env('EMAIL_CHANGE_TTL', 60),
    ],

    'rate_limits' => [
        'login_per_minute' => (int) env('AUTH_LOGIN_RATE_LIMIT', 5),
        // T22: alta de cuentas por IP (por minuto) y por email (por hora).
        'register_per_minute' => (int) env('AUTH_REGISTER_RATE_LIMIT', 10),
        'register_per_email_hour' => (int) env('AUTH_REGISTER_EMAIL_RATE_LIMIT', 5),
        'recovery_per_minute' => (int) env('AUTH_RECOVERY_RATE_LIMIT', 3),
        'resend_per_minute' => (int) env('AUTH_RESEND_RATE_LIMIT', 3),
        // Cada reconexión del websocket re-autoriza sus canales (perfil + directorio).
        'broadcast_auth_per_minute' => (int) env('BROADCAST_AUTH_RATE_LIMIT', 60),
        // T19/R23: contacto público por IP (ventana horaria y diaria).
        'support_inquiries_per_hour' => (int) env('SUPPORT_INQUIRY_RATE_LIMIT_HOURLY', 5),
        'support_inquiries_per_day' => (int) env('SUPPORT_INQUIRY_RATE_LIMIT_DAILY', 20),
    ],
    'support' => [
        // Destino del correo del formulario público (R23); Mailpit en local.
        'email' => (string) env('SUPPORT_EMAIL', 'soporte@contactur.test'),
    ],
    'notifications' => [
        // El contrato fija per_page 1..50 (default 20).
        'max_per_page' => 50,
        'default_per_page' => 20,
        // Barrido del outbox: reencola correos `pending` con más de N minutos.
        'redispatch_after_minutes' => max(1, (int) env('NOTIFICATION_EMAIL_REDISPATCH_MINUTES', 10)),
    ],

    'uploads' => [
        'documents_max_bytes' => (int) env('DOCUMENT_UPLOAD_MAX_BYTES', 10 * 1024 * 1024),
        'media_max_bytes' => (int) env('MEDIA_UPLOAD_MAX_BYTES', 5 * 1024 * 1024),
        'media_max_width' => (int) env('MEDIA_UPLOAD_MAX_WIDTH', 6000),
        'media_max_height' => (int) env('MEDIA_UPLOAD_MAX_HEIGHT', 6000),
        'gallery_max_items' => (int) env('GALLERY_MAX_ITEMS', 20),
    ],

    'availability' => [
        // El contrato fija maxItems 366; el valor configurable solo puede reducirlo.
        'max_dates' => min(366, max(1, (int) env('AVAILABILITY_MAX_DATES', 366))),
    ],

    'search' => [
        // El contrato fija per_page 1..50 (default 20) y un rango máximo de 90 días;
        // los valores configurables solo pueden reducirlos.
        'max_per_page' => min(50, max(1, (int) env('SEARCH_MAX_PER_PAGE', 50))),
        'default_per_page' => min(
            min(50, max(1, (int) env('SEARCH_MAX_PER_PAGE', 50))),
            max(1, (int) env('SEARCH_DEFAULT_PER_PAGE', 20)),
        ),
        'max_range_days' => min(90, max(1, (int) env('SEARCH_MAX_RANGE_DAYS', 90))),
    ],

    'contact_requests' => [
        // D13: vencen a 7 días y el reenvío tras rechazo/expiración/cancelación
        // espera otros 7; ambos configurables (no son requisitos originales).
        'expiration_hours' => max(1, (int) env('CONTACT_REQUEST_EXPIRATION_HOURS', 168)),
        'resend_cooldown_hours' => max(0, (int) env('CONTACT_REQUEST_RESEND_COOLDOWN_HOURS', 168)),
        // El contrato fija per_page 1..50 (default 20).
        'max_per_page' => 50,
        'default_per_page' => 20,
    ],
    'references' => [
        // El contrato fija per_page 1..50 (default 20) para listados propios y admin.
        'max_per_page' => 50,
        'default_per_page' => 20,
        // Tope de `visible_references` en la ficha de directorio (más nuevas primero).
        'directory_limit' => min(50, max(1, (int) env('REFERENCES_DIRECTORY_LIMIT', 20))),
    ],
];
