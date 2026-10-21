<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use LogicException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Variables que el entorno del contenedor (compose.yml) define con valores
     * reales y que la suite debe pisar. PHPUnit no reemplaza $_SERVER aunque el
     * <env> tenga force, y Laravel lee $_SERVER primero; por eso se fijan acá.
     * Sin esto, los eventos de tiempo real (T16) de los tests se encolarían en el
     * Redis real y el worker los publicaría en el Reverb real.
     *
     * @var array<string, string>
     */
    private const ISOLATED_ENV = [
        'DB_URL' => '',
        'DB_DATABASE' => 'contactur_test',
        'QUEUE_CONNECTION' => 'sync',
        'BROADCAST_CONNECTION' => 'null',
        // Sin proxies de confianza por defecto: cada test que lo necesite lo configura.
        'TRUSTED_PROXIES' => '',
        // Las pruebas registran muchas cuentas desde 127.0.0.1 y el limiter vive en
        // Redis: el límite del alta se fija alto acá y se prueba en su propio test.
        'AUTH_REGISTER_RATE_LIMIT' => '100000',
        'AUTH_REGISTER_EMAIL_RATE_LIMIT' => '100000',
        // T19: los correos de la suite nunca llegan al Mailpit real.
        'MAIL_MAILER' => 'array',
    ];

    protected function setUp(): void
    {
        $database = $_ENV['DB_DATABASE'] ?? getenv('DB_DATABASE');

        if ($database !== 'contactur_test') {
            throw new LogicException('The test suite may only run against contactur_test.');
        }

        foreach (self::ISOLATED_ENV as $name => $value) {
            putenv($name.'='.$value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }

        parent::setUp();

        // Symfony arma las requests de prueba con `Accept-Language: en-us` por defecto;
        // el BFF siempre manda el idioma de la interfaz, que por defecto es español. Los
        // tests de inglés lo piden explícitamente (`withHeader('Accept-Language', 'en')`).
        $this->withHeader('Accept-Language', 'es');
    }
}
