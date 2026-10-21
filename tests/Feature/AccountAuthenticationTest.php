<?php

namespace Tests\Feature;

use App\Models\AccountToken;
use App\Models\AuthSession;
use App\Models\User;
use App\Notifications\EmailChangedNotification;
use App\Notifications\EmailVerificationNotification;
use App\Notifications\PasswordResetNotification;
use App\Services\AccessTokenService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class AccountAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    public function test_user_factory_generates_internal_bigint_and_public_ulid(): void
    {
        $user = User::factory()->create();

        $this->assertIsInt($user->id);
        $this->assertGreaterThan(0, $user->id);
        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $user->public_id);
        $this->assertNotSame((string) $user->id, $user->public_id);
        $this->assertArrayNotHasKey('id', $user->toArray());
        $this->assertArrayNotHasKey('public_id', $user->toArray());
    }

    public function test_register_login_me_and_patch_are_positive_hashed_and_whitelisted(): void
    {
        $password = 'Clave-segura-2026';
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => '  Ana Torres  ',
            'email' => 'ANA.TORRES@EXAMPLE.TEST',
            'password' => $password,
            'password_confirmation' => $password,
        ])->assertCreated()->assertJsonPath('data.user.email', 'ana.torres@example.test');

        $session = $response->json('data');
        $user = User::query()->where('email', 'ana.torres@example.test')->firstOrFail();
        $storedSession = AuthSession::query()->firstOrFail();
        $storedAccountToken = AccountToken::query()->firstOrFail();
        $this->assertIsInt($user->id);
        $this->assertGreaterThan(0, $user->id);
        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $user->public_id);
        $this->assertSame($user->public_id, $session['user']['id']);
        $this->assertNotSame((string) $user->id, $session['user']['id']);
        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $storedSession->sid);
        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $storedAccountToken->id);
        $this->assertIsInt($storedSession->user_id);
        $this->assertIsInt($storedAccountToken->user_id);
        $this->assertSame($user->id, $storedSession->user_id);
        $this->assertSame($user->id, $storedAccountToken->user_id);
        $this->assertTrue(Hash::check($password, $user->password));
        $this->assertNotSame($password, $user->password);
        $this->assertSame(hash('sha256', $session['refresh_token']), $storedSession->refresh_hash);
        $this->assertNotSame($session['refresh_token'], $storedSession->refresh_hash);
        $payload = $this->app->make(AccessTokenService::class)->parseAndValidate($session['access_token']);
        $header = json_decode(
            base64_decode(strtr(explode('.', $session['access_token'])[0], '-_', '+/'), true) ?: '',
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $this->assertSame('HS256', $header['alg']);
        $this->assertSame(config('contactur.jwt.issuer'), $payload['iss']);
        $this->assertContains(config('contactur.jwt.audience'), (array) $payload['aud']);
        $this->assertSame((string) $user->id, $payload['sub']);
        $this->assertSame($storedSession->sid, $payload['sid']);
        $this->assertSame(
            ['id', 'name', 'email', 'email_verified_at', 'system_role', 'status', 'locale'],
            array_keys($session['user']),
        );
        // Con la interfaz en español (idioma por defecto) el alta queda en es-AR.
        $this->assertSame('es-AR', $session['user']['locale']);
        $this->assertSame(['user', 'access_token', 'refresh_token', 'token_type', 'expires_in'], array_keys($session));
        Notification::assertSentTo($user, EmailVerificationNotification::class, function (EmailVerificationNotification $notification): bool {
            return $notification instanceof ShouldQueue && strlen($notification->token) >= 20;
        });

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => 'Ana.Torres@example.test',
            'password' => $password,
        ])->assertOk()->json('data');

        $this->withToken($login['access_token'])
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'user' => $session['user'],
                    'profiles' => [],
                    'notification_preferences' => [],
                ],
            ]);

        $this->withToken($login['access_token'])
            ->patchJson('/api/v1/me', [
                'name' => 'Ana Torres Actualizada',
                'locale' => 'es-AR',
                'timezone' => 'America/Argentina/Buenos_Aires',
                'notification_preferences' => [[
                    'event_type' => 'contact_request_received',
                    'email_enabled' => true,
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.user.name', 'Ana Torres Actualizada')
            ->assertJsonPath('data.notification_preferences.0.email_enabled', true);

        $this->withHeader('X-Request-ID', 'validation-envelope')
            ->withToken($login['access_token'])
            ->patchJson('/api/v1/me', ['email' => 'forbidden@example.test'])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('request_id', 'validation-envelope');

        // Los mensajes por campo se muestran en la UI: deben viajar en español
        // con el rótulo del formulario, no con el texto por defecto del framework.
        $localized = $this->withToken($login['access_token'])
            ->patchJson('/api/v1/me', ['name' => 'A'])
            ->assertUnprocessable()
            ->json('error.fields.name.0');
        $this->assertSame('El campo nombre debe tener al menos 2 caracteres.', $localized);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Duplicada',
            'email' => 'ana.TORRES@example.test',
            'password' => $password,
            'password_confirmation' => $password,
        ])->assertConflict()->assertJsonPath('error.code', 'email_conflict');
    }

    public function test_email_verification_and_change_keep_old_email_until_token_is_consumed(): void
    {
        $registered = $this->registerAccount('original@example.test');
        $user = User::query()->where('email', 'original@example.test')->firstOrFail();
        $verificationToken = null;
        Notification::assertSentTo($user, EmailVerificationNotification::class, function (EmailVerificationNotification $notification) use (&$verificationToken): bool {
            $verificationToken = $notification->token;

            return ! $notification->emailChange;
        });

        $this->postJson('/api/v1/auth/email/verify', ['token' => $verificationToken])
            ->assertOk();
        $this->postJson('/api/v1/auth/email/verify', ['token' => $verificationToken])
            ->assertOk();
        $this->assertNotNull($user->refresh()->email_verified_at);

        $this->withToken($registered['access_token'])
            ->postJson('/api/v1/me/change-email', [
                'email' => 'NUEVA@example.test',
                'password' => 'Clave-segura-2026',
            ])->assertAccepted();

        $user->refresh();
        $this->assertSame('original@example.test', $user->email);
        $this->assertSame('nueva@example.test', $user->pending_email);
        $changeToken = null;
        Notification::assertSentOnDemand(EmailVerificationNotification::class, function (
            EmailVerificationNotification $notification,
            array $channels,
            AnonymousNotifiable $notifiable,
        ) use (&$changeToken): bool {
            if (! $notification->emailChange) {
                return false;
            }

            $changeToken = $notification->token;

            return $channels === ['mail']
                && $notifiable->routeNotificationFor('mail') === 'nueva@example.test';
        });

        $this->postJson('/api/v1/auth/email/verify', ['token' => $changeToken])
            ->assertOk();
        $user->refresh();
        $this->assertSame('nueva@example.test', $user->email);
        $this->assertNull($user->pending_email);
        $this->assertNotNull($user->email_verified_at);
        Notification::assertSentOnDemand(EmailChangedNotification::class, function (
            EmailChangedNotification $notification,
            array $channels,
            AnonymousNotifiable $notifiable,
        ): bool {
            return $notification->newEmail === 'nueva@example.test'
                && $channels === ['mail']
                && $notifiable->routeNotificationFor('mail') === 'original@example.test';
        });

        $this->postJson('/api/v1/auth/login', [
            'email' => 'nueva@example.test',
            'password' => 'Clave-segura-2026',
        ])->assertOk();
    }

    public function test_p04_logout_and_authenticated_password_change_invalidate_current_jwt(): void
    {
        $logoutSession = $this->registerAccount('logout@example.test');
        $this->withToken($logoutSession['access_token'])
            ->postJson('/api/v1/auth/logout')
            ->assertNoContent();
        $this->withToken($logoutSession['access_token'])
            ->getJson('/api/v1/me')
            ->assertUnauthorized();

        $passwordSession = $this->registerAccount('password@example.test');
        $this->withToken($passwordSession['access_token'])
            ->postJson('/api/v1/me/change-password', [
                'current_password' => 'Clave-segura-2026',
                'new_password' => 'Nueva-clave-2026',
                'new_password_confirmation' => 'Nueva-clave-2026',
            ])->assertOk();
        $this->withToken($passwordSession['access_token'])
            ->getJson('/api/v1/me')
            ->assertUnauthorized();
        $user = User::query()->where('email', 'password@example.test')->firstOrFail();
        $this->assertTrue(Hash::check('Nueva-clave-2026', $user->password));
        $this->assertSame(0, AuthSession::query()
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->count());
    }

    public function test_p04_password_reset_revokes_all_sessions_and_recovery_is_generic(): void
    {
        $email = mb_strtolower('recovery-'.Str::ulid().'@example.test');
        $session = $this->registerAccount($email);
        $unknownResponse = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'unknown-'.Str::ulid().'@example.test',
        ])->assertAccepted();
        $knownResponse = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => mb_strtoupper($email),
        ])->assertAccepted();
        $this->assertSame($unknownResponse->json(), $knownResponse->json());

        $user = User::query()->where('email', $email)->firstOrFail();
        $resetToken = null;
        Notification::assertSentTo($user, PasswordResetNotification::class, function (PasswordResetNotification $notification) use (&$resetToken): bool {
            $resetToken = $notification->token;

            return $notification instanceof ShouldQueue;
        });

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $resetToken,
            'email' => $email,
            'password' => 'Clave-nueva-2026',
            'password_confirmation' => 'Clave-nueva-2026',
        ])->assertOk();
        $this->withToken($session['access_token'])
            ->getJson('/api/v1/me')
            ->assertUnauthorized();
        $this->assertTrue(Hash::check('Clave-nueva-2026', $user->refresh()->password));
        $this->assertSame(0, AuthSession::query()
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->count());
        $this->assertNotNull(AccountToken::query()
            ->where('purpose', AccountToken::PURPOSE_PASSWORD_RESET)
            ->firstOrFail()
            ->consumed_at);
    }

    public function test_p04_login_recovery_and_resend_rate_limits_use_redis_and_error_envelope(): void
    {
        $this->assertSame('redis', config('cache.default'));
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        config()->set('contactur.rate_limits.login_per_minute', 2);
        config()->set('contactur.rate_limits.recovery_per_minute', 2);
        config()->set('contactur.rate_limits.resend_per_minute', 2);

        $loginEmail = 'limited-login-'.Str::ulid().'@example.test';
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => $loginEmail,
                'password' => 'incorrecta',
            ])->assertUnauthorized();
        }
        $this->postJson('/api/v1/auth/login', [
            'email' => $loginEmail,
            'password' => 'incorrecta',
        ])->assertTooManyRequests()->assertJsonPath('error.code', 'rate_limited');

        $recoveryEmail = 'limited-recovery-'.Str::ulid().'@example.test';
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->postJson('/api/v1/auth/forgot-password', [
                'email' => $recoveryEmail,
            ])->assertAccepted();
        }
        $this->postJson('/api/v1/auth/forgot-password', [
            'email' => $recoveryEmail,
        ])->assertTooManyRequests()->assertJsonStructure(['error' => ['code', 'message'], 'request_id']);

        $session = $this->registerAccount('limited-resend@example.test');
        $resendIp = '2001:db8:'.substr(hash('sha256', (string) Str::ulid()), 0, 4).'::1';
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => $resendIp])
                ->withToken($session['access_token'])
                ->postJson('/api/v1/auth/email/resend')
                ->assertAccepted();
        }
        $this->withServerVariables(['REMOTE_ADDR' => $resendIp])
            ->withToken($session['access_token'])
            ->postJson('/api/v1/auth/email/resend')
            ->assertTooManyRequests()
            ->assertJsonPath('error.code', 'rate_limited');
    }

    /** @return array<string, mixed> */
    private function registerAccount(string $email): array
    {
        return $this->postJson('/api/v1/auth/register', [
            'name' => 'Persona de Prueba',
            'email' => $email,
            'password' => 'Clave-segura-2026',
            'password_confirmation' => 'Clave-segura-2026',
        ])->assertCreated()->json('data');
    }
}
