<?php

namespace App\Services;

use App\Contracts\Clock;
use App\Enums\ContactRequestStatus;
use App\Exceptions\ApiProblemException;
use App\Models\Profile;
use App\Models\ProfileDocument;
use App\Models\Reference;
use App\Models\User;
use App\Repositories\AuditLogRepository;
use App\Repositories\ContactRequestRepository;
use App\Repositories\ProfileRepository;
use App\Repositories\ReferenceRepository;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Referencias bilaterales, T20 (R17/D14/D21/P25). Solo texto: sin puntajes,
 * estrellas ni ranking, y nunca afirman que un servicio se haya realizado.
 *
 * Decisiones:
 * - Crear exige acceso base vigente del perfil autor (acceso.md: "Match aceptado"
 *   activo; vencido/inactivo/suspendido → su 403 real). Luego, como GET /contact:
 *   solicitud en la que el perfil no participa → 404; no `accepted` → 409
 *   `contact_request_not_accepted`. El destinatario es siempre la otra parte del
 *   match: nunca lo elige el cliente.
 * - Una por (match, autor, destinatario): el índice único es la única fuente de
 *   verdad del duplicado, también ante creaciones concurrentes (la fila de la
 *   solicitud bloqueada serializa a ambas partes y la segunda choca con el índice
 *   recién confirmado). La violación se traduce a 409 `reference_already_exists`.
 * - El texto se guarda tal cual (recortado): no se interpreta ni se filtra; los
 *   clientes lo escapan al mostrarlo. No existe ningún endpoint que lo edite.
 * - Visibilidad: solo el destinatario, con acceso vencido o inactivo sí y
 *   suspensión no (`can_reject_pending_requests`, D21). Autor → 403 `forbidden`;
 *   ajeno → 404. Moderada → 409 `reference_moderated` aun si repite el valor: el
 *   destinatario no puede publicar ni re-ocultar sobre la decisión del admin.
 *   Mismo valor sin moderación → 200 sin cambios ni invalidación.
 * - Listar usa `can_view_history`, igual que el historial de solicitudes (D21).
 *   `moderation_reason` es visible para ambas partes; nunca en la ficha.
 * - Moderación admin: `hide` marca moderated_at/by/reason, `restore` los limpia y
 *   deja la visibilidad que eligió el destinatario. La transición que no cambia
 *   nada (ocultar una ya moderada, restaurar una no moderada) responde 200 sin
 *   auditoría, notificación ni invalidación, igual que los PATCH de estado del
 *   backoffice (AdminBackofficeService): el motivo de la moderación vigente no se
 *   reescribe. La auditoría nunca incluye el texto de la referencia.
 */
final class ReferenceService
{
    public function __construct(
        private readonly ProfileService $profileService,
        private readonly AccessService $access,
        private readonly ContactRequestRepository $requests,
        private readonly ReferenceRepository $references,
        private readonly ProfileRepository $profiles,
        private readonly AuditLogRepository $auditLogs,
        private readonly NotificationService $notifications,
        private readonly RealtimeInvalidationService $realtime,
        private readonly Clock $clock,
    ) {}

    public function create(User $actor, string $profilePublicId, string $requestPublicId, string $body): Reference
    {
        $author = $this->profileService->owned($actor, $profilePublicId);
        $this->assertActiveAccess($actor, $author);

        try {
            return DB::transaction(function () use ($author, $requestPublicId, $body): Reference {
                $request = $this->requests->findForParticipant($author, $requestPublicId, lock: true);

                if ($request === null) {
                    throw new ApiProblemException(404, 'not_found', __('El recurso solicitado no existe.'));
                }

                $now = $this->clock->now();
                if ($request->effectiveStatus($now) !== ContactRequestStatus::Accepted) {
                    throw new ApiProblemException(
                        409,
                        'contact_request_not_accepted',
                        __('Solo se puede dejar una referencia después de un match aceptado.'),
                    );
                }

                $subjectId = $request->sender_profile_id === $author->id
                    ? $request->recipient_profile_id
                    : $request->sender_profile_id;
                $created = $this->references->create([
                    'contact_request_id' => $request->id,
                    'author_profile_id' => $author->id,
                    'subject_profile_id' => $subjectId,
                    'body' => $body,
                    'visibility' => Reference::VISIBILITY_HIDDEN,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $this->prepareForResponse(new Collection([$created]), $now);

                return $created;
            });
        } catch (UniqueConstraintViolationException $exception) {
            if (str_contains($exception->getMessage(), ReferenceRepository::UNIQUE_CONSTRAINT)) {
                throw new ApiProblemException(
                    409,
                    'reference_already_exists',
                    __('Ya dejaste una referencia para este match.'),
                );
            }

            throw $exception;
        }
    }

    /** @return array{items: Collection<int, Reference>, next_cursor: string|null} */
    public function list(User $actor, string $profilePublicId, ?string $direction, ?int $cursor, int $perPage): array
    {
        $profile = $this->profileService->owned($actor, $profilePublicId);
        $this->access->assertAllowed($actor, $profile, AccessService::CAPABILITY_VIEW_HISTORY);
        $page = $this->references->listForParticipant($profile, $direction, $cursor, $perPage);
        $this->prepareForResponse($page['items'], $this->clock->now());

        return $page;
    }

    public function updateVisibility(User $actor, string $profilePublicId, string $referencePublicId, string $visibility): Reference
    {
        $profile = $this->profileService->owned($actor, $profilePublicId);
        $this->access->assertAllowed($actor, $profile, AccessService::CAPABILITY_REJECT_PENDING_REQUESTS);

        return DB::transaction(function () use ($profile, $referencePublicId, $visibility): Reference {
            $reference = $this->references->findForParticipant($profile, $referencePublicId, lock: true);

            if ($reference === null) {
                throw new ApiProblemException(404, 'not_found', __('El recurso solicitado no existe.'));
            }
            if ($reference->subject_profile_id !== $profile->id) {
                throw new ApiProblemException(403, 'forbidden', __('Solo el perfil que recibió la referencia puede cambiar su visibilidad.'));
            }
            if ($reference->isModerated()) {
                throw new ApiProblemException(
                    409,
                    'reference_moderated',
                    __('La referencia fue moderada por administración y su visibilidad no puede cambiarse.'),
                );
            }

            $now = $this->clock->now();
            if ($reference->visibility !== $visibility) {
                $this->references->updateVisibility($reference, $visibility, $now);
                // La ficha del destinatario cambió: los directorios abiertos re-consultan tras el commit.
                $this->realtime->directoryChanged($profile);
            }
            $this->prepareForResponse(new Collection([$reference]), $now);

            return $reference;
        });
    }

    /** @return array{items: Collection<int, Reference>, next_cursor: string|null, total: int} */
    public function listForAdmin(User $actor, ?bool $moderated, ?string $profilePublicId, ?int $cursor, int $perPage): array
    {
        $this->assertActiveAdmin($actor);
        $page = $this->references->listForAdmin($moderated, $profilePublicId, $cursor, $perPage);
        $this->prepareForResponse($page['items'], $this->clock->now());

        return $page;
    }

    public function moderate(User $actor, string $referencePublicId, string $action, string $reason, string $requestId): Reference
    {
        $this->assertActiveAdmin($actor);
        $normalizedReason = $this->normalizedReason($reason);

        return DB::transaction(function () use ($actor, $referencePublicId, $action, $normalizedReason, $requestId): Reference {
            $reference = $this->references->lockByPublicId($referencePublicId);

            if ($reference === null) {
                throw new ApiProblemException(404, 'not_found', __('El recurso solicitado no existe.'));
            }

            $now = $this->clock->now();
            $hide = $action === 'hide';
            $wasModerated = $reference->isModerated();

            if ($hide === $wasModerated) {
                $this->prepareForResponse(new Collection([$reference]), $now);

                return $reference;
            }

            if ($hide) {
                $this->references->moderate($reference, $actor, $normalizedReason, $now);
            } else {
                $this->references->clearModeration($reference, $now);
            }

            $this->prepareForResponse(new Collection([$reference]), $now);
            $parties = $this->references->parties($reference);
            $author = $parties[$reference->author_profile_id] ?? null;
            $subject = $parties[$reference->subject_profile_id] ?? null;
            $audit = $this->auditLogs->create(
                actor: $actor,
                targetType: 'reference',
                targetId: $reference->public_id,
                action: $hide ? 'reference.hidden' : 'reference.restored',
                reason: $normalizedReason,
                // Whitelist: IDs públicos y estados. Nunca el texto de la referencia.
                safeChanges: [
                    'action' => $action,
                    'moderated' => ['before' => $wasModerated, 'after' => $hide],
                    'visibility' => $reference->visibility,
                    'contact_request_id' => $reference->contactRequest->public_id,
                    'author_profile_id' => $author?->public_id,
                    'subject_profile_id' => $subject?->public_id,
                ],
                requestId: $requestId,
                createdAt: $now,
            );

            $change = $hide ? 'reference_hidden' : 'reference_restored';
            foreach (['subject' => $subject, 'author' => $author] as $role => $party) {
                $owner = $party?->getRelation('owner');
                if ($party instanceof Profile && $owner instanceof User) {
                    $this->notifications->administrativeChange(
                        $owner,
                        $party,
                        $change,
                        $audit->public_id,
                        ['reference_id' => $reference->public_id, 'role' => $role],
                        $role,
                    );
                }
            }
            if ($subject instanceof Profile) {
                $this->realtime->directoryChanged($subject);
            }

            return $reference;
        });
    }

    /**
     * Referencias públicas de la ficha (`visible_references`): visibles, no
     * moderadas, más nuevas primero y con tope configurable. El autor sale como
     * SafeProfile con su badge calculado en una sola consulta para todo el lote.
     *
     * @return Collection<int, Reference>
     */
    public function publicForSubject(Profile $subject, CarbonImmutable $now): Collection
    {
        $references = $this->references->publicForSubject($subject, (int) config('contactur.references.directory_limit'));
        $authorIds = $references->pluck('author_profile_id')->unique()->values()->all();
        $verified = $this->profiles->verifiedFlags($authorIds, [ProfileDocument::STATUS_APPROVED], $now);

        foreach ($references as $reference) {
            $reference->author->setAttribute('verified', $verified[$reference->author_profile_id] ?? false);
            $reference->author->syncOriginalAttribute('verified');
        }

        return $references;
    }

    /** Acceso base vigente del perfil propio (grant activo, sin suspensión): 403 con el denial real. */
    private function assertActiveAccess(User $actor, Profile $profile): void
    {
        $evaluation = $this->access->evaluate($actor, $profile);

        if (! $evaluation->allowed) {
            throw new ApiProblemException(
                403,
                $evaluation->denialCode ?? 'subscription_required',
                __('El perfil no tiene acceso a esta funcionalidad.'),
            );
        }
    }

    /**
     * Relaciones por lote y badge `verified` de cada perfil con una sola consulta.
     *
     * @param  Collection<int, Reference>  $references
     */
    private function prepareForResponse(Collection $references, CarbonImmutable $now): void
    {
        if ($references->isEmpty()) {
            return;
        }

        $this->references->loadForResponse($references);
        $profileIds = [];
        foreach ($references as $reference) {
            $profileIds[$reference->author_profile_id] = $reference->author_profile_id;
            $profileIds[$reference->subject_profile_id] = $reference->subject_profile_id;
        }
        $verified = $this->profiles->verifiedFlags(array_values($profileIds), [ProfileDocument::STATUS_APPROVED], $now);

        foreach ($references as $reference) {
            foreach ([$reference->author, $reference->subject] as $party) {
                $party->setAttribute('verified', $verified[$party->id] ?? false);
                $party->syncOriginalAttribute('verified');
            }
        }
    }

    private function normalizedReason(string $reason): string
    {
        $trimmed = trim($reason);

        if (mb_strlen($trimmed) < 3) {
            throw new ApiProblemException(422, 'validation_failed', __('Los datos enviados no son válidos.'), [
                'reason' => [__('El motivo debe tener al menos 3 caracteres no vacíos.')],
            ]);
        }

        return $trimmed;
    }

    private function assertActiveAdmin(User $actor): void
    {
        if ($actor->system_role !== User::ROLE_ADMIN || $actor->status !== User::STATUS_ACTIVE) {
            throw new ApiProblemException(403, 'forbidden', __('No tenés permisos para realizar esta acción.'));
        }
    }
}
