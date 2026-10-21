<?php

namespace Tests\Feature;

use App\Enums\AccessGrantStatus;
use App\Enums\ProfileCategory;
use App\Enums\ProfileStatus;
use App\Models\AccessGrant;
use App\Models\City;
use App\Models\Profile;
use App\Models\TermsAcceptance;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class SuspensionAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->seed(CatalogSeeder::class);
    }

    public function test_suspended_account_can_only_read_me_and_access_then_logout(): void
    {
        [$user, $token] = $this->registerAccount('suspended-account@example.test');
        $profileId = $this->createProfile($token, 'Perfil de cuenta suspendida');
        $city = City::query()->firstOrFail();
        $user->forceFill([
            'system_role' => User::ROLE_ADMIN,
            'status' => User::STATUS_SUSPENDED,
        ])->save();

        $this->withToken($token)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.user.status', User::STATUS_SUSPENDED)
            ->assertJsonPath('data.profiles.0.access_state', 'suspended')
            ->assertJsonPath('data.profiles.0.capabilities.can_view_history', false);
        $this->withToken($token)
            ->getJson('/api/v1/profiles/'.$profileId.'/access')
            ->assertOk()
            ->assertJsonPath('data.allowed', false)
            ->assertJsonPath('data.denial_code', 'account_suspended');

        $blocked = [
            $this->withToken($token)->patchJson('/api/v1/me', ['name' => 'No debe cambiar']),
            $this->withToken($token)->postJson('/api/v1/me/change-email', [
                'email' => 'new-suspended@example.test',
                'password' => 'Clave-segura-2026',
            ]),
            $this->withToken($token)->postJson('/api/v1/me/change-password', [
                'current_password' => 'Clave-segura-2026',
                'new_password' => 'Nueva-clave-2026',
                'new_password_confirmation' => 'Nueva-clave-2026',
            ]),
            $this->withToken($token)->postJson('/api/v1/auth/email/resend'),
            $this->withToken($token)->getJson('/api/v1/catalogs'),
            $this->withToken($token)->postJson('/api/v1/profiles', [
                'category' => ProfileCategory::Guide->value,
                'display_name' => 'No debe crearse',
            ]),
            $this->withToken($token)->getJson('/api/v1/profiles/'.$profileId),
            $this->withToken($token)->patchJson('/api/v1/profiles/'.$profileId, [
                'display_name' => 'No debe editarse',
            ]),
            $this->withToken($token)->postJson('/api/v1/profiles/'.$profileId.'/terms', [
                'version' => 'demo-v1',
                'accepted' => true,
            ]),
            $this->withToken($token)->postJson('/api/v1/profiles/'.$profileId.'/complete'),
            $this->withToken($token)->putJson('/api/v1/profiles/'.$profileId.'/cities', [
                'city_ids' => [$city->id],
            ]),
            $this->withToken($token)->putJson('/api/v1/admin/profiles/'.$profileId.'/access', [
                'status' => AccessGrantStatus::Active->value,
                'starts_at' => null,
                'expires_at' => null,
                'city_limit' => null,
                'monthly_contact_limit' => null,
                'gallery_enabled' => false,
                'expected_version' => 0,
                'reason' => 'No debe concederse',
            ]),
            $this->withToken($token)->postJson('/api/v1/profiles/'.$profileId.'/avatar'),
            $this->withToken($token)->postJson('/api/v1/profiles/'.$profileId.'/gallery'),
            $this->withToken($token)->deleteJson('/api/v1/profiles/'.$profileId.'/gallery/'.Str::ulid()),
            $this->withToken($token)->getJson('/api/v1/profiles/'.$profileId.'/documents'),
            $this->withToken($token)->postJson('/api/v1/profiles/'.$profileId.'/documents'),
            $this->withToken($token)->getJson('/api/v1/profiles/'.$profileId.'/documents/'.Str::ulid().'/download'),
            $this->withToken($token)->deleteJson('/api/v1/profiles/'.$profileId.'/documents/'.Str::ulid()),
            $this->withToken($token)->getJson('/api/v1/admin/documents'),
            $this->withToken($token)->postJson('/api/v1/admin/documents/'.Str::ulid().'/review', [
                'action' => 'approve',
            ]),
            $this->withToken($token)->getJson('/api/v1/admin/audit-logs'),
        ];

        foreach ($blocked as $response) {
            $this->assertAccountSuspended($response);
        }

        $this->assertSame('Perfil de cuenta suspendida', $this->profile($profileId)->display_name);
        $this->assertSame(1, Profile::query()->where('owner_user_id', $user->id)->count());
        $this->assertSame(0, TermsAcceptance::query()->count());

        $this->withToken($token)
            ->postJson('/api/v1/auth/logout')
            ->assertNoContent();
        $this->withToken($token)
            ->getJson('/api/v1/me')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'unauthorized');
    }

    public function test_profile_or_grant_suspension_allows_owner_reads_but_blocks_mutations(): void
    {
        [$owner, $token] = $this->registerAccount('suspended-profile@example.test');
        $statusProfileId = $this->createProfile($token, 'Perfil suspendido por estado');
        $grantProfileId = $this->createProfile($token, 'Perfil suspendido por grant');
        $statusProfile = $this->profile($statusProfileId);
        $statusProfile->forceFill(['status' => ProfileStatus::Suspended])->save();
        $grantProfile = $this->profile($grantProfileId);
        $this->suspendedGrant($grantProfile, $owner);
        $city = City::query()->firstOrFail();

        foreach ([$statusProfileId, $grantProfileId] as $profileId) {
            $this->withToken($token)
                ->getJson('/api/v1/profiles/'.$profileId)
                ->assertOk()
                ->assertJsonPath('data.id', $profileId);
            $this->withToken($token)
                ->getJson('/api/v1/profiles/'.$profileId.'/access')
                ->assertOk()
                ->assertJsonPath('data.effective_state', 'suspended')
                ->assertJsonPath('data.allowed', false)
                ->assertJsonPath('data.denial_code', 'profile_suspended')
                ->assertJsonPath('data.capabilities.can_view_history', true)
                ->assertJsonPath('data.capabilities.can_reject_pending_requests', false);

            $blocked = [
                $this->withToken($token)->patchJson('/api/v1/profiles/'.$profileId, [
                    'display_name' => 'No debe mutar',
                ]),
                $this->withToken($token)->putJson('/api/v1/profiles/'.$profileId.'/cities', [
                    'city_ids' => [$city->id],
                ]),
                $this->withToken($token)->postJson('/api/v1/profiles/'.$profileId.'/terms', [
                    'version' => 'demo-v1',
                    'accepted' => true,
                ]),
                $this->withToken($token)->postJson('/api/v1/profiles/'.$profileId.'/complete'),
            ];

            foreach ($blocked as $response) {
                $response
                    ->assertForbidden()
                    ->assertJsonPath('error.code', 'profile_suspended');
            }
        }

        $this->assertSame('Perfil suspendido por estado', $statusProfile->refresh()->display_name);
        $this->assertSame(ProfileStatus::Suspended, $statusProfile->status);
        $this->assertSame('Perfil suspendido por grant', $grantProfile->refresh()->display_name);
        $this->assertSame(ProfileStatus::Draft, $grantProfile->status);
        $this->assertSame(0, $statusProfile->cities()->count());
        $this->assertSame(0, $grantProfile->cities()->count());
        $this->assertSame(0, TermsAcceptance::query()->count());
    }

    /** @param TestResponse<Response> $response */
    private function assertAccountSuspended(TestResponse $response): void
    {
        $response
            ->assertForbidden()
            ->assertJsonPath('error.code', 'account_suspended');
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

    private function createProfile(string $token, string $displayName): string
    {
        return $this->withToken($token)->postJson('/api/v1/profiles', [
            'category' => ProfileCategory::Guide->value,
            'display_name' => $displayName,
        ])->assertCreated()->json('data.id');
    }

    private function profile(string $publicId): Profile
    {
        return Profile::query()->where('public_id', $publicId)->firstOrFail();
    }

    private function suspendedGrant(Profile $profile, User $actor): void
    {
        AccessGrant::query()->create([
            'profile_id' => $profile->id,
            'status' => AccessGrantStatus::Suspended,
            'starts_at' => null,
            'expires_at' => null,
            'city_limit' => null,
            'monthly_contact_limit' => null,
            'gallery_enabled' => false,
            'version' => 1,
            'updated_by' => $actor->id,
            'reason' => 'Suspensión de prueba',
        ]);
    }
}
