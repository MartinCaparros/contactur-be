<?php

namespace Tests\Feature;

use App\Contracts\Clock;
use App\Enums\AccessGrantStatus;
use App\Enums\ProfileCategory;
use App\Enums\ProfileStatus;
use App\Models\AccessGrant;
use App\Models\Profile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use stdClass;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FrozenClock;
use Tests\TestCase;

/**
 * T16 / R10 R21: POST /api/v1/broadcasting/auth (authorizePrivateBroadcastChannel).
 * Revalida ownership y acceso en cada suscripción y firma con el SDK de Pusher.
 */
final class BroadcastChannelAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/broadcasting/auth';

    private const SOCKET_ID = '1234.5678';

    private const APP_KEY = 'contactur-test-key';

    private const APP_SECRET = 'contactur-test-secret';

    private FrozenClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->seed(CatalogSeeder::class);
        $now = CarbonImmutable::parse('2026-11-10T02:30:00Z');
        $this->travelTo($now);
        $this->clock = new FrozenClock($now);
        $this->app->instance(Clock::class, $this->clock);
        // Credenciales deterministas: la firma esperada se calcula en el test.
        config([
            'broadcasting.connections.reverb.key' => self::APP_KEY,
            'broadcasting.connections.reverb.secret' => self::APP_SECRET,
            'broadcasting.connections.reverb.app_id' => 'contactur-test',
        ]);
    }

    public function test_requires_bearer_token(): void
    {
        $response = $this->postJson(self::URL, [
            'socket_id' => self::SOCKET_ID,
            'channel_name' => 'private-directory',
        ])->assertUnauthorized()->assertJsonPath('error.code', 'unauthorized');

        $this->assertErrorShape($response);
    }

    public function test_rejects_malformed_socket_ids_unknown_channel_shapes_and_extra_fields_with_422(): void
    {
        [, $token] = $this->registerAccount('t16-auth-422@example.test');
        $ulid = (string) Str::ulid();

        $missing = $this->withToken($token)->postJson(self::URL, [])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['fields' => ['socket_id', 'channel_name']]]);
        $this->assertErrorShape($missing);

        // TrimStrings recorta espacios/saltos en los extremos; un salto interno debe fallar.
        foreach (['abc', '1234', '1234.', '.5678', '12a4.5678', "1234.5678\n9", '1234.5678.9', '-1.5'] as $socketId) {
            $this->withToken($token)->postJson(self::URL, [
                'socket_id' => $socketId,
                'channel_name' => 'private-directory',
            ])->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['socket_id']]]);
        }

        foreach ([
            'directory',
            'presence-directory',
            'private-directory.extra',
            'private-profile.',
            'private-profile.'.strtolower($ulid),
            'private-profile.'.$ulid.'x',
            'private-profile.'.$ulid."\nprivate-directory",
            'private-user.'.$ulid,
            'private-encrypted-directory',
        ] as $channel) {
            $this->withToken($token)->postJson(self::URL, [
                'socket_id' => self::SOCKET_ID,
                'channel_name' => $channel,
            ])->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['channel_name']]]);
        }

        $this->withToken($token)->postJson(self::URL, [
            'socket_id' => self::SOCKET_ID,
            'channel_name' => 'private-directory',
            'channel_data' => '{"user_id":1}',
        ])->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['channel_data']]]);
    }

    public function test_foreign_and_nonexistent_profile_channels_get_the_same_403(): void
    {
        [$owner] = $this->registerAccount('t16-owner@example.test');
        $foreign = $this->profileFor($owner, ProfileCategory::Guide);
        $this->grant($foreign, AccessGrantStatus::Active);
        [, $intruderToken] = $this->registerAccount('t16-intruder@example.test');

        $foreignResponse = $this->authorizeChannel($intruderToken, 'private-profile.'.$foreign->public_id)
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden');
        $missingResponse = $this->authorizeChannel($intruderToken, 'private-profile.'.Str::ulid())
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden');

        $this->assertSame($foreignResponse->json('error'), $missingResponse->json('error'));
        $this->assertErrorShape($foreignResponse);
        $this->assertStringNotContainsString($foreign->display_name, (string) $foreignResponse->getContent());
    }

    public function test_suspended_account_and_suspended_profile_cannot_subscribe(): void
    {
        [$owner, $token] = $this->registerAccount('t16-suspended@example.test');
        $profile = $this->profileFor($owner, ProfileCategory::Guide);
        $this->grant($profile, AccessGrantStatus::Active);

        $profile->forceFill(['status' => ProfileStatus::Suspended])->save();
        $this->authorizeChannel($token, 'private-profile.'.$profile->public_id)
            ->assertForbidden()
            ->assertJsonPath('error.code', 'profile_suspended');
        $this->authorizeChannel($token, 'private-directory')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden');

        $profile->forceFill(['status' => ProfileStatus::Published])->save();
        $owner->forceFill(['status' => User::STATUS_SUSPENDED])->save();
        foreach (['private-profile.'.$profile->public_id, 'private-directory'] as $channel) {
            $this->assertErrorShape(
                $this->authorizeChannel($token, $channel)
                    ->assertForbidden()
                    ->assertJsonPath('error.code', 'account_suspended'),
            );
        }
    }

    public function test_owner_gets_a_pusher_signature_for_its_own_profile_channel_even_when_expired(): void
    {
        [$owner, $token] = $this->registerAccount('t16-own-channel@example.test');
        $profile = $this->profileFor($owner, ProfileCategory::Driver);
        // D21: un acceso vencido conserva lectura de estado y avisos propios.
        $this->grant($profile, AccessGrantStatus::Active, expiresAt: $this->clock->now()->subDay());
        $channel = 'private-profile.'.$profile->public_id;

        $response = $this->authorizeChannel($token, $channel)
            ->assertOk()
            ->assertJsonPath('data.auth', $this->expectedAuth($channel))
            ->assertJsonPath('data.channel_data', null);

        $this->assertContractShape($response);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        // La firma depende del socket: otro socket_id produce otra firma.
        $this->assertNotSame(
            $response->json('data.auth'),
            $this->authorizeChannel($token, $channel, '9999.1')->assertOk()->json('data.auth'),
        );
    }

    public function test_directory_channel_requires_a_profile_with_current_search_access(): void
    {
        [$enabled, $enabledToken] = $this->registerAccount('t16-directory-enabled@example.test');
        $this->grant($this->profileFor($enabled, ProfileCategory::TourismCompany), AccessGrantStatus::Active);

        $response = $this->authorizeChannel($enabledToken, 'private-directory')
            ->assertOk()
            ->assertJsonPath('data.auth', $this->expectedAuth('private-directory'))
            ->assertJsonPath('data.channel_data', null);
        $this->assertContractShape($response);

        // Ningún perfil habilitado: sin grant, inactivo, vencido, futuro, grant suspendido, perfil suspendido.
        [$denied, $deniedToken] = $this->registerAccount('t16-directory-denied@example.test');
        $this->profileFor($denied, ProfileCategory::Guide);
        $this->grant($this->profileFor($denied, ProfileCategory::Guide), AccessGrantStatus::Inactive);
        $this->grant(
            $this->profileFor($denied, ProfileCategory::Guide),
            AccessGrantStatus::Active,
            expiresAt: $this->clock->now()->subMinute(),
        );
        $this->grant(
            $this->profileFor($denied, ProfileCategory::Guide),
            AccessGrantStatus::Active,
            startsAt: $this->clock->now()->addDay(),
        );
        $this->grant($this->profileFor($denied, ProfileCategory::Instructor), AccessGrantStatus::Suspended);
        $suspendedProfile = $this->profileFor($denied, ProfileCategory::Driver, ProfileStatus::Suspended);
        $this->grant($suspendedProfile, AccessGrantStatus::Active);

        $deniedResponse = $this->authorizeChannel($deniedToken, 'private-directory')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden');
        $this->assertErrorShape($deniedResponse);

        // Basta un perfil con can_search vigente en la cuenta.
        $this->grant($this->profileFor($denied, ProfileCategory::TransportCompany), AccessGrantStatus::Active);
        $this->authorizeChannel($deniedToken, 'private-directory')->assertOk();
    }

    public function test_revoked_access_cannot_resubscribe_to_the_directory(): void
    {
        [$owner, $token] = $this->registerAccount('t16-revoked@example.test');
        $profile = $this->profileFor($owner, ProfileCategory::TourismCompany);
        $this->grant($profile, AccessGrantStatus::Active);
        [, $adminToken] = $this->registerAdmin('t16-revoked-admin@example.test');

        $this->authorizeChannel($token, 'private-directory')->assertOk();

        $this->withToken($adminToken)
            ->putJson('/api/v1/admin/profiles/'.$profile->public_id.'/access', [
                'status' => AccessGrantStatus::Inactive->value,
                'starts_at' => null,
                'expires_at' => null,
                'city_limit' => null,
                'monthly_contact_limit' => null,
                'gallery_enabled' => false,
                'expected_version' => 1,
                'reason' => 'Revocación de prueba T16',
            ])
            ->assertOk();

        // La reconexión del socket vuelve a pedir firma y ya no la obtiene.
        $this->authorizeChannel($token, 'private-directory')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden');
        // El canal propio sigue disponible para enterarse del cambio (inactivo ≠ suspendido).
        $this->authorizeChannel($token, 'private-profile.'.$profile->public_id)->assertOk();
    }

    public function test_is_rate_limited_per_account(): void
    {
        config(['contactur.rate_limits.broadcast_auth_per_minute' => 2]);
        [$owner, $token] = $this->registerAccount('t16-rate-limit@example.test');
        $profile = $this->profileFor($owner, ProfileCategory::Guide);
        $channel = 'private-profile.'.$profile->public_id;

        $this->authorizeChannel($token, $channel);
        $this->authorizeChannel($token, $channel);
        $limited = $this->authorizeChannel($token, $channel)
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'rate_limited');

        $this->assertErrorShape($limited);
        $this->assertTrue($limited->headers->has('Retry-After'));
    }

    /** @return TestResponse<Response> */
    private function authorizeChannel(string $token, string $channel, string $socketId = self::SOCKET_ID): TestResponse
    {
        return $this->withToken($token)->postJson(self::URL, [
            'socket_id' => $socketId,
            'channel_name' => $channel,
        ]);
    }

    private function expectedAuth(string $channel, string $socketId = self::SOCKET_ID): string
    {
        return self::APP_KEY.':'.hash_hmac('sha256', $socketId.':'.$channel, self::APP_SECRET);
    }

    /** BroadcastAuthorizationResponse: additionalProperties false en ambos niveles. */
    private function assertContractShape(TestResponse $response): void
    {
        $payload = json_decode((string) $response->getContent(), false, 512, JSON_THROW_ON_ERROR);
        $this->assertInstanceOf(stdClass::class, $payload);
        $this->assertSame(['data'], array_keys((array) $payload));
        $this->assertInstanceOf(stdClass::class, $payload->data);
        $this->assertSame(['auth', 'channel_data'], array_keys((array) $payload->data));
        $this->assertIsString($payload->data->auth);
        $this->assertMatchesRegularExpression('/\A'.preg_quote(self::APP_KEY, '/').':[0-9a-f]{64}\z/', $payload->data->auth);
        $this->assertTrue($payload->data->channel_data === null || is_string($payload->data->channel_data));
    }

    /** ErrorResponse: {error: {code, message, fields?}, request_id}. */
    private function assertErrorShape(TestResponse $response): void
    {
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload);
        $this->assertSame(['error', 'request_id'], array_keys($payload));
        $this->assertIsString($payload['request_id']);
        $this->assertNotSame('', $payload['request_id']);
        $this->assertIsArray($payload['error']);
        $this->assertSame([], array_diff(array_keys($payload['error']), ['code', 'message', 'fields']));
        $this->assertIsString($payload['error']['code']);
        $this->assertIsString($payload['error']['message']);
    }

    /** @return array{User, string} */
    private function registerAccount(string $email): array
    {
        $data = $this->postJson('/api/v1/auth/register', [
            'name' => 'Persona de Prueba',
            'email' => $email,
            'password' => 'Clave-segura-2026',
            'password_confirmation' => 'Clave-segura-2026',
        ])->assertCreated()->json('data');

        return [User::query()->where('email', $email)->firstOrFail(), $data['access_token']];
    }

    /** @return array{User, string} */
    private function registerAdmin(string $email): array
    {
        [$user, $token] = $this->registerAccount($email);
        $user->forceFill(['system_role' => User::ROLE_ADMIN])->save();

        return [$user, $token];
    }

    private function profileFor(
        User $owner,
        ProfileCategory $category,
        ProfileStatus $status = ProfileStatus::Published,
    ): Profile {
        return Profile::factory()->create([
            'owner_user_id' => $owner->id,
            'category' => $category,
            'status' => $status,
            'completed_at' => $this->clock->now()->subDay(),
        ]);
    }

    private function grant(
        Profile $profile,
        AccessGrantStatus $status,
        ?CarbonImmutable $startsAt = null,
        ?CarbonImmutable $expiresAt = null,
    ): AccessGrant {
        return AccessGrant::query()->create([
            'profile_id' => $profile->id,
            'status' => $status,
            'starts_at' => $startsAt,
            'expires_at' => $expiresAt,
            'city_limit' => null,
            'monthly_contact_limit' => null,
            'gallery_enabled' => false,
            'version' => 1,
            'updated_by' => $profile->owner_user_id,
            'reason' => 'Habilitación de prueba',
        ]);
    }
}
