<?php

namespace App\Repositories;

use App\Models\DocumentType;
use App\Models\File;
use App\Models\Profile;
use App\Models\ProfileDocument;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class DocumentRepository
{
    /** @return array{items: Collection<int, ProfileDocument>, next_cursor: string|null, total: int} */
    public function listForProfile(
        Profile $profile,
        ?string $status,
        ?int $cursor,
        int $perPage,
    ): array {
        $query = ProfileDocument::query()
            ->where('profile_id', $profile->id)
            ->whereIn('status', [
                ProfileDocument::STATUS_PENDING,
                ProfileDocument::STATUS_APPROVED,
                ProfileDocument::STATUS_REJECTED,
            ]);

        if ($status !== null) {
            $query->where('status', $status);
        }

        $total = (clone $query)->count();
        if ($cursor !== null) {
            $query->where('id', '<', $cursor);
        }

        /** @var Collection<int, ProfileDocument> $items */
        $items = $query
            ->with(['documentType', 'file', 'profile'])
            ->orderByDesc('id')
            ->limit($perPage + 1)
            ->get();
        $hasMore = $items->count() > $perPage;
        if ($hasMore) {
            $items->pop();
        }

        return [
            'items' => $items,
            'next_cursor' => $hasMore ? (string) $items->last()?->id : null,
            'total' => $total,
        ];
    }

    /** @return Collection<int, DocumentType> */
    public function typesForProfile(Profile $profile): Collection
    {
        return DocumentType::query()
            ->where('category', $profile->category->value)
            ->orderBy('id')
            ->get();
    }

    public function findTypeForProfile(Profile $profile, int $id): ?DocumentType
    {
        return DocumentType::query()
            ->whereKey($id)
            ->where('category', $profile->category->value)
            ->first();
    }

    /** @return Collection<int, ProfileDocument> */
    public function lockCurrentForType(Profile $profile, DocumentType $type): Collection
    {
        return ProfileDocument::query()
            ->where('profile_id', $profile->id)
            ->where('document_type_id', $type->id)
            ->whereIn('status', [
                ProfileDocument::STATUS_PENDING,
                ProfileDocument::STATUS_APPROVED,
                ProfileDocument::STATUS_REJECTED,
            ])
            ->with('file')
            ->lockForUpdate()
            ->get();
    }

    public function nextRevision(Profile $profile, DocumentType $type): int
    {
        return ((int) ProfileDocument::query()
            ->where('profile_id', $profile->id)
            ->where('document_type_id', $type->id)
            ->max('revision')) + 1;
    }

    /** @param Collection<int, ProfileDocument> $documents */
    public function supersede(Collection $documents): void
    {
        foreach ($documents as $document) {
            $document->forceFill([
                'status' => ProfileDocument::STATUS_SUPERSEDED,
            ])->save();
        }
    }

    /** @param array<string, mixed> $attributes */
    public function create(Profile $profile, DocumentType $type, File $file, array $attributes): ProfileDocument
    {
        return ProfileDocument::query()->create(array_merge($attributes, [
            'profile_id' => $profile->id,
            'document_type_id' => $type->id,
            'file_id' => $file->id,
        ]));
    }

    public function lockForProfile(Profile $profile, string $publicId): ?ProfileDocument
    {
        return ProfileDocument::query()
            ->where('profile_id', $profile->id)
            ->where('public_id', $publicId)
            ->with(['documentType', 'file', 'profile'])
            ->lockForUpdate()
            ->first();
    }

    public function findForProfile(Profile $profile, string $publicId): ?ProfileDocument
    {
        return ProfileDocument::query()
            ->where('profile_id', $profile->id)
            ->where('public_id', $publicId)
            ->with(['documentType', 'file', 'profile'])
            ->first();
    }

    public function lockByPublicId(string $publicId): ?ProfileDocument
    {
        return ProfileDocument::query()
            ->where('public_id', $publicId)
            ->with(['documentType', 'file', 'profile'])
            ->lockForUpdate()
            ->first();
    }

    /**
     * Cola documental del backoffice. $status ya llega resuelto por el servicio y
     * nunca es null, así que superseded/retired quedan fuera por construcción: los
     * únicos valores posibles son los tres que el contrato declara.
     *
     * $category filtra por la categoría del perfil dueño del documento, con un
     * EXISTS correlacionado en lugar de un join para no alterar la selección ni
     * duplicar filas; el eager loading sigue resolviendo las relaciones por lote.
     *
     * @return array{items: Collection<int, ProfileDocument>, next_cursor: string|null, total: int}
     */
    public function listForAdmin(
        string $status,
        ?string $category,
        ?int $documentTypeId,
        ?int $cursor,
        int $perPage,
    ): array {
        $query = ProfileDocument::query()
            ->where('profile_documents.status', $status)
            ->when($documentTypeId !== null, fn ($builder) => $builder->where(
                'profile_documents.document_type_id',
                $documentTypeId,
            ))
            ->when($category !== null, fn ($builder) => $builder->whereHas(
                'profile',
                fn ($profile) => $profile->where('profiles.category', $category),
            ));
        $total = (clone $query)->count();

        if ($cursor !== null) {
            $query->where('id', '>', $cursor);
        }

        /** @var Collection<int, ProfileDocument> $items */
        $items = $query
            ->with([
                'documentType',
                'file',
                'profile.accessGrant',
                'profile.owner' => static fn ($query) => $query->withCount('profiles'),
            ])
            ->orderBy('id')
            ->limit($perPage + 1)
            ->get();
        $hasMore = $items->count() > $perPage;
        if ($hasMore) {
            $items->pop();
        }

        return [
            'items' => $items,
            'next_cursor' => $hasMore ? (string) $items->last()?->id : null,
            'total' => $total,
        ];
    }

    /**
     * Documentos pendientes de revisión. "Fecha" acá es `profile_documents.created_at`,
     * la fecha de carga del documento, no la de revisión: un pendiente por definición
     * no tiene `reviewed_at`.
     */
    public function countPending(?CarbonImmutable $createdFrom, ?CarbonImmutable $createdUntil): int
    {
        return (int) DB::table('profile_documents')
            ->where('profile_documents.status', ProfileDocument::STATUS_PENDING)
            ->when($createdFrom !== null, fn ($query) => $query->where('profile_documents.created_at', '>=', $createdFrom))
            ->when($createdUntil !== null, fn ($query) => $query->where('profile_documents.created_at', '<', $createdUntil))
            ->count();
    }

    public function review(
        ProfileDocument $document,
        string $status,
        User $reviewer,
        CarbonImmutable $reviewedAt,
        ?string $rejectionReason,
    ): void {
        $document->forceFill([
            'status' => $status,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => $reviewedAt,
            'rejection_reason' => $rejectionReason,
        ])->save();
    }

    public function retire(ProfileDocument $document): void
    {
        $document->forceFill([
            'status' => ProfileDocument::STATUS_RETIRED,
        ])->save();
    }
}
