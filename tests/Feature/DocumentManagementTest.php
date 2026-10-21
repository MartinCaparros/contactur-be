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
use App\Services\DocumentService;
use App\Services\ProfileService;
use App\Support\UploadInspector;
use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FrozenClock;
use Tests\Support\SyntheticUploads;
use Tests\TestCase;
use Throwable;

final class DocumentManagementTest extends TestCase
{
    use RefreshDatabase;

    private FrozenClock $clock;

    private CarbonImmutable $initialNow;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Storage::fake('local');
        Storage::fake('public');
        $this->seed(CatalogSeeder::class);
        $this->initialNow = CarbonImmutable::now('UTC')->subMinute()->startOfSecond();
        $this->clock = new FrozenClock($this->initialNow);
        $this->app->instance(Clock::class, $this->clock);
    }

    protected function tearDown(): void
    {
        SyntheticUploads::cleanup();
        parent::tearDown();
    }

    public function test_p08_valid_pdf_upload_is_private_and_matches_the_public_shape(): void
    {
        [$owner, $token] = $this->registerAccount('document-owner@example.test');
        $profile = $this->createProfile($token, 'Guía Documental');
        $type = $this->documentType($profile);
        $contents = SyntheticUploads::pdf();

        $response = $this->postDocument(
            $token,
            $profile,
            $type,
            SyntheticUploads::file('credencial-prueba.pdf', 'application/pdf', $contents),
            ['registration_number' => 'GUIA-TEST-001'],
        )->assertCreated();

        $data = $response->json('data');
        $this->assertSame([
            'id',
            'profile_id',
            'document_type',
            'original_filename',
            'mime_type',
            'size_bytes',
            'registration_number',
            'valid_until',
            'status',
            'reviewed_at',
            'rejection_reason',
            'uploaded_at',
            'is_currently_valid',
        ], array_keys($data));
        $this->assertSame([
            'id',
            'code',
            'category',
            'label',
            'requires_expiration',
            'required',
            'jurisdiction',
        ], array_keys($data['document_type']));
        $this->assertSame($profile->public_id, $data['profile_id']);
        $this->assertSame($type->id, $data['document_type']['id']);
        $this->assertSame('application/pdf', $data['mime_type']);
        $this->assertSame(strlen($contents), $data['size_bytes']);
        $this->assertSame(ProfileDocument::STATUS_PENDING, $data['status']);
        $this->assertFalse($data['is_currently_valid']);

        $document = ProfileDocument::query()->where('public_id', $data['id'])->firstOrFail();
        $file = $document->file;
        $this->assertSame($owner->id, $file->uploader_user_id);
        $this->assertSame($profile->id, $file->owner_profile_id);
        $this->assertSame('local', $file->disk);
        $this->assertSame(hash('sha256', $contents), $file->checksum);
        Storage::disk('local')->assertExists($file->private_path);
        foreach (['private_path', 'path', 'disk', 'checksum', $file->private_path, $file->checksum] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $response->getContent());
        }
    }

    public function test_p08_false_mime_extension_svg_executable_polyglot_empty_and_exact_byte_limit_are_rejected(): void
    {
        [, $token] = $this->registerAccount('document-validation@example.test');
        $profile = $this->createProfile($token, 'Guía Validaciones');
        $type = $this->documentType($profile);
        $invalidFiles = [
            SyntheticUploads::file('mime-falso.pdf', 'image/png', SyntheticUploads::pdf()),
            SyntheticUploads::file('extension-falsa.png', 'application/pdf', SyntheticUploads::pdf()),
            SyntheticUploads::file('vector.svg', 'image/svg+xml', '<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1"/></svg>'),
            SyntheticUploads::file('ejecutable.pdf', 'application/pdf', "MZ\x90\x00programa"),
            SyntheticUploads::file('poliglot.pdf', 'application/pdf', SyntheticUploads::pdf("<?php echo 'x';")),
            SyntheticUploads::file('vacio.pdf', 'application/pdf', ''),
        ];

        foreach ($invalidFiles as $invalidFile) {
            $this->postDocument($token, $profile, $type, $invalidFile)
                ->assertUnprocessable()
                ->assertJsonStructure(['error' => ['fields' => ['file']]]);
        }

        config()->set('contactur.uploads.documents_max_bytes', 90);
        $this->postDocument(
            $token,
            $profile,
            $type,
            SyntheticUploads::file('demasiado-grande.pdf', 'application/pdf', SyntheticUploads::pdf(str_repeat('A', 32))),
        )->assertUnprocessable()
            ->assertJsonStructure(['error' => ['fields' => ['file']]]);

        $this->assertSame(0, ProfileDocument::query()->count());
        $this->assertSame(0, File::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_p08_foreign_profile_and_document_ulids_are_404_without_information_leakage(): void
    {
        [, $tokenA] = $this->registerAccount('document-a@example.test');
        [, $tokenB] = $this->registerAccount('document-b@example.test');
        $profileA = $this->createProfile($tokenA, 'Guía Propia');
        $profileB = $this->createProfile($tokenB, 'Guía Privada Ajena');
        $typeB = $this->documentType($profileB);
        $foreignDocumentId = $this->postDocument(
            $tokenB,
            $profileB,
            $typeB,
            SyntheticUploads::file('documento-ajeno.pdf', 'application/pdf', SyntheticUploads::pdf()),
        )->assertCreated()->json('data.id');

        $responses = [
            $this->withToken($tokenA)->getJson('/api/v1/profiles/'.$profileB->public_id.'/documents'),
            $this->postDocument(
                $tokenA,
                $profileB,
                $typeB,
                SyntheticUploads::file('intento-ajeno.pdf', 'application/pdf', SyntheticUploads::pdf()),
            ),
            $this->withToken($tokenA)->getJson('/api/v1/profiles/'.$profileA->public_id.'/documents/'.$foreignDocumentId.'/download'),
            $this->withToken($tokenA)->deleteJson('/api/v1/profiles/'.$profileA->public_id.'/documents/'.$foreignDocumentId),
        ];

        foreach ($responses as $response) {
            $response->assertNotFound()->assertJsonPath('error.code', 'not_found');
            $this->assertStringNotContainsString('Guía Privada Ajena', $response->getContent());
            $this->assertStringNotContainsString('documento-ajeno.pdf', $response->getContent());
        }
        $this->assertSame(1, ProfileDocument::query()->count());
    }

    public function test_p08_inactive_and_expired_owners_can_manage_documents_but_suspended_profiles_cannot_mutate(): void
    {
        [$owner, $token] = $this->registerAccount('document-access@example.test');
        $inactive = $this->createProfile($token, 'Guía Sin Grant');
        $expired = $this->createProfile($token, 'Guía Grant Vencido');
        $statusSuspended = $this->createProfile($token, 'Guía Perfil Suspendido');
        $grantSuspended = $this->createProfile($token, 'Guía Grant Suspendido');
        $this->grant($expired, $owner, AccessGrantStatus::Active, false, $this->clock->now()->subMinute());

        foreach ([$inactive, $expired, $statusSuspended, $grantSuspended] as $profile) {
            $this->postDocument(
                $token,
                $profile,
                $this->documentType($profile),
                SyntheticUploads::file('documento-'.$profile->id.'.pdf', 'application/pdf', SyntheticUploads::pdf()),
            )->assertCreated();
        }

        $statusSuspended->forceFill(['status' => ProfileStatus::Suspended])->save();
        $this->grant($grantSuspended, $owner, AccessGrantStatus::Suspended);

        foreach ([$statusSuspended, $grantSuspended] as $profile) {
            $document = $profile->documents()->firstOrFail();
            $this->postDocument(
                $token,
                $profile,
                $this->documentType($profile),
                SyntheticUploads::file('bloqueado-'.$profile->id.'.pdf', 'application/pdf', SyntheticUploads::pdf()),
            )->assertForbidden()->assertJsonPath('error.code', 'profile_suspended');
            $this->withToken($token)
                ->deleteJson('/api/v1/profiles/'.$profile->public_id.'/documents/'.$document->public_id)
                ->assertForbidden()
                ->assertJsonPath('error.code', 'profile_suspended');
            $this->withToken($token)
                ->getJson('/api/v1/profiles/'.$profile->public_id.'/documents')
                ->assertOk()
                ->assertJsonCount(1, 'data');
        }
        $this->assertSame(4, ProfileDocument::query()->count());
    }

    public function test_p08_download_is_owner_or_admin_only_with_hardened_headers_and_hides_retired_or_superseded_files(): void
    {
        [, $ownerToken] = $this->registerAccount('download-owner@example.test');
        [$admin, $adminToken] = $this->registerAccount('download-admin@example.test');
        [, $thirdToken] = $this->registerAccount('download-third@example.test');
        $admin->forceFill(['system_role' => User::ROLE_ADMIN])->save();
        $profile = $this->createProfile($ownerToken, 'Guía Descargas');
        $type = $this->documentType($profile);
        $contents = SyntheticUploads::pdf();
        $firstId = $this->postDocument(
            $ownerToken,
            $profile,
            $type,
            SyntheticUploads::file('credencial-descarga.pdf', 'application/pdf', $contents),
        )->assertCreated()->json('data.id');
        $downloadUri = '/api/v1/profiles/'.$profile->public_id.'/documents/'.$firstId.'/download';

        foreach ([$ownerToken, $adminToken] as $authorizedToken) {
            $download = $this->withToken($authorizedToken)->get($downloadUri)->assertOk();
            $this->assertSame('application/pdf', $download->headers->get('content-type'));
            $this->assertStringContainsString('private', (string) $download->headers->get('cache-control'));
            $this->assertStringContainsString('no-store', (string) $download->headers->get('cache-control'));
            $this->assertSame('nosniff', $download->headers->get('x-content-type-options'));
            $this->assertStringContainsString('attachment', (string) $download->headers->get('content-disposition'));
            $this->assertStringContainsString('credencial-descarga.pdf', (string) $download->headers->get('content-disposition'));
            $this->assertSame($contents, $download->streamedContent());
        }

        $this->flushHeaders()->getJson($downloadUri)->assertUnauthorized()->assertJsonPath('error.code', 'unauthorized');
        $this->withToken($thirdToken)->getJson($downloadUri)->assertNotFound()->assertJsonPath('error.code', 'not_found');

        $secondId = $this->postDocument(
            $ownerToken,
            $profile,
            $type,
            SyntheticUploads::file('credencial-reemplazo.pdf', 'application/pdf', SyntheticUploads::pdf('revision-2')),
            ['replaces_document_id' => $firstId],
        )->assertCreated()->json('data.id');
        $this->withToken($ownerToken)->getJson($downloadUri)->assertNotFound();

        $secondUri = '/api/v1/profiles/'.$profile->public_id.'/documents/'.$secondId;
        $this->withToken($ownerToken)->deleteJson($secondUri)->assertNoContent();
        $this->withToken($ownerToken)->getJson($secondUri.'/download')->assertNotFound();
    }

    public function test_p08_replacement_id_conflicts_and_stale_writers_do_not_create_extra_revisions(): void
    {
        [, $token] = $this->registerAccount('replacement-conflict@example.test');
        $profile = $this->createProfile($token, 'Guía Reemplazos');
        $type = $this->documentType($profile);

        $this->postDocument(
            $token,
            $profile,
            $type,
            SyntheticUploads::file('malformado.pdf', 'application/pdf', SyntheticUploads::pdf()),
            ['replaces_document_id' => 'not-a-ulid'],
        )->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['replaces_document_id']]]);
        $this->postDocument(
            $token,
            $profile,
            $type,
            SyntheticUploads::file('inexistente.pdf', 'application/pdf', SyntheticUploads::pdf()),
            ['replaces_document_id' => (string) Str::ulid()],
        )->assertConflict()->assertJsonPath('error.code', 'document_replacement_conflict');

        $firstId = $this->postDocument(
            $token,
            $profile,
            $type,
            SyntheticUploads::file('revision-1.pdf', 'application/pdf', SyntheticUploads::pdf('one')),
        )->assertCreated()->json('data.id');
        $this->postDocument(
            $token,
            $profile,
            $type,
            SyntheticUploads::file('revision-2.pdf', 'application/pdf', SyntheticUploads::pdf('two')),
            ['replaces_document_id' => $firstId],
        )->assertCreated()->assertJsonPath('data.status', ProfileDocument::STATUS_PENDING);
        $storedFiles = File::query()->count();

        $this->postDocument(
            $token,
            $profile,
            $type,
            SyntheticUploads::file('revision-stale.pdf', 'application/pdf', SyntheticUploads::pdf('stale')),
            ['replaces_document_id' => $firstId],
        )->assertConflict()->assertJsonPath('error.code', 'document_replacement_conflict');

        $this->assertSame(2, ProfileDocument::query()->count());
        $this->assertSame($storedFiles, File::query()->count());
        $this->assertSame(1, ProfileDocument::query()->where('status', ProfileDocument::STATUS_SUPERSEDED)->count());
        $this->assertSame(1, ProfileDocument::query()->where('status', ProfileDocument::STATUS_PENDING)->count());
    }

    public function test_p09_pending_queue_review_rules_badge_and_audit_are_admin_only_and_safe(): void
    {
        [, $ownerToken] = $this->registerAccount('review-owner@example.test');
        [$admin, $adminToken] = $this->registerAccount('review-admin@example.test');
        $admin->forceFill(['system_role' => User::ROLE_ADMIN])->save();
        $profile = $this->createProfile($ownerToken, 'Guía Revisión');
        $this->withToken($ownerToken)->patchJson('/api/v1/profiles/'.$profile->public_id, [
            'contact_email' => 'contacto-privado@example.test',
            'phone' => '+5491112345678',
        ])->assertOk();
        $documentId = $this->postDocument(
            $ownerToken,
            $profile,
            $this->documentType($profile),
            SyntheticUploads::file('pendiente.pdf', 'application/pdf', SyntheticUploads::pdf()),
        )->assertCreated()->json('data.id');

        $this->withToken($ownerToken)
            ->getJson('/api/v1/profiles/'.$profile->public_id)
            ->assertOk()
            ->assertJsonPath('data.verified', false);
        $this->withToken($ownerToken)->getJson('/api/v1/admin/documents')->assertForbidden();
        $this->withToken($ownerToken)
            ->postJson('/api/v1/admin/documents/'.$documentId.'/review', ['action' => 'approve'])
            ->assertForbidden();

        $queue = $this->withToken($adminToken)->getJson('/api/v1/admin/documents')->assertOk();
        $queue->assertJsonCount(1, 'data')->assertJsonPath('data.0.document.id', $documentId);
        foreach (['private_path', 'path', 'disk', 'checksum'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $queue->getContent());
        }

        $this->withToken($adminToken)->postJson('/api/v1/admin/documents/'.$documentId.'/review', [
            'action' => 'approve',
            'rejection_reason' => 'No corresponde al aprobar.',
        ])->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['rejection_reason']]]);
        $this->withToken($adminToken)->postJson('/api/v1/admin/documents/'.$documentId.'/review', [
            'action' => 'reject',
        ])->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['rejection_reason']]]);
        $this->assertSame(ProfileDocument::STATUS_PENDING, ProfileDocument::query()->where('public_id', $documentId)->value('status'));

        $this->withToken($adminToken)->postJson('/api/v1/admin/documents/'.$documentId.'/review', [
            'action' => 'approve',
        ])->assertOk()
            ->assertJsonPath('data.status', ProfileDocument::STATUS_APPROVED)
            ->assertJsonPath('data.rejection_reason', null)
            ->assertJsonPath('data.is_currently_valid', true);
        $this->withToken($ownerToken)
            ->getJson('/api/v1/profiles/'.$profile->public_id)
            ->assertOk()
            ->assertJsonPath('data.verified', true);

        $rejectedProfile = $this->createProfile($ownerToken, 'Guía Rechazada');
        $rejectedId = $this->postDocument(
            $ownerToken,
            $rejectedProfile,
            $this->documentType($rejectedProfile),
            SyntheticUploads::file('rechazado.pdf', 'application/pdf', SyntheticUploads::pdf('reject')),
        )->assertCreated()->json('data.id');
        $this->withToken($adminToken)->postJson('/api/v1/admin/documents/'.$rejectedId.'/review', [
            'action' => 'reject',
            'rejection_reason' => 'El documento sintético no coincide con la categoría.',
        ])->assertOk()
            ->assertJsonPath('data.status', ProfileDocument::STATUS_REJECTED)
            ->assertJsonPath('data.rejection_reason', 'El documento sintético no coincide con la categoría.');
        $this->withToken($ownerToken)
            ->getJson('/api/v1/profiles/'.$rejectedProfile->public_id)
            ->assertOk()
            ->assertJsonPath('data.verified', false);

        $auditResponse = $this->withToken($adminToken)->getJson('/api/v1/admin/audit-logs')->assertOk();
        $this->assertGreaterThanOrEqual(4, AuditLog::query()->count());
        $audit = json_encode([
            'database' => AuditLog::query()->orderBy('id')->get()->toArray(),
            'response' => $auditResponse->json(),
        ], JSON_THROW_ON_ERROR);
        foreach ([
            'private_path',
            'checksum',
            'documents/',
            'contacto-privado@example.test',
            '+5491112345678',
            'access_token',
            'refresh_token',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $audit);
        }
    }

    public function test_p09_replacement_preserves_review_metadata_supersedes_internally_and_removes_badge(): void
    {
        [, $ownerToken] = $this->registerAccount('revision-owner@example.test');
        [$admin, $adminToken] = $this->registerAccount('revision-admin@example.test');
        $admin->forceFill(['system_role' => User::ROLE_ADMIN])->save();
        $profile = $this->createProfile($ownerToken, 'Guía Revisiones');
        $type = $this->documentType($profile);
        $firstId = $this->postDocument(
            $ownerToken,
            $profile,
            $type,
            SyntheticUploads::file('revision-aprobada.pdf', 'application/pdf', SyntheticUploads::pdf('approved')),
        )->assertCreated()->json('data.id');
        $this->withToken($adminToken)->postJson('/api/v1/admin/documents/'.$firstId.'/review', [
            'action' => 'approve',
        ])->assertOk();
        $firstBefore = ProfileDocument::query()->where('public_id', $firstId)->firstOrFail();
        $reviewedBy = $firstBefore->reviewed_by;
        $reviewedAt = $firstBefore->reviewed_at;
        $oldPath = $firstBefore->file->private_path;
        $this->withToken($ownerToken)
            ->getJson('/api/v1/profiles/'.$profile->public_id)
            ->assertJsonPath('data.verified', true);

        $secondId = $this->postDocument(
            $ownerToken,
            $profile,
            $type,
            SyntheticUploads::file('revision-pendiente.pdf', 'application/pdf', SyntheticUploads::pdf('pending')),
            ['replaces_document_id' => $firstId],
        )->assertCreated()
            ->assertJsonPath('data.status', ProfileDocument::STATUS_PENDING)
            ->json('data.id');

        $firstAfter = ProfileDocument::query()->where('public_id', $firstId)->with('file')->firstOrFail();
        $second = ProfileDocument::query()->where('public_id', $secondId)->firstOrFail();
        $this->assertSame(ProfileDocument::STATUS_SUPERSEDED, $firstAfter->status);
        $this->assertSame(1, $firstAfter->revision);
        $this->assertSame($reviewedBy, $firstAfter->reviewed_by);
        $this->assertTrue($reviewedAt?->equalTo($firstAfter->reviewed_at) ?? false);
        $this->assertNull($firstAfter->rejection_reason);
        $this->assertNotNull($firstAfter->file->storage_deleted_at);
        Storage::disk('local')->assertMissing($oldPath);
        $this->assertSame(2, $second->revision);
        $this->assertSame(ProfileDocument::STATUS_PENDING, $second->status);
        $this->assertNull($second->reviewed_at);
        $this->assertNull($second->reviewed_by);
        $this->withToken($ownerToken)
            ->getJson('/api/v1/profiles/'.$profile->public_id)
            ->assertOk()
            ->assertJsonPath('data.verified', false);
        $this->withToken($ownerToken)
            ->getJson('/api/v1/profiles/'.$profile->public_id.'/documents')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $secondId);
    }

    public function test_p09_frozen_clock_expiry_and_owner_retirement_remove_the_badge(): void
    {
        [$owner, $ownerToken] = $this->registerAccount('expiry-owner@example.test');
        [$admin, $adminToken] = $this->registerAccount('expiry-admin@example.test');
        $admin->forceFill(['system_role' => User::ROLE_ADMIN])->save();
        $expiringProfile = $this->createProfile($ownerToken, 'Guía Caducidad');
        $expiringId = $this->postDocument(
            $ownerToken,
            $expiringProfile,
            $this->documentType($expiringProfile),
            SyntheticUploads::file('por-caducar.pdf', 'application/pdf', SyntheticUploads::pdf()),
            ['valid_until' => $this->initialNow->addDay()->toDateString()],
        )->assertCreated()->json('data.id');
        $this->withToken($adminToken)->postJson('/api/v1/admin/documents/'.$expiringId.'/review', [
            'action' => 'approve',
        ])->assertOk();
        $this->withToken($ownerToken)
            ->getJson('/api/v1/profiles/'.$expiringProfile->public_id)
            ->assertJsonPath('data.verified', true);

        $this->clock->set($this->initialNow->addDays(2));
        $expiredProfile = $this->app->make(ProfileService::class)->show($owner, $expiringProfile->public_id);
        $this->assertFalse((bool) $expiredProfile->getAttribute('verified'));
        $this->clock->set($this->initialNow);

        $retiredProfile = $this->createProfile($ownerToken, 'Guía Retiro');
        $retiredId = $this->postDocument(
            $ownerToken,
            $retiredProfile,
            $this->documentType($retiredProfile),
            SyntheticUploads::file('a-retirar.pdf', 'application/pdf', SyntheticUploads::pdf('retire')),
        )->assertCreated()->json('data.id');
        $this->withToken($adminToken)->postJson('/api/v1/admin/documents/'.$retiredId.'/review', [
            'action' => 'approve',
        ])->assertOk();
        $this->withToken($ownerToken)
            ->getJson('/api/v1/profiles/'.$retiredProfile->public_id)
            ->assertJsonPath('data.verified', true);
        $this->withToken($ownerToken)
            ->deleteJson('/api/v1/profiles/'.$retiredProfile->public_id.'/documents/'.$retiredId)
            ->assertNoContent();
        $this->withToken($ownerToken)
            ->getJson('/api/v1/profiles/'.$retiredProfile->public_id)
            ->assertJsonPath('data.verified', false);
        $this->assertSame(
            ProfileDocument::STATUS_RETIRED,
            ProfileDocument::query()->where('public_id', $retiredId)->value('status'),
        );
    }

    public function test_p08_database_failure_after_storage_rolls_back_database_and_binary(): void
    {
        [$owner, $token] = $this->registerAccount('rollback-owner@example.test');
        $profile = $this->createProfile($token, 'Guía Rollback');
        $type = $this->documentType($profile);
        $validated = $this->app->make(UploadInspector::class)->inspect(
            SyntheticUploads::file('rollback.pdf', 'application/pdf', SyntheticUploads::pdf()),
            UploadInspector::DOCUMENT,
        );

        try {
            $this->app->make(DocumentService::class)->upload(
                actor: $owner,
                profile: $profile,
                type: $type,
                upload: $validated,
                registrationNumber: str_repeat('R', 161),
                validUntil: $this->clock->now()->addMonth(),
                replacesDocumentId: null,
                requestId: (string) Str::ulid(),
            );
            $this->fail('La escritura documental debía fallar después de guardar el binario.');
        } catch (Throwable $exception) {
            $this->assertInstanceOf(QueryException::class, $exception);
        }

        $this->assertSame(0, File::query()->count());
        $this->assertSame(0, ProfileDocument::query()->count());
        $this->assertSame(0, AuditLog::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
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

    private function createProfile(string $token, string $displayName): Profile
    {
        $publicId = $this->withToken($token)->postJson('/api/v1/profiles', [
            'category' => ProfileCategory::Guide->value,
            'display_name' => $displayName,
        ])->assertCreated()->json('data.id');

        return Profile::query()->where('public_id', $publicId)->firstOrFail();
    }

    private function documentType(Profile $profile): DocumentType
    {
        return DocumentType::query()->where('category', $profile->category->value)->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return TestResponse<Response>
     */
    private function postDocument(
        string $token,
        Profile $profile,
        DocumentType $type,
        UploadedFile $file,
        array $overrides = [],
    ): TestResponse {
        return $this->withToken($token)->withHeader('Accept', 'application/json')->post(
            '/api/v1/profiles/'.$profile->public_id.'/documents',
            array_merge([
                'document_type_id' => $type->id,
                'file' => $file,
                'valid_until' => $this->clock->now()->addMonth()->toDateString(),
            ], $overrides),
        );
    }

    private function grant(
        Profile $profile,
        User $actor,
        AccessGrantStatus $status,
        bool $galleryEnabled = false,
        ?CarbonImmutable $expiresAt = null,
    ): void {
        AccessGrant::query()->create([
            'profile_id' => $profile->id,
            'status' => $status,
            'starts_at' => null,
            'expires_at' => $expiresAt,
            'city_limit' => null,
            'monthly_contact_limit' => null,
            'gallery_enabled' => $galleryEnabled,
            'version' => 1,
            'updated_by' => $actor->id,
            'reason' => 'Grant sintético para pruebas documentales.',
        ]);
    }
}
