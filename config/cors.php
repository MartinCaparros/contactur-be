<?php

/*
 * CORS de la API (T22). Sin este archivo regía el default del framework
 * (`allowed_origins => ['*']`) y `CORS_ALLOWED_ORIGINS` de compose no se leía.
 *
 * El navegador no habla con Laravel: el BFF de Next llama del lado del servidor
 * (sin CORS) y los tokens viven en cookies HttpOnly del dominio del frontend.
 * Por eso solo se permiten los orígenes declarados y nunca credenciales: un sitio
 * ajeno no obtiene ningún permiso de lectura cross-origin sobre la API.
 */
$origins = array_values(array_filter(array_map(
    static fn (string $origin): string => rtrim(trim($origin), '/'),
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', (string) env('FRONTEND_URL', 'http://localhost:3000'))),
), static fn (string $origin): bool => $origin !== '' && $origin !== '*'));

return [
    'paths' => ['api/*'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    'allowed_origins' => $origins,
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'Idempotency-Key', 'X-Request-ID'],
    'exposed_headers' => ['X-Request-ID', 'Retry-After', 'Idempotency-Replayed'],
    'max_age' => 600,
    'supports_credentials' => false,
];
