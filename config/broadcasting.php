<?php

/*
 * Broadcasting de invalidaciones en tiempo real (R10, D20).
 *
 * Reverb solo transporta avisos con IDs públicos y versiones; el dato fuente
 * siempre se vuelve a pedir por la API autorizada. La autorización de canales
 * privados no usa la ruta /broadcasting/auth de Laravel sino
 * POST /api/v1/broadcasting/auth (contrato authorizePrivateBroadcastChannel).
 *
 * En tests BROADCAST_CONNECTION=null (phpunit.xml); la conexión reverb se
 * resuelve igual por nombre para firmar suscripciones.
 */

return [

    'default' => env('BROADCAST_CONNECTION', 'null'),

    'connections' => [

        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            'options' => [
                'host' => env('REVERB_HOST'),
                'port' => env('REVERB_PORT', 443),
                'scheme' => env('REVERB_SCHEME', 'https'),
                'useTLS' => env('REVERB_SCHEME', 'https') === 'https',
            ],
            'client_options' => [
                // El publish HTTP hacia Reverb es interno; un timeout corto evita que
                // un Reverb caído retenga al worker de la cola realtime.
                'timeout' => (float) env('REVERB_CLIENT_TIMEOUT', 5),
                'connect_timeout' => (float) env('REVERB_CLIENT_CONNECT_TIMEOUT', 2),
            ],
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];
