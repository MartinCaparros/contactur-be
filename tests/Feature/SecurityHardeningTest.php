<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * T22 — hallazgos reproducidos en la auditoría y corregidos:
 * - alta de cuentas sin límite;
 * - CORS abierto (`*`) por falta de config/cors.php;
 * - X-Forwarded-For aceptado desde la red de Docker, que incluía al host.
 */
final class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    public function test_registration_is_rate_limited_per_ip_and_per_email_with_the_error_envelope(): void
    {
        config([
            'contactur.rate_limits.register_per_minute' => 3,
            'contactur.rate_limits.register_per_email_hour' => 2,
        ]);
        $ip = '10.22.'.random_int(0, 255).'.'.random_int(1, 254);
        $this->withServerVariables(['REMOTE_ADDR' => $ip]);

        foreach (range(1, 3) as $i) {
            $this->register('t22-alta-'.Str::lower((string) Str::ulid()).'@example.test')->assertCreated();
        }
        $this->register('t22-alta-extra@example.test')
            ->assertTooManyRequests()
            ->assertJsonPath('error.code', 'rate_limited')
            ->assertJsonStructure(['error' => ['code', 'message'], 'request_id'])
            ->assertHeader('Retry-After');
        $this->assertSame(0, User::query()->where('email', 't22-alta-extra@example.test')->count());

        // Por email: aunque cambie la IP, la misma dirección no se reintenta sin fin.
        $email = 't22-misma-direccion@example.test';
        foreach (['10.23.0.1', '10.23.0.2'] as $other) {
            $this->withServerVariables(['REMOTE_ADDR' => $other]);
            $this->register($email); // 201 la primera, 409 email_conflict la segunda
        }
        $this->withServerVariables(['REMOTE_ADDR' => '10.23.0.3']);
        $this->register($email)->assertTooManyRequests();

        RateLimiter::clear(md5('auth.register'.'register-ip:'.$ip));
    }

    public function test_cors_only_allows_the_configured_frontend_origin_and_never_credentials(): void
    {
        $allowed = rtrim((string) config('cors.allowed_origins.0'), '/');
        $this->assertNotSame('', $allowed);
        $this->assertNotContains('*', config('cors.allowed_origins'));
        $this->assertFalse(config('cors.supports_credentials'));

        // Con un único origen permitido, el middleware responde siempre ese origen: el
        // navegador lo compara con el suyo y bloquea a evil.example. Lo que nunca puede
        // salir es `*` ni el origen ajeno.
        $foreign = $this->call('OPTIONS', '/api/v1/me', [], [], [], [
            'HTTP_ORIGIN' => 'https://evil.example',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization',
        ]);
        $this->assertNotContains($foreign->headers->get('Access-Control-Allow-Origin'), ['*', 'https://evil.example']);

        $own = $this->call('OPTIONS', '/api/v1/me', [], [], [], [
            'HTTP_ORIGIN' => $allowed,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization',
        ]);
        $this->assertSame($allowed, $own->headers->get('Access-Control-Allow-Origin'));
        $this->assertNull($own->headers->get('Access-Control-Allow-Credentials'));

        $simple = $this->withHeader('Origin', 'https://evil.example')->getJson('/api/v1/health')->assertOk();
        $this->assertNotContains($simple->headers->get('Access-Control-Allow-Origin'), ['*', 'https://evil.example']);
    }

    public function test_without_trusted_proxies_a_spoofed_forwarded_for_does_not_change_the_client_ip(): void
    {
        $this->assertNull(config('trustedproxy.proxies'), 'el default no confía en ningún proxy');
        config(['contactur.rate_limits.support_inquiries_per_hour' => 2]);
        // El host llega a nginx desde el gateway de Docker: ya no es un proxy de confianza.
        $this->withServerVariables(['REMOTE_ADDR' => '172.24.0.1']);

        foreach (range(1, 2) as $i) {
            $this->withHeader('X-Forwarded-For', '198.51.100.'.$i)->postJson('/api/v1/support-inquiries', $this->inquiry($i))->assertStatus(202);
        }
        $this->withHeader('X-Forwarded-For', '198.51.100.3')
            ->postJson('/api/v1/support-inquiries', $this->inquiry(3))
            ->assertTooManyRequests();

        RateLimiter::clear(md5('support.inquiries'.'support-inquiries-hour:172.24.0.1'));
        RateLimiter::clear(md5('support.inquiries'.'support-inquiries-day:172.24.0.1'));
    }

    /** @return TestResponse<Response> */
    private function register(string $email): TestResponse
    {
        return $this->postJson('/api/v1/auth/register', [
            'name' => 'Alta T22',
            'email' => $email,
            'password' => 'Clave-segura-2026',
            'password_confirmation' => 'Clave-segura-2026',
        ]);
    }

    /** @return array<string, string> */
    private function inquiry(int $i): array
    {
        return [
            'name' => 'Visitante T22',
            'email' => 't22-visitante-'.$i.'@example.test',
            'message' => 'Consulta de prueba del límite por IP número '.$i,
            'website' => '',
        ];
    }
}
