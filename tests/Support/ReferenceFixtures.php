<?php

namespace Tests\Support;

use App\Contracts\Clock;
use App\Enums\AccessGrantStatus;
use App\Enums\ProfileCategory;
use App\Enums\ProfileStatus;
use App\Models\AccessGrant;
use App\Models\Profile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fixtures compartidos de T20 (referencias). Igual que las pruebas de T17: Carbon
 * queda en T0 (validez del JWT) y solo se mueve el FrozenClock de la app.
 */
trait ReferenceFixtures
{
    protected FrozenClock $clock;

    protected function freezeReferenceClock(string $instant): void
    {
        $now = CarbonImmutable::parse($instant);
        $this->travelTo($now);
        $this->clock = new FrozenClock($now);
        $this->app->instance(Clock::class, $this->clock);
    }

    /**
     * Cuenta registrada por API con un perfil propio publicado, completo y con grant activo.
     *
     * @return array{string, Profile, User}
     */
    protected function actor(string $email, ProfileCategory $category = ProfileCategory::TourismCompany): array
    {
        $token = (string) $this->postJson('/api/v1/auth/register', [
            'name' => 'Persona de Prueba',
            'email' => $email,
            'password' => 'Clave-segura-2026',
            'password_confirmation' => 'Clave-segura-2026',
        ])->assertCreated()->json('data.access_token');
        $owner = User::query()->where('email', $email)->firstOrFail();
        DB::table('auth_sessions')->where('user_id', $owner->id)->update(['expires_at' => '2030-01-01 00:00:00+00']);

        return [$token, $this->enabledProfile('Actor '.Str::before($email, '@'), $category, $owner), $owner];
    }

    /** @return array{User, string} */
    protected function registerAdmin(string $email): array
    {
        [$token, , $user] = $this->actor($email);
        $user->forceFill(['system_role' => User::ROLE_ADMIN])->save();

        return [$user, $token];
    }

    protected function enabledProfile(string $name, ProfileCategory $category = ProfileCategory::Guide, ?User $owner = null): Profile
    {
        $profile = Profile::factory()->create(array_merge([
            'category' => $category,
            'display_name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(8)),
            'status' => ProfileStatus::Published,
            'onboarding_step' => 4,
            'completed_at' => CarbonImmutable::parse('2026-10-01T12:00:00Z'),
        ], $owner === null ? [] : ['owner_user_id' => $owner->id]));
        AccessGrant::query()->create([
            'profile_id' => $profile->id,
            'status' => AccessGrantStatus::Active,
            'starts_at' => null,
            'expires_at' => null,
            'city_limit' => null,
            'monthly_contact_limit' => null,
            'gallery_enabled' => false,
            'version' => 1,
            'updated_by' => $profile->owner_user_id,
            'reason' => 'Habilitación de prueba',
        ]);

        return $profile;
    }

    /** Otro perfil de la misma cuenta que $sibling (mismo token). */
    protected function siblingProfile(Profile $sibling, string $name, ProfileCategory $category = ProfileCategory::Guide): Profile
    {
        return $this->enabledProfile($name, $category, User::query()->findOrFail($sibling->owner_user_id));
    }

    protected function createRequest(string $token, Profile $sender, Profile $target): string
    {
        return (string) $this->withToken($token)->postJson('/api/v1/profiles/'.$sender->public_id.'/contact-requests', [
            'target_profile_id' => $target->public_id,
            'service_description' => 'Guía bilingüe para trekking',
        ])->assertCreated()->json('data.id');
    }

    /** Solicitud creada por la empresa y aceptada por el receptor; devuelve su public_id. */
    protected function acceptedMatch(string $companyToken, Profile $company, string $receiverToken, Profile $receiver): string
    {
        $id = $this->createRequest($companyToken, $company, $receiver);
        $this->respond($receiverToken, $receiver, $id, 'accept')->assertOk();

        return $id;
    }

    /** @return TestResponse<Response> */
    protected function respond(string $token, Profile $actor, string $id, string $action): TestResponse
    {
        return $this->withToken($token)->postJson('/api/v1/profiles/'.$actor->public_id.'/contact-requests/'.$id.'/'.$action);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return TestResponse<Response>
     */
    protected function createReference(string $token, Profile $author, string $requestId, ?array $payload = null): TestResponse
    {
        return $this->withToken($token)->postJson(
            '/api/v1/profiles/'.$author->public_id.'/contact-requests/'.$requestId.'/references',
            $payload ?? ['body' => 'La coordinación de la propuesta fue clara y puntual.'],
        );
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return TestResponse<Response>
     */
    protected function setVisibility(string $token, Profile $profile, string $referenceId, string $visibility, array $extra = []): TestResponse
    {
        return $this->withToken($token)->patchJson(
            '/api/v1/profiles/'.$profile->public_id.'/references/'.$referenceId.'/visibility',
            ['visibility' => $visibility, ...$extra],
        );
    }

    /** @return TestResponse<Response> */
    protected function listReferences(string $token, Profile $profile, string $query = ''): TestResponse
    {
        return $this->withToken($token)->getJson('/api/v1/profiles/'.$profile->public_id.'/references'.$query);
    }

    /** @return TestResponse<Response> */
    protected function directoryProfile(string $token, Profile $viewer, Profile $target): TestResponse
    {
        return $this->withToken($token)->getJson('/api/v1/profiles/'.$viewer->public_id.'/directory/'.$target->public_id);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return TestResponse<Response>
     */
    protected function moderate(string $adminToken, string $referenceId, string $action, string $reason = 'Contenido contrario a las reglas', array $extra = []): TestResponse
    {
        return $this->withToken($adminToken)->postJson('/api/v1/admin/references/'.$referenceId.'/moderate', [
            'action' => $action,
            'reason' => $reason,
            ...$extra,
        ]);
    }
}
