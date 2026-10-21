<?php

namespace Tests\Feature;

use App\Contracts\Clock;
use App\Models\AuthSession;
use App\Models\User;
use App\Services\AccessTokenService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\JWT;
use Tests\Support\FrozenClock;
use Tests\TestCase;

class AuthSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    public function test_p02_rejects_invalid_signature_with_generic_401(): void
    {
        $session = $this->registerAccount('firma@example.test');
        $token = $session['access_token'];
        $invalidToken = substr($token, 0, -1).(substr($token, -1) === 'a' ? 'b' : 'a');

        $response = $this->withHeader('X-Request-ID', 'p02-invalid-signature')
            ->withToken($invalidToken)
            ->getJson('/api/v1/me');

        $this->assertUnauthorizedEnvelope($response->json(), 'p02-invalid-signature');
        $response->assertUnauthorized()->assertHeader('X-Request-ID', 'p02-invalid-signature');
    }

    public function test_p02_rejects_expired_access_token_without_sleeping(): void
    {
        $clock = new FrozenClock(CarbonImmutable::now('UTC'));
        $this->app->instance(Clock::class, $clock);
        $session = $this->registerAccount('expirado@example.test');
        $future = $clock->now()->addHour();
        $clock->set($future);
        Carbon::setTestNow($future);

        try {
            $response = $this->withToken($session['access_token'])->getJson('/api/v1/me');
        } finally {
            Carbon::setTestNow();
        }

        $response->assertUnauthorized()->assertJsonPath('error.code', 'unauthorized');
    }

    public function test_p02_rejects_unexpected_audience_and_issuer(): void
    {
        $session = $this->registerAccount('audiencia@example.test');
        $expectedAudience = (string) config('contactur.jwt.audience');
        $expectedIssuer = (string) config('contactur.jwt.issuer');
        config()->set('contactur.jwt.audience', 'unexpected-audience');

        $this->withToken($session['access_token'])
            ->getJson('/api/v1/me')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'unauthorized');

        config()->set('contactur.jwt.audience', $expectedAudience);
        config()->set('contactur.jwt.issuer', 'https://unexpected-issuer.example.test');
        $this->withToken($session['access_token'])
            ->getJson('/api/v1/me')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'unauthorized');
        config()->set('contactur.jwt.issuer', $expectedIssuer);
    }

    public function test_p02_rejects_signed_non_canonical_or_public_subjects(): void
    {
        $registered = $this->registerAccount('subject@example.test');
        $user = User::query()->where('email', 'subject@example.test')->firstOrFail();
        $session = AuthSession::query()->where('user_id', $user->id)->firstOrFail();
        $now = CarbonImmutable::now('UTC');

        foreach (["0{$user->id}", $user->public_id] as $invalidSubject) {
            $token = $this->app->make(JWT::class)->claims([
                'iss' => (string) config('contactur.jwt.issuer'),
                'aud' => (string) config('contactur.jwt.audience'),
                'sid' => $session->sid,
                'sub' => $invalidSubject,
                'iat' => $now->timestamp,
                'nbf' => $now->timestamp,
                'exp' => $now->addMinute()->timestamp,
                'jti' => (string) Str::ulid(),
            ])->fromUser($user);

            $this->withToken($token)
                ->getJson('/api/v1/me')
                ->assertUnauthorized()
                ->assertJsonPath('error.code', 'unauthorized');
        }

        $this->assertSame((string) $user->id, $this->app->make(AccessTokenService::class)
            ->parseAndValidate($registered['access_token'])['sub']);
    }

    public function test_p02_rejects_revoked_sid_and_allows_suspended_user_limited_auth(): void
    {
        $session = $this->registerAccount('revocada@example.test');

        $this->withToken($session['access_token'])
            ->postJson('/api/v1/auth/logout')
            ->assertNoContent();
        $this->withToken($session['access_token'])
            ->getJson('/api/v1/me')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'unauthorized');

        $active = $this->registerAccount('suspendida@example.test');
        User::query()->where('email', 'suspendida@example.test')->update([
            'status' => User::STATUS_SUSPENDED,
        ]);
        $this->withToken($active['access_token'])
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.user.status', User::STATUS_SUSPENDED);
    }

    public function test_p03_refresh_rotates_once_and_replay_revokes_the_entire_family(): void
    {
        $original = $this->registerAccount('rotacion@example.test');
        $originalRefresh = $original['refresh_token'];
        $originalSession = AuthSession::query()
            ->where('refresh_hash', hash('sha256', $originalRefresh))
            ->firstOrFail();

        $rotated = $this->postJson('/api/v1/auth/refresh', [
            'refresh_token' => $originalRefresh,
        ])->assertOk()->json('data');

        $this->assertNotSame($originalRefresh, $rotated['refresh_token']);
        $this->assertNotSame($original['access_token'], $rotated['access_token']);
        $this->assertDatabaseMissing('auth_sessions', ['refresh_hash' => $originalRefresh]);

        $originalSession->refresh();
        $replacement = AuthSession::query()
            ->where('refresh_hash', hash('sha256', $rotated['refresh_token']))
            ->firstOrFail();
        $this->assertNotNull($originalSession->rotated_at);
        $this->assertNotNull($originalSession->revoked_at);
        $this->assertSame($replacement->sid, $originalSession->replaced_by_sid);
        $this->assertSame($originalSession->family_id, $replacement->family_id);
        $this->assertNull($replacement->revoked_at);
        $this->assertSame(2, AuthSession::query()->where('family_id', $replacement->family_id)->count());

        $this->postJson('/api/v1/auth/refresh', [
            'refresh_token' => $originalRefresh,
        ])->assertUnauthorized()->assertJsonPath('error.code', 'unauthorized');

        $this->assertSame(2, AuthSession::query()->where('family_id', $replacement->family_id)->count());
        $this->assertSame(0, AuthSession::query()
            ->where('family_id', $replacement->family_id)
            ->whereNull('revoked_at')
            ->count());
        $this->withToken($rotated['access_token'])
            ->getJson('/api/v1/me')
            ->assertUnauthorized();
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

    /** @param array<string, mixed> $payload */
    private function assertUnauthorizedEnvelope(array $payload, string $requestId): void
    {
        $this->assertSame(['error', 'request_id'], array_keys($payload));
        $this->assertSame(['code', 'message'], array_keys($payload['error']));
        $this->assertSame('unauthorized', $payload['error']['code']);
        $this->assertSame('No autorizado.', $payload['error']['message']);
        $this->assertSame($requestId, $payload['request_id']);
    }
}
