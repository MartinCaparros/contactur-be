<?php

namespace Tests\Feature;

use App\Contracts\Clock;
use App\Enums\AccessGrantStatus;
use App\Enums\ProfileCategory;
use App\Enums\ProfileStatus;
use App\Models\AccessGrant;
use App\Models\AuditLog;
use App\Models\DocumentType;
use App\Models\File;
use App\Models\Profile;
use App\Models\ProfileDocument;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FrozenClock;
use Tests\TestCase;

final class AdminDocumentQueueTest extends TestCase
{
    use RefreshDatabase;

    private FrozenClock $clock;

    private User $admin;

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->seed(CatalogSeeder::class);
        $this->clock = new FrozenClock(CarbonImmutable::now('UTC')->startOfSecond());
        $this->app->instance(Clock::class, $this->clock);
        [$this->admin, $this->adminToken] = $this->registerAdmin('documents-queue-admin@example.test');
    }

    public function test_t11_document_queue_filters_by_status_category_and_document_type(): void
    {
        $guide = $this->profile(ProfileCategory::Guide);
        $driver = $this->profile(ProfileCategory::Driver);
        $company = $this->profile(ProfileCategory::TourismCompany);
        $guideLicense = $this->typeFor(ProfileCategory::Guide);
        $guideFirstAid = $this->extraType(ProfileCategory::Guide, 'guide_first_aid', 'Primeros auxilios');
        $driverLicense = $this->typeFor(ProfileCategory::Driver);
        $companyRegistration = $this->typeFor(ProfileCategory::TourismCompany);

        $guidePending = $this->document($guide, $guideLicense, ProfileDocument::STATUS_PENDING);
        $guideApproved = $this->document($guide, $guideFirstAid, ProfileDocument::STATUS_APPROVED);
        $driverPending = $this->document($driver, $driverLicense, ProfileDocument::STATUS_PENDING);
        $driverRejected = $this->document($driver, $driverLicense, ProfileDocument::STATUS_REJECTED);
        $companyPending = $this->document($company, $companyRegistration, ProfileDocument::STATUS_PENDING);
        $companyRejected = $this->document($company, $companyRegistration, ProfileDocument::STATUS_REJECTED);
        // Estados internos de revisión: nunca deben aparecer en la cola.
        $superseded = $this->document($guide, $guideLicense, ProfileDocument::STATUS_SUPERSEDED);
        $retired = $this->document($driver, $driverLicense, ProfileDocument::STATUS_RETIRED);

        // Sin `status` el default sigue siendo pending, igual que antes del filtro.
        $default = $this->queue('');
        $this->assertSame(
            [$guidePending->public_id, $driverPending->public_id, $companyPending->public_id],
            $this->ids($default),
        );
        $default->assertJsonPath('meta.total', 3)->assertJsonPath('meta.per_page', 20);
        foreach ($default->json('data') as $item) {
            $this->assertSame(ProfileDocument::STATUS_PENDING, $item['document']['status']);
        }
        $this->assertSame($this->ids($default), $this->ids($this->queue('?status=pending')));

        $approved = $this->queue('?status=approved');
        $this->assertSame([$guideApproved->public_id], $this->ids($approved));
        $approved->assertJsonPath('meta.total', 1);

        $rejected = $this->queue('?status=rejected');
        $this->assertSame([$driverRejected->public_id, $companyRejected->public_id], $this->ids($rejected));
        $rejected->assertJsonPath('meta.total', 2);

        $this->assertSame([$guidePending->public_id], $this->ids($this->queue('?category=guide')));
        $this->assertSame([$companyPending->public_id], $this->ids($this->queue('?category=tourism_company')));
        $this->assertSame(
            [$guidePending->public_id],
            $this->ids($this->queue('?document_type_id='.$guideLicense->id)),
        );
        $this->assertSame(
            [$guideApproved->public_id],
            $this->ids($this->queue('?status=approved&document_type_id='.$guideFirstAid->id)),
        );
        $this->assertSame(
            [$driverRejected->public_id],
            $this->ids($this->queue('?status=rejected&category=driver&document_type_id='.$driverLicense->id)),
        );
        $this->assertSame(
            [],
            $this->ids($this->queue('?status=approved&category=driver')),
        );
        $this->assertSame(
            [],
            $this->ids($this->queue('?category=guide&document_type_id='.$driverLicense->id)),
        );

        // superseded y retired no se alcanzan por ningún valor admitido del filtro.
        $reachable = [];
        foreach (['pending', 'approved', 'rejected'] as $status) {
            $reachable = [...$reachable, ...$this->ids($this->queue('?status='.$status))];
        }
        $this->assertNotContains($superseded->public_id, $reachable);
        $this->assertNotContains($retired->public_id, $reachable);
        $this->assertCount(6, $reachable);
        $this->assertSame(8, ProfileDocument::query()->count());
        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_t11_document_queue_paginates_within_a_filter_and_rejects_invalid_values(): void
    {
        $guide = $this->profile(ProfileCategory::Guide);
        $type = $this->typeFor(ProfileCategory::Guide);
        $first = $this->document($guide, $type, ProfileDocument::STATUS_REJECTED);
        $second = $this->document($guide, $type, ProfileDocument::STATUS_REJECTED);
        $third = $this->document($guide, $type, ProfileDocument::STATUS_REJECTED);
        $this->document($guide, $type, ProfileDocument::STATUS_PENDING);

        $page = $this->queue('?status=rejected&per_page=2')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.next_cursor', (string) $second->id);
        $this->assertSame([$first->public_id, $second->public_id], $this->ids($page));
        $next = $this->queue('?status=rejected&per_page=2&cursor='.$second->id)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.next_cursor', null);
        $this->assertSame([$third->public_id], $this->ids($next));

        $this->withToken($this->adminToken)
            ->getJson('/api/v1/admin/documents?status=superseded&category=hotel&document_type_id=0&estado=pending')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure([
                'error' => ['fields' => ['status', 'category', 'document_type_id', 'estado']],
            ]);
        $this->withToken($this->adminToken)
            ->getJson('/api/v1/admin/documents?status=retired')
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['fields' => ['status']]]);
    }

    public function test_t11_document_queue_never_exposes_storage_paths_disk_or_checksum(): void
    {
        $guide = $this->profile(ProfileCategory::Guide);
        $type = $this->typeFor(ProfileCategory::Guide);
        $pending = $this->document($guide, $type, ProfileDocument::STATUS_PENDING);
        $approved = $this->document($guide, $this->extraType(ProfileCategory::Guide, 'guide_insurance', 'Seguro'), ProfileDocument::STATUS_APPROVED);
        $files = File::query()->whereIn('id', [$pending->file_id, $approved->file_id])->get();

        foreach (['', '?status=pending', '?status=approved', '?status=rejected', '?category=guide', '?document_type_id='.$type->id] as $query) {
            $body = (string) $this->queue($query)->getContent();

            foreach (['private_path', 'checksum', 'disk', 'storage_deleted_at', 'owner_profile_id', 'uploader_user_id'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $body);
            }
            foreach ($files as $file) {
                $this->assertStringNotContainsString($file->private_path, $body);
                $this->assertStringNotContainsString((string) $file->checksum, $body);
            }
        }
    }

    public function test_t11_document_queue_evaluates_access_once_per_profile_not_once_per_document(): void
    {
        // tourism_company es la única categoría cuya evaluación consulta la base por
        // la cuota mensual de contacto, así que es la que delata una evaluación por
        // ítem. Cuatro documentos del mismo perfil deben costar lo mismo que uno.
        $company = $this->profile(ProfileCategory::TourismCompany);
        $this->grant($company);
        $type = $this->typeFor(ProfileCategory::TourismCompany);
        for ($index = 0; $index < 4; $index++) {
            $this->document($company, $type, ProfileDocument::STATUS_PENDING);
        }

        $single = $this->countQueries('/api/v1/admin/documents?per_page=1');
        $full = $this->countQueries('/api/v1/admin/documents?per_page=4');

        $this->assertGreaterThan(5, $single);
        $this->assertSame($single, $full);
        $this->assertSame(4, $this->queue('?per_page=4')->json('meta.total'));
    }

    private function countQueries(string $url): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->withToken($this->adminToken)->getJson($url)->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();
        DB::flushQueryLog();

        return $count;
    }

    /** @return TestResponse<Response> */
    private function queue(string $query): TestResponse
    {
        return $this->withToken($this->adminToken)
            ->getJson('/api/v1/admin/documents'.$query)
            ->assertOk();
    }

    /**
     * @param  TestResponse<Response>  $response
     * @return array<int, string>
     */
    private function ids(TestResponse $response): array
    {
        /** @var array<int, array<string, mixed>> $data */
        $data = $response->json('data');

        return collect($data)->pluck('document.id')->map(static fn (mixed $id): string => (string) $id)->all();
    }

    /** @return array{User, string} */
    private function registerAdmin(string $email): array
    {
        $data = $this->postJson('/api/v1/auth/register', [
            'name' => 'Administración de Prueba',
            'email' => $email,
            'password' => 'Clave-segura-2026',
            'password_confirmation' => 'Clave-segura-2026',
        ])->assertCreated()->json('data');
        $user = User::query()->where('email', $email)->firstOrFail();
        $user->forceFill(['system_role' => User::ROLE_ADMIN])->save();

        return [$user, $data['access_token']];
    }

    private function profile(ProfileCategory $category): Profile
    {
        return Profile::factory()->create([
            'category' => $category,
            'status' => ProfileStatus::Published,
            'completed_at' => $this->clock->now()->subDay(),
            'onboarding_step' => 4,
        ]);
    }

    private function grant(Profile $profile): AccessGrant
    {
        return AccessGrant::query()->create([
            'profile_id' => $profile->id,
            'status' => AccessGrantStatus::Active,
            'starts_at' => null,
            'expires_at' => null,
            'city_limit' => null,
            'monthly_contact_limit' => 10,
            'gallery_enabled' => false,
            'version' => 1,
            'updated_by' => $profile->owner_user_id,
            'reason' => 'Habilitación de prueba',
        ]);
    }

    private function typeFor(ProfileCategory $category): DocumentType
    {
        return DocumentType::query()->where('category', $category->value)->orderBy('id')->firstOrFail();
    }

    private function extraType(ProfileCategory $category, string $code, string $label): DocumentType
    {
        return DocumentType::query()->firstOrCreate(
            ['category' => $category->value, 'code' => $code],
            ['label' => $label, 'requires_expiration' => false, 'required' => false],
        );
    }

    private function document(Profile $profile, DocumentType $type, string $status): ProfileDocument
    {
        $file = File::factory()->create([
            'owner_profile_id' => $profile->id,
            'uploader_user_id' => $profile->owner_user_id,
        ]);
        $reviewed = in_array($status, [ProfileDocument::STATUS_APPROVED, ProfileDocument::STATUS_REJECTED], true);

        return ProfileDocument::query()->create([
            'profile_id' => $profile->id,
            'document_type_id' => $type->id,
            'file_id' => $file->id,
            'revision' => ProfileDocument::query()
                ->where('profile_id', $profile->id)
                ->where('document_type_id', $type->id)
                ->max('revision') + 1,
            'status' => $status,
            'valid_until' => $this->clock->now()->addYear()->toDateString(),
            'reviewed_by' => $reviewed ? $this->admin->id : null,
            'reviewed_at' => $reviewed ? $this->clock->now() : null,
            'rejection_reason' => $status === ProfileDocument::STATUS_REJECTED ? 'Documento ilegible.' : null,
        ]);
    }
}
