<?php

namespace App\Repositories;

use App\Models\Profile;
use App\Models\Reference;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Referencias (T20/R17). La tabla se llama `references` (palabra reservada de
 * PostgreSQL): el query builder la cita siempre; no usar SQL crudo sin citarla.
 */
final class ReferenceRepository
{
    public const DIRECTION_AUTHORED = 'authored';

    public const DIRECTION_RECEIVED = 'received';

    /** Nombre del índice único (match, autor, destinatario) que el servicio traduce a 409. */
    public const UNIQUE_CONSTRAINT = 'references_request_author_subject_unique';

    /**
     * Relaciones de SafeProfile para autor y destinatario. Nunca `contact` ni
     * `owner`: contact_email, phone y el email de la cuenta no se cargan (R12).
     */
    private const SAFE_PROFILE_RELATIONS = [
        'primaryCity',
        'cities',
        'languages',
        'services',
        'companyDetails',
        'guideDetails',
        'driverDetails',
        'instructorDetails',
        'transportDetails',
        'avatarFile',
        'gallery.file',
    ];

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): Reference
    {
        return Reference::query()->create($attributes);
    }

    /** Solo si el perfil es autor o destinatario; si no, no existe para él. */
    public function findForParticipant(Profile $participant, string $publicId, bool $lock = false): ?Reference
    {
        return Reference::query()
            ->where('public_id', $publicId)
            ->where(fn (Builder $query) => $this->constrainParticipant($query, $participant, null))
            ->when($lock, fn (Builder $query) => $query->lockForUpdate())
            ->first();
    }

    public function lockByPublicId(string $publicId): ?Reference
    {
        return Reference::query()->where('public_id', $publicId)->lockForUpdate()->first();
    }

    /**
     * Emitidas, recibidas o ambas (sin `direction`). Orden id desc con cursor
     * numérico (id < cursor), igual que solicitudes y listados admin.
     *
     * @return array{items: Collection<int, Reference>, next_cursor: string|null}
     */
    public function listForParticipant(Profile $participant, ?string $direction, ?int $cursor, int $perPage): array
    {
        $items = Reference::query()
            ->where(fn (Builder $query) => $this->constrainParticipant($query, $participant, $direction))
            ->when($cursor !== null, fn (Builder $query) => $query->where('id', '<', $cursor))
            ->orderByDesc('id')
            ->limit($perPage + 1)
            ->get();

        return $this->page($items, $perPage);
    }

    /**
     * Cola de moderación: filtro opcional por estado de moderación y por perfil
     * (public_id como autor o destinatario). Un perfil inexistente da lista vacía.
     *
     * @return array{items: Collection<int, Reference>, next_cursor: string|null, total: int}
     */
    public function listForAdmin(?bool $moderated, ?string $profilePublicId, ?int $cursor, int $perPage): array
    {
        $base = Reference::query()
            ->when($moderated === true, fn (Builder $query) => $query->whereNotNull('moderated_at'))
            ->when($moderated === false, fn (Builder $query) => $query->whereNull('moderated_at'))
            ->when($profilePublicId !== null, function (Builder $query) use ($profilePublicId): void {
                $profileIds = Profile::query()->select('id')->where('public_id', $profilePublicId);
                $query->where(fn (Builder $inner) => $inner
                    ->whereIn('author_profile_id', $profileIds)
                    ->orWhereIn('subject_profile_id', $profileIds));
            });
        $total = (clone $base)->count();
        $items = $base
            ->when($cursor !== null, fn (Builder $query) => $query->where('id', '<', $cursor))
            ->orderByDesc('id')
            ->limit($perPage + 1)
            ->get();

        return [...$this->page($items, $perPage), 'total' => $total];
    }

    /**
     * Ficha de directorio: solo visibles y no moderadas del destinatario, más
     * nuevas primero, con tope. El autor se carga con las relaciones de SafeProfile
     * en lote (consultas constantes, sin N+1).
     *
     * @return Collection<int, Reference>
     */
    public function publicForSubject(Profile $subject, int $limit): Collection
    {
        return $this->publicQueryForSubject($subject)
            ->orderByDesc('id')
            ->limit($limit)
            ->with(['author' => static fn ($query) => $query->with(self::SAFE_PROFILE_RELATIONS)])
            ->get();
    }

    /**
     * T21: total de lo que muestra la ficha de directorio (mismo filtro que
     * publicForSubject), sin el tope de la ficha.
     */
    public function publicCountForSubject(Profile $subject): int
    {
        return $this->publicQueryForSubject($subject)->count();
    }

    /**
     * Carga por lote (sin N+1) lo que serializa ReferenceResource: el public_id de
     * la solicitud y los dos perfiles seguros.
     *
     * @param  Collection<int, Reference>  $references
     */
    public function loadForResponse(Collection $references): void
    {
        $references->load([
            'contactRequest:id,public_id',
            'author' => static fn ($query) => $query->with(self::SAFE_PROFILE_RELATIONS),
            'subject' => static fn ($query) => $query->with(self::SAFE_PROFILE_RELATIONS),
        ]);
    }

    /**
     * Autor y destinatario como modelos aparte (no las relaciones serializadas) con
     * `owner` para notificar a cada titular. Indexados por id interno.
     *
     * @return array<int, Profile>
     */
    public function parties(Reference $reference): array
    {
        return Profile::query()
            ->with('owner')
            ->whereIn('id', [$reference->author_profile_id, $reference->subject_profile_id])
            ->get()
            ->keyBy('id')
            ->all();
    }

    public function updateVisibility(Reference $reference, string $visibility, CarbonImmutable $now): void
    {
        $reference->forceFill([
            'visibility' => $visibility,
            'updated_at' => $now,
        ])->save();
    }

    public function moderate(Reference $reference, User $admin, string $reason, CarbonImmutable $now): void
    {
        $reference->forceFill([
            'moderated_at' => $now,
            'moderated_by' => $admin->id,
            'moderation_reason' => $reason,
            'updated_at' => $now,
        ])->save();
    }

    /** Restaurar limpia la moderación; la visibilidad elegida por el destinatario no cambia. */
    public function clearModeration(Reference $reference, CarbonImmutable $now): void
    {
        $reference->forceFill([
            'moderated_at' => null,
            'moderated_by' => null,
            'moderation_reason' => null,
            'updated_at' => $now,
        ])->save();
    }

    /**
     * Visibles y no moderadas del destinatario: lo único que se publica (R17).
     *
     * @return Builder<Reference>
     */
    private function publicQueryForSubject(Profile $subject): Builder
    {
        return Reference::query()
            ->where('subject_profile_id', $subject->id)
            ->where('visibility', Reference::VISIBILITY_VISIBLE)
            ->whereNull('moderated_at');
    }

    /** @param Builder<Reference> $query */
    private function constrainParticipant(Builder $query, Profile $participant, ?string $direction): void
    {
        match ($direction) {
            self::DIRECTION_AUTHORED => $query->where('author_profile_id', $participant->id),
            self::DIRECTION_RECEIVED => $query->where('subject_profile_id', $participant->id),
            default => $query
                ->where('author_profile_id', $participant->id)
                ->orWhere('subject_profile_id', $participant->id),
        };
    }

    /**
     * @param  Collection<int, Reference>  $items
     * @return array{items: Collection<int, Reference>, next_cursor: string|null}
     */
    private function page(Collection $items, int $perPage): array
    {
        $hasMore = $items->count() > $perPage;
        /** @var Collection<int, Reference> $page */
        $page = $items->take($perPage)->values();

        return [
            'items' => $page,
            'next_cursor' => $hasMore ? (string) $page->last()?->id : null,
        ];
    }
}
