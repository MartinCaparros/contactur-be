<?php

namespace Tests\Feature;

use App\Contracts\Clock;
use App\Enums\AccessGrantStatus;
use App\Enums\ProfileCategory;
use App\Enums\ProfileStatus;
use App\Exceptions\ApiProblemException;
use App\Models\AccessGrant;
use App\Models\Profile;
use App\Models\User;
use App\Services\AccessService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\FrozenClock;
use Tests\TestCase;

final class AccessEvaluationTest extends TestCase
{
    use RefreshDatabase;

    private FrozenClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->clock = new FrozenClock(CarbonImmutable::now('UTC')->subMinute()->startOfSecond());
        $this->app->instance(Clock::class, $this->clock);
    }

    public function test_p10_effective_state_boundaries_and_suspensions_are_evaluated_with_frozen_clock(): void
    {
        $owner = User::factory()->create();
        $profile = Profile::factory()->for($owner, 'owner')->guide()->create();
        $access = $this->app->make(AccessService::class);

        $evaluation = $access->evaluate($owner, $profile, null);
        $this->assertSame('inactive', $evaluation->effectiveState);
        $this->assertSame('subscription_required', $evaluation->denialCode);
        $this->assertFalse($evaluation->allowed);
        $this->assertNull($evaluation->grant);

        $grant = $this->grant($profile, [
            'status' => AccessGrantStatus::Inactive,
            'starts_at' => $this->clock->now()->subDay(),
            'expires_at' => null,
        ]);
        $this->assertSame('inactive', $access->evaluate($owner, $profile, null)->effectiveState);

        $grant->forceFill([
            'status' => AccessGrantStatus::Active,
            'starts_at' => $this->clock->now()->addSecond(),
            'expires_at' => null,
        ])->save();
        $future = $access->evaluate($owner, $profile, null);
        $this->assertSame('inactive', $future->effectiveState);
        $this->assertSame('subscription_required', $future->denialCode);

        $grant->forceFill([
            'starts_at' => $this->clock->now(),
            'expires_at' => $this->clock->now(),
        ])->save();
        $expiredAtBoundary = $access->evaluate($owner, $profile, null);
        $this->assertSame('expired', $expiredAtBoundary->effectiveState);
        $this->assertSame('subscription_expired', $expiredAtBoundary->denialCode);

        $grant->forceFill(['expires_at' => null])->save();
        $activeWithoutExpiry = $access->evaluate($owner, $profile, null);
        $this->assertSame('active', $activeWithoutExpiry->effectiveState);
        $this->assertTrue($activeWithoutExpiry->allowed);
        $this->assertNull($activeWithoutExpiry->denialCode);

        $grant->forceFill(['status' => AccessGrantStatus::Suspended])->save();
        $suspendedGrant = $access->evaluate($owner, $profile, null);
        $this->assertSame('suspended', $suspendedGrant->effectiveState);
        $this->assertSame('profile_suspended', $suspendedGrant->denialCode);
        $this->assertTrue($suspendedGrant->capabilities[AccessService::CAPABILITY_VIEW_HISTORY]);
        $this->assertFalse($suspendedGrant->capabilities[AccessService::CAPABILITY_REJECT_PENDING_REQUESTS]);

        try {
            $access->assertAllowed($owner, $profile, AccessService::CAPABILITY_SEARCH);
            $this->fail('A suspended profile grant must deny premium capabilities.');
        } catch (ApiProblemException $exception) {
            $this->assertSame(403, $exception->status);
            $this->assertSame('profile_suspended', $exception->errorCode);
        }

        $grant->forceFill([
            'status' => AccessGrantStatus::Active,
            'expires_at' => $this->clock->now()->subSecond(),
        ])->save();
        $profile->forceFill(['status' => ProfileStatus::Suspended])->save();
        $suspendedProfile = $access->evaluate($owner, $profile, null);
        $this->assertSame('suspended', $suspendedProfile->effectiveState);
        $this->assertSame('profile_suspended', $suspendedProfile->denialCode);

        $profile->forceFill(['status' => ProfileStatus::Published])->save();
        $owner->forceFill(['status' => User::STATUS_SUSPENDED])->save();
        $suspendedAccount = $access->evaluate($owner, $profile, null);
        $this->assertSame('suspended', $suspendedAccount->effectiveState);
        $this->assertSame('account_suspended', $suspendedAccount->denialCode);
    }

    public function test_p10_me_and_owned_access_remain_available_without_an_effective_grant(): void
    {
        [$owner, $token] = $this->registerAccount('access-owner@example.test');
        $profileId = $this->withToken($token)->postJson('/api/v1/profiles', [
            'category' => ProfileCategory::Guide->value,
            'display_name' => 'Guía sin grant',
        ])->assertCreated()->json('data.id');

        $this->withToken($token)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.profiles.0.access_state', 'inactive')
            ->assertJsonPath('data.profiles.0.capabilities.can_view_history', true);
        $this->withToken($token)
            ->getJson('/api/v1/profiles/'.$profileId.'/access')
            ->assertOk()
            ->assertJsonPath('data.profile_id', $profileId)
            ->assertJsonPath('data.effective_state', 'inactive')
            ->assertJsonPath('data.denial_code', 'subscription_required')
            ->assertJsonPath('data.grant', null);

        $profile = Profile::query()->where('public_id', $profileId)->firstOrFail();
        $this->grant($profile, [
            'status' => AccessGrantStatus::Active,
            'starts_at' => $this->clock->now()->subDay(),
            'expires_at' => $this->clock->now(),
        ]);

        $this->withToken($token)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.profiles.0.access_state', 'expired');
        $this->withToken($token)
            ->getJson('/api/v1/profiles/'.$profileId.'/access')
            ->assertOk()
            ->assertJsonPath('data.effective_state', 'expired')
            ->assertJsonPath('data.denial_code', 'subscription_expired');
        $this->assertSame($owner->id, $profile->owner_user_id);
    }

    public function test_p10_capabilities_follow_category_boolean_and_null_or_zero_limits(): void
    {
        $owner = User::factory()->create();
        $access = $this->app->make(AccessService::class);
        $company = Profile::factory()->for($owner, 'owner')->tourismCompany()->create();
        $companyGrant = $this->grant($company, [
            'status' => AccessGrantStatus::Active,
            'starts_at' => $this->clock->now(),
            'expires_at' => null,
            'city_limit' => null,
            'monthly_contact_limit' => null,
            'gallery_enabled' => true,
        ]);
        $companyEvaluation = $access->evaluate($owner, $company, null);

        $this->assertTrue($companyEvaluation->capabilities[AccessService::CAPABILITY_SEARCH]);
        $this->assertTrue($companyEvaluation->capabilities[AccessService::CAPABILITY_SEND_CONTACT_REQUESTS]);
        $this->assertFalse($companyEvaluation->capabilities[AccessService::CAPABILITY_PUBLISH_AVAILABILITY]);
        $this->assertFalse($companyEvaluation->capabilities[AccessService::CAPABILITY_RECEIVE_CONTACT_REQUESTS]);
        $this->assertTrue($companyEvaluation->capabilities[AccessService::CAPABILITY_USE_GALLERY]);
        $this->assertNull($companyEvaluation->contactQuota['limit']);
        $this->assertNull($companyEvaluation->contactQuota['remaining']);

        foreach ([
            ProfileCategory::Guide,
            ProfileCategory::Driver,
            ProfileCategory::Instructor,
            ProfileCategory::TransportCompany,
        ] as $category) {
            $receiver = Profile::factory()->for($owner, 'owner')->create(['category' => $category]);
            $this->grant($receiver, [
                'status' => AccessGrantStatus::Active,
                'starts_at' => null,
                'expires_at' => null,
                'city_limit' => null,
                'gallery_enabled' => false,
            ]);
            $evaluation = $access->evaluate($owner, $receiver, null);
            $this->assertTrue($evaluation->capabilities[AccessService::CAPABILITY_SEARCH]);
            $this->assertFalse($evaluation->capabilities[AccessService::CAPABILITY_SEND_CONTACT_REQUESTS]);
            $this->assertTrue($evaluation->capabilities[AccessService::CAPABILITY_PUBLISH_AVAILABILITY]);
            $this->assertTrue($evaluation->capabilities[AccessService::CAPABILITY_RECEIVE_CONTACT_REQUESTS]);
            $this->assertFalse($evaluation->capabilities[AccessService::CAPABILITY_USE_GALLERY]);
            $this->assertNull($evaluation->contactQuota);
        }

        $companyGrant->forceFill(['monthly_contact_limit' => 0, 'gallery_enabled' => false])->save();
        $zeroLimits = $access->evaluate($owner, $company, null);
        $this->assertFalse($zeroLimits->capabilities[AccessService::CAPABILITY_SEND_CONTACT_REQUESTS]);
        $this->assertFalse($zeroLimits->capabilities[AccessService::CAPABILITY_USE_GALLERY]);
        $this->assertSame(0, $zeroLimits->contactQuota['limit']);
        $this->assertSame(0, $zeroLimits->contactQuota['remaining']);

        try {
            $access->assertAllowed($owner, $company, AccessService::CAPABILITY_SEND_CONTACT_REQUESTS);
            $this->fail('A zero monthly limit must deny contact requests.');
        } catch (ApiProblemException $exception) {
            $this->assertSame(403, $exception->status);
            $this->assertSame('capability_limit_reached', $exception->errorCode);
            $this->assertSame(['0'], $exception->fields['limit']);
        }
    }

    public function test_target_owner_suspension_denies_access_for_an_active_actor(): void
    {
        $actor = User::factory()->create();
        $owner = User::factory()->create(['status' => User::STATUS_SUSPENDED]);
        $profile = Profile::factory()->for($owner, 'owner')->tourismCompany()->create([
            'status' => ProfileStatus::Published,
        ]);
        $this->grant($profile, [
            'status' => AccessGrantStatus::Active,
            'starts_at' => null,
            'expires_at' => null,
            'monthly_contact_limit' => 10,
        ]);
        $this->assertFalse($profile->relationLoaded('owner'));

        $evaluation = $this->app->make(AccessService::class)->evaluate($actor, $profile, null);

        $this->assertTrue($profile->relationLoaded('owner'));
        $this->assertSame('suspended', $evaluation->effectiveState);
        $this->assertFalse($evaluation->allowed);
        $this->assertSame('account_suspended', $evaluation->denialCode);
        $this->assertFalse($evaluation->capabilities[AccessService::CAPABILITY_SEARCH]);
        $this->assertFalse($evaluation->capabilities[AccessService::CAPABILITY_VIEW_HISTORY]);
    }

    public function test_contact_quota_month_uses_buenos_aires_at_utc_boundary(): void
    {
        $owner = User::factory()->create();
        $profile = Profile::factory()->for($owner, 'owner')->tourismCompany()->create();
        $this->grant($profile, [
            'status' => AccessGrantStatus::Active,
            'starts_at' => null,
            'expires_at' => null,
            'monthly_contact_limit' => 10,
        ]);
        $access = $this->app->make(AccessService::class);

        $this->clock->set(CarbonImmutable::parse('2026-04-01T02:59:59Z'));
        $beforeBoundary = $access->evaluate($owner, $profile, null);
        $this->assertSame('2026-03-01', $beforeBoundary->contactQuota['month_start']);

        $this->clock->set(CarbonImmutable::parse('2026-04-01T03:00:00Z'));
        $atBoundary = $access->evaluate($owner, $profile, null);
        $this->assertSame('2026-04-01', $atBoundary->contactQuota['month_start']);
    }

    /** @param array<string, mixed> $overrides */
    private function grant(Profile $profile, array $overrides): AccessGrant
    {
        return AccessGrant::query()->create(array_merge([
            'profile_id' => $profile->id,
            'status' => AccessGrantStatus::Inactive,
            'starts_at' => null,
            'expires_at' => null,
            'city_limit' => null,
            'monthly_contact_limit' => null,
            'gallery_enabled' => false,
            'version' => 1,
            'updated_by' => $profile->owner_user_id,
            'reason' => 'Fixture de prueba',
        ], $overrides));
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
}
