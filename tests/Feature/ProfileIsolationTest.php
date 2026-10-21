<?php

namespace Tests\Feature;

use App\Enums\ProfileCategory;
use App\Models\City;
use App\Models\Language;
use App\Models\Profile;
use App\Models\ProfileContact;
use App\Models\Service;
use App\Models\Specialty;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

final class ProfileIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->seed(CatalogSeeder::class);
    }

    public function test_p07_multiple_profiles_and_foreign_ulid_are_isolated_without_contact_leak(): void
    {
        [, $tokenA] = $this->registerAccount('account-a@example.test');
        [, $tokenB] = $this->registerAccount('account-b@example.test');
        $guideA = $this->createProfile($tokenA, ProfileCategory::Guide, 'Guía A');
        $driverA = $this->createProfile($tokenA, ProfileCategory::Driver, 'Chofer A');
        $transportB = $this->createProfile($tokenB, ProfileCategory::TransportCompany, 'Transporte B');

        $this->withToken($tokenA)->patchJson('/api/v1/profiles/'.$guideA, [
            'contact_email' => 'guide-private@example.test',
            'phone' => '+5491111111111',
        ])->assertOk();
        $this->withToken($tokenA)->patchJson('/api/v1/profiles/'.$driverA, [
            'contact_email' => 'driver-private@example.test',
            'phone' => '+5492222222222',
        ])->assertOk();
        $this->withToken($tokenB)->patchJson('/api/v1/profiles/'.$transportB, [
            'contact_email' => 'transport-private@example.test',
            'phone' => '+5493333333333',
        ])->assertOk();

        $this->withToken($tokenA)
            ->getJson('/api/v1/profiles/'.$guideA)
            ->assertOk()
            ->assertJsonPath('data.contact_email', 'guide-private@example.test');
        $this->withToken($tokenA)
            ->getJson('/api/v1/profiles/'.$driverA)
            ->assertOk()
            ->assertJsonPath('data.contact_email', 'driver-private@example.test');

        $foreignRead = $this->withToken($tokenA)
            ->getJson('/api/v1/profiles/'.$transportB)
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
        $this->assertStringNotContainsString('transport-private@example.test', $foreignRead->getContent());
        $this->assertStringNotContainsString('+5493333333333', $foreignRead->getContent());
        $this->assertStringNotContainsString('Transporte B', $foreignRead->getContent());

        $foreignWrite = $this->withToken($tokenA)
            ->patchJson('/api/v1/profiles/'.$transportB, ['contact_email' => 'stolen@example.test'])
            ->assertNotFound();
        $this->assertStringNotContainsString('transport-private@example.test', $foreignWrite->getContent());
        $transportProfile = Profile::query()->where('public_id', $transportB)->firstOrFail();
        $this->assertSame(
            'transport-private@example.test',
            ProfileContact::query()->where('profile_id', $transportProfile->id)->value('contact_email'),
        );

        $profilesA = $this->withToken($tokenA)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonCount(2, 'data.profiles')
            ->json('data.profiles');
        $this->assertEqualsCanonicalizing([$guideA, $driverA], array_column($profilesA, 'id'));
        $this->assertNotContains($transportB, array_column($profilesA, 'id'));
        $this->assertStringNotContainsString('private@example.test', json_encode($profilesA, JSON_THROW_ON_ERROR));

        $profilesB = $this->withToken($tokenB)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonCount(1, 'data.profiles')
            ->json('data.profiles');
        $this->assertSame([$transportB], array_column($profilesB, 'id'));
    }

    public function test_collections_replace_when_present_remain_when_absent_and_fail_atomically(): void
    {
        [, $token] = $this->registerAccount('collections@example.test');
        $profileId = $this->createProfile($token, ProfileCategory::Instructor, 'Instructora Colecciones');
        $cities = City::query()->orderBy('id')->take(2)->get();
        $languages = Language::query()->orderBy('id')->take(2)->get();
        $services = Service::query()->orderBy('id')->take(2)->get();
        $specialties = Specialty::query()->orderBy('id')->take(2)->get();

        $this->withToken($token)->putJson('/api/v1/profiles/'.$profileId.'/cities', [
            'city_ids' => $cities->pluck('id')->all(),
        ])->assertOk()->assertJsonCount(2, 'data.cities');

        $qualification = [[
            'title' => 'Rescate',
            'institution' => 'Instituto Uno',
            'completed_on' => '2024-01-10',
            'expires_on' => null,
        ]];
        $this->withToken($token)->patchJson('/api/v1/profiles/'.$profileId, [
            'description' => 'Estado inicial',
            'primary_city_id' => $cities[0]->id,
            'language_ids' => $languages->pluck('id')->all(),
            'service_ids' => $services->pluck('id')->all(),
            'specialty_ids' => $specialties->pluck('id')->all(),
            'qualifications' => $qualification,
        ])->assertOk()
            ->assertJsonCount(2, 'data.languages')
            ->assertJsonCount(2, 'data.services')
            ->assertJsonCount(2, 'data.specialties')
            ->assertJsonCount(1, 'data.qualifications');

        $this->withToken($token)->patchJson('/api/v1/profiles/'.$profileId, [
            'description' => 'Sólo cambia este escalar',
        ])->assertOk()
            ->assertJsonCount(2, 'data.languages')
            ->assertJsonCount(2, 'data.services')
            ->assertJsonCount(2, 'data.specialties')
            ->assertJsonCount(1, 'data.qualifications');

        $this->withToken($token)->patchJson('/api/v1/profiles/'.$profileId, [
            'language_ids' => [$languages[1]->id],
            'service_ids' => [],
            'specialty_ids' => [$specialties[1]->id],
            'qualifications' => [],
        ])->assertOk()
            ->assertJsonCount(1, 'data.languages')
            ->assertJsonPath('data.languages.0.id', $languages[1]->id)
            ->assertJsonCount(0, 'data.services')
            ->assertJsonCount(1, 'data.specialties')
            ->assertJsonCount(0, 'data.qualifications');

        $this->withToken($token)->patchJson('/api/v1/profiles/'.$profileId, [
            'language_ids' => [$languages[0]->id],
            'service_ids' => [PHP_INT_MAX],
        ])->assertUnprocessable();
        $this->withToken($token)->getJson('/api/v1/profiles/'.$profileId)
            ->assertOk()
            ->assertJsonCount(1, 'data.languages')
            ->assertJsonPath('data.languages.0.id', $languages[1]->id);

        $this->withToken($token)->putJson('/api/v1/profiles/'.$profileId.'/cities', [
            'city_ids' => [$cities[1]->id],
        ])->assertUnprocessable()
            ->assertJsonStructure(['error' => ['fields' => ['city_ids']]]);
    }

    public function test_category_foreign_privileged_and_case_variant_fields_are_rejected(): void
    {
        [, $token] = $this->registerAccount('whitelist@example.test');
        $instructor = $this->createProfile($token, ProfileCategory::Instructor, 'Instructora Segura');

        foreach (['category', 'status', 'completed_at', 'owner_user_id', 'legal_name', 'fleet_vehicles', 'displayName', 'Legal_Name'] as $field) {
            $value = match ($field) {
                'category' => 'transport_company',
                'status' => 'published',
                'completed_at' => now()->toISOString(),
                'owner_user_id' => 999,
                'fleet_vehicles' => [],
                default => 'valor indebido',
            };
            $this->withToken($token)
                ->patchJson('/api/v1/profiles/'.$instructor, [$field => $value])
                ->assertUnprocessable()
                ->assertJsonStructure(['error' => ['fields' => [$field]]]);
        }

        $this->assertSame(
            ProfileCategory::Instructor,
            Profile::query()->where('public_id', $instructor)->firstOrFail()->category,
        );
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

    private function createProfile(
        string $token,
        ProfileCategory $category,
        string $displayName,
    ): string {
        return $this->withToken($token)->postJson('/api/v1/profiles', [
            'category' => $category->value,
            'display_name' => $displayName,
        ])->assertCreated()->json('data.id');
    }
}
