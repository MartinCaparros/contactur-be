<?php

namespace Tests\Feature;

use App\Jobs\SendSupportInquiryEmail;
use App\Mail\SupportInquiryMail;
use App\Models\SupportInquiry;
use App\Models\User;
use App\Services\SupportInquiryService;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Mockery;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * T19 lote A — contacto público (R23, P28 backend): valida, aplica honeypot y
 * rate limit, persiste y encola el correo al soporte configurado; no revela si
 * el email tiene cuenta y nunca responde 202 si persistir o encolar falló.
 *
 * Cada prueba usa una IP propia: el limitador vive en Redis (CACHE_STORE) y
 * no debe mezclarse entre pruebas ni corridas.
 */
final class SupportInquiryTest extends TestCase
{
    use RefreshDatabase;

    private string $ip;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Mail::fake();
        config(['contactur.support.email' => 'soporte-pruebas@contactur.test']);
        $this->ip = '10.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254);
        $this->withServerVariables(['REMOTE_ADDR' => $this->ip]);
    }

    public function test_r23_a_valid_inquiry_is_persisted_queued_and_emailed_to_support_with_reply_to(): void
    {
        Queue::fake();

        $response = $this->submit()->assertStatus(202);
        $this->assertSame(['data'], array_keys((array) $response->json()));
        $this->assertSame(['id', 'accepted'], array_keys((array) $response->json('data')));
        $this->assertTrue($response->json('data.accepted'));
        $id = (string) $response->json('data.id');
        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $id);

        $inquiry = SupportInquiry::query()->where('public_id', $id)->sole();
        $this->assertSame('Paula Ficticia', $inquiry->name);
        $this->assertSame('paula@example.test', $inquiry->email);
        $this->assertSame('acceso', $inquiry->category);
        $this->assertSame(SupportInquiry::STATUS_PENDING, $inquiry->status);
        $this->assertNull($inquiry->emailed_at);
        // Nunca la IP en claro: HMAC con la APP_KEY.
        $this->assertNotSame($this->ip, $inquiry->ip_hash);
        $this->assertStringNotContainsString($this->ip, $inquiry->ip_hash);
        $this->assertSame(hash_hmac('sha256', $this->ip, (string) config('app.key')), $inquiry->ip_hash);

        Queue::assertPushed(SendSupportInquiryEmail::class, 1);
        Queue::assertPushed(SendSupportInquiryEmail::class, fn (SendSupportInquiryEmail $job): bool => $job->inquiryId === $inquiry->id && $job->queue === 'default');

        // El worker entrega una sola vez aunque el job se repita.
        $service = app(SupportInquiryService::class);
        (new SendSupportInquiryEmail($inquiry->id))->handle($service);
        (new SendSupportInquiryEmail($inquiry->id))->handle($service);
        Mail::assertSent(SupportInquiryMail::class, 1);
        Mail::assertSent(SupportInquiryMail::class, function (SupportInquiryMail $mail) use ($id): bool {
            $html = $mail->render();
            foreach (['Paula Ficticia', 'paula@example.test', 'acceso', 'Quisiera conocer el proceso para solicitar acceso.', $id] as $expected) {
                $this->assertStringContainsString($expected, $html);
            }

            return $mail->hasTo('soporte-pruebas@contactur.test')
                && $mail->hasReplyTo('paula@example.test', 'Paula Ficticia');
        });
        $this->assertNotNull($inquiry->refresh()->emailed_at);
    }

    public function test_r23_with_the_real_sync_queue_the_support_email_goes_out_once_and_html_is_escaped(): void
    {
        $this->submit(['message' => '<script>alert(1)</script> necesito ayuda con mi cuenta', 'category' => null])->assertStatus(202);

        Mail::assertSent(SupportInquiryMail::class, function (SupportInquiryMail $mail): bool {
            $html = $mail->render();
            $this->assertStringNotContainsString('<script>', $html);
            $this->assertStringContainsString('&lt;script&gt;', $html);
            $this->assertStringContainsString('Sin categoría', $html);

            return true;
        });
        $this->assertNotNull(SupportInquiry::query()->sole()->emailed_at);
    }

    public function test_r23_a_filled_honeypot_is_422_and_nothing_is_stored_or_queued(): void
    {
        Queue::fake();

        $this->submit(['website' => 'https://spam.example.test'])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['fields' => ['website']], 'request_id']);
        $this->submit(['website' => ['x']])->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['website']]]);
        $this->assertSame(0, SupportInquiry::query()->count());
        Queue::assertNothingPushed();

        // Vacío (lo que envía un navegador real) sí pasa.
        $this->submit(['website' => ''])->assertStatus(202);
        $this->assertSame(1, SupportInquiry::query()->count());
    }

    public function test_r23_each_field_is_validated_and_nothing_partial_is_stored(): void
    {
        Queue::fake();
        // El limitador también cuenta los intentos inválidos (antiabuso); acá no es el objeto.
        config(['contactur.rate_limits.support_inquiries_per_hour' => 100]);
        $cases = [
            'name' => ['name' => 'P'],
            'email' => ['email' => 'no-es-un-email'],
            'message' => ['message' => 'Corto'],
            'category' => ['category' => str_repeat('c', 81)],
            'phone' => ['phone' => '+54 11 5555-0000'],
        ];
        foreach ($cases as $field => $override) {
            $this->submit($override)->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => [$field]]]);
        }
        $this->submit(['name' => str_repeat('n', 121)])->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['name']]]);
        $this->submit(['email' => str_repeat('a', 245).'@example.test'])->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['email']]]);
        $this->submit(['message' => str_repeat('m', 5001)])->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['message']]]);
        $this->postJson('/api/v1/support-inquiries', [])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['fields' => ['name', 'email', 'message']]]);

        $this->assertSame(0, SupportInquiry::query()->count());
        Queue::assertNothingPushed();

        // Bordes válidos: 2/120, 10/5000 y categoría de 80.
        $this->submit(['name' => 'Al', 'message' => str_repeat('m', 10), 'category' => str_repeat('c', 80)])->assertStatus(202);
        $this->submit(['name' => str_repeat('n', 120), 'message' => str_repeat('m', 5000)])->assertStatus(202);
    }

    public function test_r23_the_response_does_not_reveal_whether_the_email_has_an_account(): void
    {
        Queue::fake();
        User::factory()->create(['email' => 'cuenta-existente@example.test']);

        $existing = $this->submit(['email' => 'cuenta-existente@example.test'])->assertStatus(202);
        $unknown = $this->submit(['email' => 'sin-cuenta@example.test'])->assertStatus(202);

        $strip = static fn (TestResponse $response): array => array_replace_recursive((array) $response->json(), ['data' => ['id' => 'X']]);
        $this->assertSame($strip($existing), $strip($unknown));
        $this->assertSame(
            array_diff(array_keys($existing->headers->all()), ['x-request-id', 'date']),
            array_diff(array_keys($unknown->headers->all()), ['x-request-id', 'date']),
        );
        $this->assertSame(2, SupportInquiry::query()->count());
        Queue::assertPushed(SendSupportInquiryEmail::class, 2);
    }

    public function test_r23_the_ip_rate_limit_answers_429_with_the_project_envelope(): void
    {
        Queue::fake();
        config(['contactur.rate_limits.support_inquiries_per_hour' => 2]);

        $this->submit()->assertStatus(202);
        $this->submit()->assertStatus(202);
        $this->submit()
            ->assertTooManyRequests()
            ->assertJsonPath('error.code', 'rate_limited')
            ->assertJsonStructure(['error' => ['code', 'message'], 'request_id'])
            ->assertHeader('Retry-After');
        $this->assertSame(2, SupportInquiry::query()->count());

        // Otra IP no comparte la ventana.
        $this->withServerVariables(['REMOTE_ADDR' => '10.255.'.random_int(0, 255).'.'.random_int(1, 254)]);
        $this->submit()->assertStatus(202);
    }

    public function test_r23_behind_a_trusted_bff_the_limit_is_per_client_ip_and_untrusted_forwarding_is_ignored(): void
    {
        Queue::fake();
        config([
            'contactur.rate_limits.support_inquiries_per_hour' => 1,
            'trustedproxy.proxies' => '172.16.0.0/12',
        ]);
        $bff = '172.24.0.'.random_int(2, 254);
        // Rangos amplios: la ventana vive en Redis y sobrevive entre corridas; con solo
        // 254 IPs posibles una corrida repetida podía caer en una IP ya limitada.
        $clientA = '10.201.'.random_int(0, 255).'.'.random_int(1, 254);
        $clientB = '10.202.'.random_int(0, 255).'.'.random_int(1, 254);

        // Dos navegadores distintos detrás del mismo BFF no comparten la ventana.
        $this->withServerVariables(['REMOTE_ADDR' => $bff]);
        $this->withHeader('X-Forwarded-For', $clientA)->submit()->assertStatus(202);
        $this->withHeader('X-Forwarded-For', $clientA)->submit()->assertTooManyRequests();
        $this->withHeader('X-Forwarded-For', $clientB)->submit()->assertStatus(202);

        // Desde una IP que no es proxy de confianza, el header no elige la IP.
        $direct = '10.200.'.random_int(0, 255).'.'.random_int(1, 254);
        $this->withServerVariables(['REMOTE_ADDR' => $direct]);
        $this->withHeader('X-Forwarded-For', '10.203.'.random_int(0, 255).'.'.random_int(1, 254))->submit()->assertStatus(202);
        $this->withHeader('X-Forwarded-For', '10.203.'.random_int(0, 255).'.'.random_int(1, 254))->submit()->assertTooManyRequests();

        $hashes = SupportInquiry::query()->pluck('ip_hash')->all();
        $this->assertCount(3, $hashes);
        $this->assertContains(hash_hmac('sha256', $clientA, (string) config('app.key')), $hashes);
        $this->assertContains(hash_hmac('sha256', $direct, (string) config('app.key')), $hashes);
    }

    public function test_r23_a_queue_failure_is_never_a_202_and_leaves_no_pending_inquiry(): void
    {
        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->with(Mockery::type(SendSupportInquiryEmail::class))
            ->andThrow(new RuntimeException('Redis no disponible'));
        $this->app->instance(Dispatcher::class, $dispatcher);
        Exceptions::fake();

        $this->submit()
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'service_unavailable')
            ->assertJsonStructure(['error' => ['code', 'message'], 'request_id'])
            ->assertJsonMissingPath('data');

        $this->assertSame(0, SupportInquiry::query()->count());
        $this->assertSame(0, SupportInquiry::query()->where('status', SupportInquiry::STATUS_PENDING)->count());
        Mail::assertNothingSent();
        // La causa real queda reportada para operación, no expuesta al cliente.
        Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'Redis no disponible');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return TestResponse<Response>
     */
    private function submit(array $overrides = []): TestResponse
    {
        return $this->postJson('/api/v1/support-inquiries', array_merge([
            'name' => 'Paula Ficticia',
            'email' => 'paula@example.test',
            'category' => 'acceso',
            'message' => 'Quisiera conocer el proceso para solicitar acceso.',
            'website' => '',
        ], $overrides));
    }
}
