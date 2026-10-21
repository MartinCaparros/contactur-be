<?php

namespace App\Services;

use App\Contracts\Clock;
use App\Data\AccessEvaluation;
use App\Data\FileDownload;
use App\Data\ValidatedUpload;
use App\Enums\AccessEffectiveState;
use App\Exceptions\ApiProblemException;
use App\Models\DocumentType;
use App\Models\File;
use App\Models\Profile;
use App\Models\ProfileDocument;
use App\Models\User;
use App\Repositories\AuditLogRepository;
use App\Repositories\DocumentRepository;
use App\Repositories\FileRepository;
use App\Repositories\ProfileRepository;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class DocumentService
{
    public function __construct(
        private readonly ProfileService $profileService,
        private readonly ProfileRepository $profiles,
        private readonly DocumentRepository $documents,
        private readonly FileRepository $files,
        private readonly AuditLogRepository $auditLogs,
        private readonly AccessService $access,
        private readonly RealtimeInvalidationService $realtime,
        private readonly NotificationService $notifications,
        private readonly Clock $clock,
    ) {}

    public function ownedProfile(User $actor, string $publicId): Profile
    {
        return $this->profileService->owned($actor, $publicId);
    }

    public function findType(Profile $profile, int $id): ?DocumentType
    {
        return $this->documents->findTypeForProfile($profile, $id);
    }

    /** @return array{items: Collection<int, ProfileDocument>, next_cursor: string|null, total: int} */
    public function list(
        User $actor,
        string $profilePublicId,
        ?string $status,
        ?int $cursor,
        int $perPage,
    ): array {
        $profile = $this->profileService->owned($actor, $profilePublicId);

        return $this->documents->listForProfile($profile, $status, $cursor, $perPage);
    }

    public function upload(
        User $actor,
        Profile $profile,
        DocumentType $type,
        ValidatedUpload $upload,
        ?string $registrationNumber,
        ?CarbonImmutable $validUntil,
        ?string $replacesDocumentId,
        string $requestId,
    ): ProfileDocument {
        $this->assertEditable($actor, $profile);
        $this->assertDocumentData($profile, $type, $validUntil);

        $filePublicId = (string) Str::ulid();
        $documentPublicId = (string) Str::ulid();
        $disk = 'local';
        $path = 'documents/'.$profile->public_id.'/'.$filePublicId.'.'.$upload->extension;
        $stored = false;
        /** @var Collection<int, ProfileDocument> $superseded */
        $superseded = new Collection;

        try {
            $document = DB::transaction(function () use (
                $actor,
                $profile,
                $type,
                $upload,
                $registrationNumber,
                $validUntil,
                $replacesDocumentId,
                $requestId,
                $filePublicId,
                $documentPublicId,
                $disk,
                $path,
                &$stored,
                &$superseded,
            ): ProfileDocument {
                $lockedProfile = $this->profiles->lockById($profile->id);
                $this->assertOwner($actor, $lockedProfile);
                $this->assertEditable($actor, $lockedProfile);
                $lockedType = $this->documents->findTypeForProfile($lockedProfile, $type->id);
                if ($lockedType === null) {
                    throw new ApiProblemException(422, 'validation_failed', __('Los datos enviados no son válidos.'), [
                        'document_type_id' => [__('El tipo de documento no pertenece a la categoría del perfil.')],
                    ]);
                }

                $superseded = $this->documents->lockCurrentForType($lockedProfile, $lockedType);
                if (
                    $replacesDocumentId !== null
                    && ! $superseded->contains(
                        static fn (ProfileDocument $document): bool => $document->public_id === $replacesDocumentId,
                    )
                ) {
                    throw new ApiProblemException(
                        409,
                        'document_replacement_conflict',
                        __('El documento indicado ya no es la revisión vigente de este tipo y perfil.'),
                    );
                }

                $revision = $this->documents->nextRevision($lockedProfile, $lockedType);
                $this->store($disk, $path, $upload);
                $stored = true;
                $file = $this->files->create($lockedProfile, $actor, $filePublicId, 'document', $disk, $path, $upload);
                $this->documents->supersede($superseded);
                $created = $this->documents->create($lockedProfile, $lockedType, $file, [
                    'public_id' => $documentPublicId,
                    'revision' => $revision,
                    'registration_number' => $registrationNumber === null ? null : trim($registrationNumber),
                    'valid_until' => $validUntil,
                    'status' => ProfileDocument::STATUS_PENDING,
                    'reviewed_by' => null,
                    'reviewed_at' => null,
                    'rejection_reason' => null,
                ]);

                $this->auditLogs->create(
                    actor: $actor,
                    targetType: 'profile_document',
                    targetId: $created->public_id,
                    action: 'document.uploaded',
                    reason: 'Carga o reemplazo de documento por el titular.',
                    safeChanges: [
                        'profile_id' => $lockedProfile->public_id,
                        'document_type' => $lockedType->code,
                        'revision' => $revision,
                        'status' => ProfileDocument::STATUS_PENDING,
                        'superseded_document_ids' => $superseded->pluck('public_id')->values()->all(),
                    ],
                    requestId: $requestId,
                    createdAt: $this->clock->now(),
                );

                return $created->load(['documentType', 'file', 'profile']);
            });
        } catch (Throwable $exception) {
            if ($stored) {
                Storage::disk($disk)->delete($path);
            }

            throw $exception;
        }

        foreach ($superseded as $previous) {
            $this->removeBinary($previous->file);
        }

        return $document;
    }

    public function delete(User $actor, string $profilePublicId, string $documentPublicId, string $requestId): void
    {
        $profile = $this->profileService->owned($actor, $profilePublicId);
        $this->assertEditable($actor, $profile);

        $file = DB::transaction(function () use ($actor, $profile, $documentPublicId, $requestId): File {
            $lockedProfile = $this->profiles->lockById($profile->id);
            $this->assertOwner($actor, $lockedProfile);
            $this->assertEditable($actor, $lockedProfile);
            $document = $this->documents->lockForProfile($lockedProfile, $documentPublicId);

            if (
                $document === null
                || in_array($document->status, [ProfileDocument::STATUS_SUPERSEDED, ProfileDocument::STATUS_RETIRED], true)
            ) {
                throw new ApiProblemException(404, 'not_found', __('El recurso solicitado no existe.'));
            }

            $previousStatus = $document->status;
            $this->documents->retire($document);
            $this->auditLogs->create(
                actor: $actor,
                targetType: 'profile_document',
                targetId: $document->public_id,
                action: 'document.retired',
                reason: 'Retiro de documento por el titular.',
                safeChanges: [
                    'profile_id' => $lockedProfile->public_id,
                    'status' => ['before' => $previousStatus, 'after' => ProfileDocument::STATUS_RETIRED],
                ],
                requestId: $requestId,
                createdAt: $this->clock->now(),
            );

            return $document->file;
        });

        $this->removeBinary($file);
    }

    public function download(User $actor, string $profilePublicId, string $documentPublicId): FileDownload
    {
        $profile = $this->profiles->findByPublicId($profilePublicId);
        $isOwner = $profile !== null && $profile->owner_user_id === $actor->id;
        $isActiveAdmin = $actor->system_role === User::ROLE_ADMIN && $actor->status === User::STATUS_ACTIVE;

        if ($profile === null || (! $isOwner && ! $isActiveAdmin)) {
            throw new ApiProblemException(404, 'not_found', __('El recurso solicitado no existe.'));
        }

        $document = $this->documents->findForProfile($profile, $documentPublicId);
        if (
            $document === null
            || in_array($document->status, [ProfileDocument::STATUS_SUPERSEDED, ProfileDocument::STATUS_RETIRED], true)
            || $document->file->storage_deleted_at !== null
            || ! Storage::disk($document->file->disk)->exists($document->file->private_path)
        ) {
            throw new ApiProblemException(404, 'not_found', __('El recurso solicitado no existe.'));
        }

        $stream = Storage::disk($document->file->disk)->readStream($document->file->private_path);
        if (! is_resource($stream)) {
            throw new ApiProblemException(404, 'not_found', __('El recurso solicitado no existe.'));
        }

        return new FileDownload(
            stream: $stream,
            filename: $document->file->original_filename,
            mime: $document->file->mime,
            size: $document->file->size,
        );
    }

    /**
     * Cola documental del backoffice.
     *
     * Sin $status el default sigue siendo `pending`: es el comportamiento que el
     * consumidor actual ya espera de esta cola y el contrato no declara otro
     * default, así que agregar el filtro no puede cambiarlo.
     *
     * @return array{items: Collection<int, ProfileDocument>, next_cursor: string|null, total: int}
     */
    public function adminQueue(
        User $actor,
        ?string $status,
        ?string $category,
        ?int $documentTypeId,
        ?int $cursor,
        int $perPage,
    ): array {
        $this->assertActiveAdmin($actor);
        $result = $this->documents->listForAdmin(
            $status ?? ProfileDocument::STATUS_PENDING,
            $category,
            $documentTypeId,
            $cursor,
            $perPage,
        );
        $this->attachEvaluations($result['items']);

        return $result;
    }

    /**
     * El grant y el owner llegan eager loaded, así que AccessService::evaluate no
     * consulta la base para resolver el estado efectivo. Lo que sí consultaba por
     * ítem era la cuota mensual de contacto de los perfiles tourism_company, y una
     * misma página puede traer varios documentos del mismo perfil. Memoizar por
     * perfil baja la evaluación de una por documento a una por perfil distinto.
     *
     * Limitación que queda: dos perfiles tourism_company distintos siguen costando
     * una consulta de cuota cada uno. Bajar eso a una sola consulta por lote exige
     * cambiar la firma de AccessService, que es la frontera reemplazable de
     * habilitación y está fuera del alcance de este lote; la misma limitación existe
     * hoy en AdminBackofficeService::listProfiles.
     *
     * @param  Collection<int, ProfileDocument>  $documents
     */
    private function attachEvaluations(Collection $documents): void
    {
        /** @var array<int, AccessEvaluation> $evaluations */
        $evaluations = [];

        foreach ($documents as $document) {
            $profile = $document->profile;
            $evaluations[$profile->id] ??= $this->access->evaluate($profile->owner, $profile);
            $profile->setRelation('accessEvaluation', $evaluations[$profile->id]);
        }
    }

    public function review(
        User $actor,
        string $documentPublicId,
        string $action,
        ?string $rejectionReason,
        string $requestId,
    ): ProfileDocument {
        $this->assertActiveAdmin($actor);
        $candidate = ProfileDocument::query()->where('public_id', $documentPublicId)->first();
        if ($candidate === null) {
            throw new ApiProblemException(404, 'not_found', __('El recurso solicitado no existe.'));
        }

        return DB::transaction(function () use ($actor, $candidate, $documentPublicId, $action, $rejectionReason, $requestId): ProfileDocument {
            $lockedProfile = $this->profiles->lockById($candidate->profile_id);
            $verifiedBefore = $this->isVerified($lockedProfile);
            $document = $this->documents->lockByPublicId($documentPublicId);
            if ($document === null) {
                throw new ApiProblemException(404, 'not_found', __('El recurso solicitado no existe.'));
            }
            if ($document->status !== ProfileDocument::STATUS_PENDING) {
                throw new ApiProblemException(409, 'document_not_pending', __('El documento ya no está pendiente de revisión.'));
            }
            if ($document->file->storage_deleted_at !== null || ! Storage::disk($document->file->disk)->exists($document->file->private_path)) {
                throw new ApiProblemException(409, 'document_file_unavailable', __('El archivo del documento ya no está disponible.'));
            }

            $status = $action === 'approve' ? ProfileDocument::STATUS_APPROVED : ProfileDocument::STATUS_REJECTED;
            $reason = $action === 'reject' ? trim((string) $rejectionReason) : null;
            if ($status === ProfileDocument::STATUS_APPROVED && $document->valid_until?->lt($this->clock->now()->startOfDay())) {
                throw new ApiProblemException(422, 'document_expired', __('No se puede aprobar un documento vencido.'));
            }

            $this->documents->review($document, $status, $actor, $this->clock->now(), $reason);
            $audit = $this->auditLogs->create(
                actor: $actor,
                targetType: 'profile_document',
                targetId: $document->public_id,
                action: 'document.'.$status,
                reason: $reason ?? 'Documento aprobado por administración.',
                safeChanges: [
                    'profile_id' => $document->profile->public_id,
                    'status' => ['before' => ProfileDocument::STATUS_PENDING, 'after' => $status],
                    'reviewer_id' => $actor->public_id,
                ],
                requestId: $requestId,
                createdAt: $this->clock->now(),
            );
            // T19: el motivo de rechazo ya lo ve el titular en DocumentResource; nunca el revisor.
            $lockedProfile->loadMissing('owner');
            $this->notifications->administrativeChange(
                $lockedProfile->owner,
                $lockedProfile,
                $status === ProfileDocument::STATUS_APPROVED ? 'document_approved' : 'document_rejected',
                $audit->public_id,
                array_filter([
                    'document_id' => $document->public_id,
                    'document_type' => $document->documentType->label,
                    'rejection_reason' => $reason,
                ], static fn (mixed $value): bool => $value !== null),
            );

            // El badge verificado reordena el directorio (D16): solo se invalida si cambió.
            if ($this->isVerified($lockedProfile) !== $verifiedBefore) {
                $this->realtime->directoryChanged($lockedProfile);
            }

            return $document->refresh()->load(['documentType', 'file', 'profile']);
        });
    }

    /** Misma regla que el badge de la respuesta del titular: requeridos aprobados y vigentes. */
    private function isVerified(Profile $profile): bool
    {
        return $this->profiles->missingRequiredDocuments(
            $profile,
            [ProfileDocument::STATUS_APPROVED],
            $this->clock->now(),
        ) === [];
    }

    private function assertDocumentData(Profile $profile, DocumentType $type, ?CarbonImmutable $validUntil): void
    {
        if ($type->category !== $profile->category) {
            throw new ApiProblemException(422, 'validation_failed', __('Los datos enviados no son válidos.'), [
                'document_type_id' => [__('El tipo de documento no pertenece a la categoría del perfil.')],
            ]);
        }
        if ($type->requires_expiration && $validUntil === null) {
            throw new ApiProblemException(422, 'validation_failed', __('Los datos enviados no son válidos.'), [
                'valid_until' => [__('La fecha de vigencia es obligatoria para este tipo de documento.')],
            ]);
        }
        if ($validUntil?->lt($this->clock->now()->startOfDay())) {
            throw new ApiProblemException(422, 'validation_failed', __('Los datos enviados no son válidos.'), [
                'valid_until' => [__('La fecha de vigencia no puede estar vencida.')],
            ]);
        }
    }

    private function assertEditable(User $actor, Profile $profile): void
    {
        $this->assertOwner($actor, $profile);
        $evaluation = $this->access->evaluate($actor, $profile);
        if ($evaluation->effectiveState === AccessEffectiveState::Suspended->value) {
            throw new ApiProblemException(
                403,
                $evaluation->denialCode ?? 'profile_suspended',
                $evaluation->denialCode === 'account_suspended' ? __('La cuenta está suspendida.') : __('El perfil está suspendido.'),
            );
        }
    }

    private function assertOwner(User $actor, Profile $profile): void
    {
        if ($profile->owner_user_id !== $actor->id) {
            throw new ApiProblemException(404, 'not_found', __('El recurso solicitado no existe.'));
        }
    }

    private function assertActiveAdmin(User $actor): void
    {
        if ($actor->system_role !== User::ROLE_ADMIN || $actor->status !== User::STATUS_ACTIVE) {
            throw new ApiProblemException(403, 'forbidden', __('No tenés permisos para realizar esta acción.'));
        }
    }

    private function store(string $disk, string $path, ValidatedUpload $upload): void
    {
        $stream = @fopen($upload->file->getRealPath(), 'rb');
        if (! is_resource($stream)) {
            throw new RuntimeException('Could not read validated upload.');
        }

        try {
            if (! Storage::disk($disk)->put($path, $stream, ['visibility' => 'private'])) {
                throw new RuntimeException('Could not store validated upload.');
            }
        } finally {
            fclose($stream);
        }
    }

    private function removeBinary(File $file): void
    {
        if ($file->storage_deleted_at !== null) {
            return;
        }

        $storage = Storage::disk($file->disk);
        if ((! $storage->exists($file->private_path) || $storage->delete($file->private_path))) {
            $this->files->markStorageDeleted($file, $this->clock->now());
        }
    }
}
