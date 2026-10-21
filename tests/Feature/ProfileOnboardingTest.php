<?php

namespace Tests\Feature;

use App\Enums\ProfileCategory;
use App\Enums\ProfileStatus;
use App\Models\City;
use App\Models\DocumentType;
use App\Models\File;
use App\Models\Language;
use App\Models\Profile;
use App\Models\ProfileDocument;
use App\Models\Service;
use App\Models\Specialty;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class ProfileOnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->seed(CatalogSeeder::class);
    }

    public function test_p06_catalogs_have_exact_categories_and_idempotent_seed_data(): void
    {
        [$user, $token] = $this->registerAccount('catalogs@example.test');

        $response = $this->withToken($token)
            ->getJson('/api/v1/catalogs?category=guide&per_page=3')
            ->assertOk()
            ->assertJsonCount(5, 'data.categories')
            ->assertJsonCount(3, 'data.cities')
            ->assertJsonPath('meta.per_page', 3);

        $this->assertSame([
            'tourism_company',
            'guide',
            'driver',
            'instructor',
            'transport_company',
        ], array_column($response->json('data.categories'), 'code'));
        $this->assertSame(['guide'], array_values(array_unique(array_column($response->json('data.document_types'), 'category'))));
        $this->assertSame(5, City::query()->count());
        $this->assertGreaterThanOrEqual(1, Language::query()->count());
        $this->assertGreaterThanOrEqual(1, Service::query()->count());
        $this->assertGreaterThanOrEqual(1, Specialty::query()->count());
        $this->assertSame(5, DocumentType::query()->where('required', true)->count());

        $this->seed(CatalogSeeder::class);
        $this->assertSame(5, City::query()->count());
        $this->assertSame(5, DocumentType::query()->where('required', true)->count());
        $this->assertSame($user->id, User::query()->whereKey($user->id)->value('id'));
    }

    public function test_p06_five_categories_resume_complete_with_terms_documents_and_no_grant(): void
    {
        [$user, $token] = $this->registerAccount('five-profiles@example.test');
        $city = City::query()->orderBy('id')->firstOrFail();
        $language = Language::query()->orderBy('id')->firstOrFail();
        $service = Service::query()->orderBy('id')->firstOrFail();
        $specialty = Specialty::query()->orderBy('id')->firstOrFail();
        $categories = ProfileCategory::cases();
        $profileIds = [];

        foreach ($categories as $index => $category) {
            $created = $this->withToken($token)
                ->postJson('/api/v1/profiles', [
                    'category' => $category->value,
                    'display_name' => 'Perfil '.$category->value,
                ])
                ->assertCreated()
                ->assertJsonPath('data.category', $category->value)
                ->assertJsonPath('data.status', 'draft')
                ->assertJsonPath('data.onboarding_step', 1)
                ->json('data');
            $profileIds[] = $created['id'];
            $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $created['id']);
            $this->assertArrayHasKey($this->categoryResponseKey($category), $created);
            $this->assertArrayNotHasKey($this->otherCompanyResponseKey($category), $created);

            $this->withToken($token)
                ->putJson('/api/v1/profiles/'.$created['id'].'/cities', ['city_ids' => [$city->id]])
                ->assertOk()
                ->assertJsonCount(1, 'data.cities');

            $payload = array_merge([
                'description' => 'Descripción persistente de '.$category->value,
                'contact_email' => $category->value.'@example.test',
                'phone' => '+54929010000'.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
                'primary_city_id' => $city->id,
                'language_ids' => [$language->id],
                'service_ids' => [$service->id],
            ], $this->categoryPayload($category, $specialty->id));

            $this->withToken($token)
                ->patchJson('/api/v1/profiles/'.$created['id'], $payload)
                ->assertOk()
                ->assertJsonPath('data.description', $payload['description'])
                ->assertJsonPath('data.contact_email', $payload['contact_email'])
                ->assertJsonPath('data.onboarding_step', 2);

            $resumed = $this->withToken($token)
                ->getJson('/api/v1/profiles/'.$created['id'])
                ->assertOk()
                ->assertJsonPath('data.description', $payload['description'])
                ->assertJsonPath('data.cities.0.id', $city->id)
                ->assertJsonPath('data.languages.0.id', $language->id)
                ->assertJsonPath('data.services.0.id', $service->id)
                ->json('data');
            $this->assertSame($payload['contact_email'], $resumed['contact_email']);

            if ($index === 0) {
                $this->withToken($token)
                    ->postJson('/api/v1/profiles/'.$created['id'].'/complete')
                    ->assertUnprocessable()
                    ->assertJsonPath('error.code', 'profile_incomplete')
                    ->assertJsonStructure(['error' => ['fields' => ['terms', 'documents']]]);
            }

            $this->withToken($token)
                ->postJson('/api/v1/profiles/'.$created['id'].'/terms', [
                    'version' => 'demo-v1',
                    'accepted' => true,
                ])
                ->assertCreated()
                ->assertJsonPath('data.profile_id', $created['id'])
                ->assertJsonPath('data.version', 'demo-v1');

            if ($index === 0) {
                $this->withToken($token)
                    ->postJson('/api/v1/profiles/'.$created['id'].'/complete')
                    ->assertUnprocessable()
                    ->assertJsonStructure(['error' => ['fields' => ['documents']]]);
            }

            $profile = Profile::query()->where('public_id', $created['id'])->firstOrFail();
            $this->attachPendingRequiredDocument($profile, $user);

            if ($index === 0) {
                $this->withToken($token)
                    ->postJson('/api/v1/profiles/'.$created['id'].'/complete')
                    ->assertUnprocessable()
                    ->assertJsonStructure(['error' => ['fields' => ['email']]]);
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            $this->withToken($token)
                ->postJson('/api/v1/profiles/'.$created['id'].'/complete')
                ->assertOk()
                ->assertJsonPath('data.status', 'published')
                ->assertJsonPath('data.onboarding_step', 4)
                ->assertJsonPath('data.verified', false)
                ->assertJson(fn ($json) => $json->whereType('data.completed_at', 'string')->etc());
        }

        $suspended = Profile::query()->where('public_id', $profileIds[0])->firstOrFail();
        $suspended->forceFill(['status' => ProfileStatus::Suspended])->save();
        $this->withToken($token)
            ->postJson('/api/v1/profiles/'.$profileIds[0].'/complete')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'profile_suspended');
        $this->assertSame(ProfileStatus::Suspended, $suspended->refresh()->status);

        $account = $this->withToken($token)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonCount(5, 'data.profiles')
            ->json('data.profiles');
        $this->assertEqualsCanonicalizing($profileIds, array_column($account, 'id'));
        $accessByProfile = array_column($account, 'access_state', 'id');
        $this->assertSame('suspended', $accessByProfile[$profileIds[0]]);
        foreach (array_slice($profileIds, 1) as $profileId) {
            $this->assertSame('inactive', $accessByProfile[$profileId]);
        }
        foreach ($account as $summary) {
            $this->assertArrayNotHasKey('contact_email', $summary);
            $this->assertFalse($summary['capabilities']['can_search']);
        }

        $grantCount = Schema::hasTable('access_grants') ? DB::table('access_grants')->count() : 0;
        $this->assertSame(0, $grantCount);
    }

    public function test_p06_rejects_wrong_terms_version_and_non_boolean_acceptance(): void
    {
        [, $token] = $this->registerAccount('terms-validation@example.test');
        $profileId = $this->createProfile($token, ProfileCategory::Guide);

        $this->withToken($token)
            ->postJson('/api/v1/profiles/'.$profileId.'/terms', ['version' => '2026-01', 'accepted' => true])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['fields' => ['version']]]);
        $this->withToken($token)
            ->postJson('/api/v1/profiles/'.$profileId.'/terms', ['version' => 'demo-v1', 'accepted' => 1])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['fields' => ['accepted']]]);
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

    private function createProfile(string $token, ProfileCategory $category): string
    {
        return $this->withToken($token)->postJson('/api/v1/profiles', [
            'category' => $category->value,
            'display_name' => 'Perfil de prueba',
        ])->assertCreated()->json('data.id');
    }

    /** @return array<string, mixed> */
    private function categoryPayload(ProfileCategory $category, int $specialtyId): array
    {
        return match ($category) {
            ProfileCategory::TourismCompany => ['legal_name' => 'Turismo Austral SA', 'years_active' => 8],
            ProfileCategory::Guide => [
                'education_summary' => 'Formación profesional de guía.',
                'qualifications' => [[
                    'title' => 'Primeros auxilios',
                    'institution' => 'Instituto Ejemplo',
                    'completed_on' => '2025-03-10',
                    'expires_on' => null,
                ]],
            ],
            ProfileCategory::Driver => [
                'license_type' => 'D2',
                'vehicle_type' => 'Minibús',
                'vehicle_capacity' => 15,
            ],
            ProfileCategory::Instructor => [
                'training_summary' => 'Formación técnica certificada.',
                'specialty_ids' => [$specialtyId],
                'qualifications' => [[
                    'title' => 'Instructor',
                    'institution' => 'Escuela Ejemplo',
                    'completed_on' => '2024-05-01',
                    'expires_on' => null,
                ]],
            ],
            ProfileCategory::TransportCompany => [
                'legal_name' => 'Transporte Austral SA',
                'fleet_description' => 'Flota para grupos pequeños.',
                'fleet_vehicles' => [[
                    'vehicle_type' => 'Van',
                    'quantity' => 2,
                    'capacity' => 12,
                ]],
            ],
        };
    }

    private function categoryResponseKey(ProfileCategory $category): string
    {
        return match ($category) {
            ProfileCategory::TourismCompany => 'company',
            ProfileCategory::Guide => 'guide',
            ProfileCategory::Driver => 'driver',
            ProfileCategory::Instructor => 'instructor',
            ProfileCategory::TransportCompany => 'transport_company',
        };
    }

    private function otherCompanyResponseKey(ProfileCategory $category): string
    {
        return $category === ProfileCategory::TourismCompany ? 'transport_company' : 'company';
    }

    private function attachPendingRequiredDocument(Profile $profile, User $user): void
    {
        $type = DocumentType::query()
            ->where('category', $profile->category->value)
            ->where('required', true)
            ->firstOrFail();
        $file = File::factory()->create([
            'owner_profile_id' => $profile->id,
            'uploader_user_id' => $user->id,
        ]);
        ProfileDocument::factory()->create([
            'profile_id' => $profile->id,
            'document_type_id' => $type->id,
            'file_id' => $file->id,
            'valid_until' => $type->requires_expiration ? now()->addYear()->toDateString() : null,
            'status' => ProfileDocument::STATUS_PENDING,
        ]);
    }
}
