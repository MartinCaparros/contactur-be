<?php

namespace App\Services;

use App\Contracts\Clock;
use App\Data\DirectoryProfile;
use App\Data\DirectorySearchFilters;
use App\Data\DirectorySearchPage;
use App\Exceptions\ApiProblemException;
use App\Models\AvailabilityDay;
use App\Models\DocumentType;
use App\Models\Profile;
use App\Models\ProfileDocument;
use App\Models\User;
use App\Repositories\AvailabilityRepository;
use App\Repositories\DocumentRepository;
use App\Repositories\ProfileRepository;
use App\Support\CatalogLabel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use JsonException;
use RuntimeException;

/**
 * Directorio entre miembros (R11/R12, D16, D19, D21).
 *
 * Búsqueda, ficha y disponibilidad pública exigen la misma capacidad
 * `can_search` del perfil actor: acceso.md las agrupa en una sola fila
 * ("Buscar/fichas de directorio") y D19 limita las fichas a miembros
 * habilitados. `{target}` nunca da autoridad: si no cumple la visibilidad de
 * búsqueda responde 404 sin explicar por qué.
 */
final class DirectoryService
{
    private const CURSOR_VERSION = 1;

    public function __construct(
        private readonly ProfileService $profileService,
        private readonly AccessService $access,
        private readonly ProfileRepository $profiles,
        private readonly AvailabilityRepository $availability,
        private readonly DocumentRepository $documents,
        private readonly ReferenceService $references,
        private readonly Clock $clock,
    ) {}

    /**
     * El seed de orden es la fecha de negocio de la primera página y viaja en el
     * cursor: una sesión que cruza medianoche conserva su orden hasta reiniciarse.
     */
    public function search(
        User $actor,
        string $profilePublicId,
        DirectorySearchFilters $filters,
        ?string $cursor,
        int $perPage,
    ): DirectorySearchPage {
        $actorProfile = $this->authorizedActor($actor, $profilePublicId);
        $fingerprint = $filters->fingerprint();
        [$seed, $after] = $cursor === null
            ? [$this->businessToday(), null]
            : $this->decodeCursor($cursor, $actorProfile, $fingerprint);
        $rows = $this->profiles->searchDirectory(
            $actorProfile,
            $filters,
            $seed,
            $after,
            $perPage,
            $this->clock->now(),
        );
        $hasMore = $rows->count() > $perPage;
        /** @var Collection<int, Profile> $items */
        $items = $rows->take($perPage)->values();

        foreach ($items as $profile) {
            $profile->setAttribute('verified', (int) $profile->getAttribute('sort_rank') === 0);
            $profile->syncOriginalAttribute('verified');
        }

        $last = $items->last();

        return new DirectorySearchPage(
            items: $items,
            requestedDays: $filters->requestedDays(),
            nextCursor: $hasMore && $last instanceof Profile
                ? $this->encodeCursor($actorProfile, $fingerprint, $seed, [
                    (int) $last->getAttribute('sort_rank'),
                    (string) $last->getAttribute('sort_hash'),
                    (string) $last->getAttribute('sort_id'),
                ])
                : null,
            perPage: $perPage,
            seed: $seed,
        );
    }

    public function profile(User $actor, string $profilePublicId, string $targetPublicId): DirectoryProfile
    {
        $target = $this->visibleTarget($actor, $profilePublicId, $targetPublicId);
        $now = $this->clock->now();
        $this->profiles->loadForDirectory($target, $now);

        return new DirectoryProfile(
            $target,
            $this->documentStatuses($target, $this->documents->typesForProfile($target), $now),
            // T20/R17: solo referencias visibles y no moderadas del target.
            $this->references->publicForSubject($target, $now),
        );
    }

    /**
     * Solo días habilitados del target; el rango ya llega validado (mes o
     * date_from/date_to con máximo configurable).
     *
     * @return array{profile_id: string, version: int, days: Collection<int, AvailabilityDay>}
     */
    public function availability(
        User $actor,
        string $profilePublicId,
        string $targetPublicId,
        string $fromDate,
        string $toDate,
        ?int $cityId,
    ): array {
        $target = $this->visibleTarget($actor, $profilePublicId, $targetPublicId);

        return [
            'profile_id' => $target->public_id,
            'version' => (int) $target->getAttribute('availability_version'),
            'days' => $this->availability->availableDays($target, $fromDate, $toDate, $cityId),
        ];
    }

    /** Perfil propio (404 si es ajeno) con `can_search` vigente (403 con el denial real). */
    private function authorizedActor(User $actor, string $profilePublicId): Profile
    {
        $profile = $this->profileService->owned($actor, $profilePublicId);
        $this->access->assertAllowed($actor, $profile, AccessService::CAPABILITY_SEARCH);

        return $profile;
    }

    private function visibleTarget(User $actor, string $profilePublicId, string $targetPublicId): Profile
    {
        $actorProfile = $this->authorizedActor($actor, $profilePublicId);
        $target = $this->profiles->findDirectoryTarget($actorProfile, $targetPublicId, $this->clock->now());

        if ($target === null) {
            throw new ApiProblemException(404, 'not_found', __('El recurso solicitado no existe.'));
        }

        return $target;
    }

    /**
     * Estado real por tipo documental, sin archivo, número ni motivo de rechazo.
     * Los tipos requeridos siempre aparecen (missing si no hay carga); los
     * opcionales solo si tienen una revisión vigente.
     *
     * @param  Collection<int, DocumentType>  $types
     * @return array<int, array{document_type: array{code: string, label: string}, status: string, valid_until: string|null, is_currently_valid: bool}>
     */
    private function documentStatuses(Profile $target, Collection $types, CarbonImmutable $now): array
    {
        $today = $now->startOfDay();
        /** @var array<int, ProfileDocument> $current */
        $current = [];

        foreach ($target->documents as $document) {
            $existing = $current[$document->document_type_id] ?? null;
            if ($existing === null || $document->revision > $existing->revision) {
                $current[$document->document_type_id] = $document;
            }
        }

        $statuses = [];
        foreach ($types as $type) {
            $document = $current[$type->id] ?? null;

            $documentType = ['code' => $type->code, 'label' => CatalogLabel::translate($type->label)];

            if ($document === null) {
                if ($type->required) {
                    $statuses[] = [
                        'document_type' => $documentType,
                        'status' => 'missing',
                        'valid_until' => null,
                        'is_currently_valid' => false,
                    ];
                }

                continue;
            }

            $expired = $document->valid_until !== null && $document->valid_until->lt($today);
            $approved = $document->status === ProfileDocument::STATUS_APPROVED;
            $statuses[] = [
                'document_type' => $documentType,
                'status' => $approved && $expired ? 'expired' : $document->status,
                'valid_until' => $document->valid_until?->toDateString(),
                'is_currently_valid' => $approved
                    && ! $expired
                    && $document->file->storage_deleted_at === null
                    && ! ($type->requires_expiration && $document->valid_until === null),
            ];
        }

        return $statuses;
    }

    /** @param array{int, string, string} $key */
    private function encodeCursor(Profile $actorProfile, string $fingerprint, string $seed, array $key): string
    {
        $body = $this->base64UrlEncode(json_encode([
            'v' => self::CURSOR_VERSION,
            'a' => $actorProfile->public_id,
            'f' => $fingerprint,
            's' => $seed,
            'k' => $key,
        ], JSON_THROW_ON_ERROR));

        return $body.'.'.$this->base64UrlEncode(hash_hmac('sha256', $body, $this->cursorKey(), true));
    }

    /**
     * Firma inválida o forma inesperada → 422 (entrada inválida). Firma válida pero
     * emitida para otro actor u otros filtros → 409 (conflicto con la consulta).
     *
     * @return array{string, array{int, string, string}}
     */
    private function decodeCursor(string $cursor, Profile $actorProfile, string $fingerprint): array
    {
        $parts = explode('.', $cursor);
        $payload = null;

        if (count($parts) === 2) {
            $expected = $this->base64UrlEncode(hash_hmac('sha256', $parts[0], $this->cursorKey(), true));

            if (hash_equals($expected, $parts[1])) {
                $decoded = base64_decode(strtr($parts[0], '-_', '+/'), true);
                try {
                    $payload = $decoded === false ? null : json_decode($decoded, true, 4, JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    $payload = null;
                }
            }
        }

        if (
            ! is_array($payload)
            || ($payload['v'] ?? null) !== self::CURSOR_VERSION
            || ! is_string($payload['a'] ?? null)
            || ! is_string($payload['f'] ?? null)
            || ! is_string($payload['s'] ?? null)
            || preg_match('/^\d{4}-\d{2}-\d{2}$/', $payload['s']) !== 1
            || ! is_array($payload['k'] ?? null)
            || ! array_is_list($payload['k'])
            || count($payload['k']) !== 3
            || ! in_array($payload['k'][0], [0, 1], true)
            || ! is_string($payload['k'][1])
            || ! is_string($payload['k'][2])
        ) {
            throw new ApiProblemException(422, 'validation_failed', __('Los datos enviados no son válidos.'), [
                'cursor' => [__('El cursor no es válido.')],
            ]);
        }

        if (! hash_equals($payload['a'], $actorProfile->public_id) || ! hash_equals($payload['f'], $fingerprint)) {
            throw new ApiProblemException(
                409,
                'search_cursor_mismatch',
                __('El cursor corresponde a otra búsqueda; volvé a la primera página.'),
            );
        }

        return [$payload['s'], [$payload['k'][0], $payload['k'][1], $payload['k'][2]]];
    }

    /** Clave derivada de APP_KEY, separada por propósito de otros usos de la misma clave. */
    private function cursorKey(): string
    {
        $appKey = (string) config('app.key');

        if ($appKey === '') {
            throw new RuntimeException('APP_KEY is required to sign search cursors.');
        }

        return hash_hmac('sha256', 'contactur:search-cursor:v1', $appKey, true);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function businessToday(): string
    {
        return $this->clock->now()->setTimezone(AvailabilityDay::BUSINESS_TIMEZONE)->toDateString();
    }
}
