<?php

namespace App\Services;

use App\Contracts\Clock;
use App\Data\IdempotentResponse;
use App\Data\MatchedContacts;
use App\Enums\ContactRequestStatus;
use App\Enums\NotificationType;
use App\Enums\ProfileCategory;
use App\Exceptions\ApiProblemException;
use App\Models\AvailabilityDay;
use App\Models\ContactRequest;
use App\Models\Profile;
use App\Models\ProfileDocument;
use App\Models\User;
use App\Repositories\AccessGrantRepository;
use App\Repositories\AvailabilityRepository;
use App\Repositories\ContactRequestRepository;
use App\Repositories\ContactUsageRepository;
use App\Repositories\IdempotencyRepository;
use App\Repositories\ProfileRepository;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Solicitudes de contacto, T17 (R13–R16/D13/D21/D22). Lote A: crear, listar, ver
 * y cancelar. Lote B: aceptar, rechazar, job de expiración y revelado de contacto.
 *
 * Decisiones del lote B:
 * - Responder (aceptar/rechazar) usa el mismo orden que cancelar: primero el
 *   acceso propio sin suspensión (`can_reject_pending_requests`), luego tercero
 *   → 404 (no revela la solicitud), participante equivocado (emisor) → 403
 *   `forbidden`, estado efectivo no pendiente → 409 `contact_request_not_pending`
 *   (en expires_at exacto ya está vencida). Aceptar además exige
 *   `can_receive_contact_requests` del destino (vencido → su 403 real).
 * - Emisor sin acceso vigente o suspendido al aceptar, o contraparte sin acceso
 *   en GET /contact → 403 `counterpart_access_unavailable`: es acceso
 *   insuficiente para la operación y no distingue vencido/suspendido/inactivo,
 *   que es estado privado de la otra parte.
 * - GET /contact sobre una solicitud no aceptada → 409
 *   `contact_request_not_accepted` (estado, no permiso; el participante ya ve el
 *   estado en el detalle).
 * - El contrato no define body para aceptar/rechazar: no hay motivo de rechazo y
 *   cualquier campo enviado es 422.
 *
 * Decisiones del lote A:
 * - Destino de otro perfil de la misma cuenta → 422 (validation_failed en
 *   target_profile_id): el directorio solo excluye al perfil actor, así que ese
 *   perfil puede ser visible; un 404 contradiría lo que el usuario ya ve y no hay
 *   nada que ocultar porque ambos son suyos.
 * - Cancelar no devuelve cuota: la cuota mide solicitudes enviadas en el mes;
 *   devolverla permitiría enviar/cancelar en bucle notificando al destino.
 * - Cancelar exige `can_reject_pending_requests` (acceso vencido sí, suspensión
 *   no; D21): retirar una pendiente no inicia contacto. Listar y ver exigen
 *   `can_view_history` (D21: con acceso vencido se lee el historial).
 * - Idempotencia: la reproducción re-evalúa acceso; solo tolera
 *   `capability_limit_reached` porque reproducir no consume cuota (la solicitud
 *   original puede ser la que agotó el límite). Cualquier otra denegación
 *   vigente (vencido, suspendido, inactivo) responde su 403 real.
 */
final class ContactRequestService
{
    public const OPERATION_CREATE = 'contact_requests.create';

    public function __construct(
        private readonly ProfileService $profileService,
        private readonly AccessService $access,
        private readonly ProfileRepository $profiles,
        private readonly AccessGrantRepository $grants,
        private readonly ContactRequestRepository $requests,
        private readonly ContactUsageRepository $usage,
        private readonly IdempotencyRepository $idempotency,
        private readonly AvailabilityRepository $availability,
        private readonly NotificationService $notifications,
        private readonly Clock $clock,
    ) {}

    /**
     * Todo ocurre con la fila del perfil emisor bloqueada: serializa los envíos
     * del mismo emisor (misma key concurrente, pendiente del par y cuota) y
     * también los cambios de grant del admin, que bloquean el perfil primero.
     *
     * @param  array{target_profile_id: string, service_description: string, requested_from: string|null, requested_to: string|null, requested_city_id: int|null}  $data
     * @param  Closure(ContactRequest): array<string, mixed>  $render
     */
    public function create(
        User $actor,
        string $profilePublicId,
        array $data,
        ?string $idempotencyKey,
        Closure $render,
    ): IdempotentResponse {
        $profile = $this->profileService->owned($actor, $profilePublicId);
        // Una categoría receptora nunca envía: no es un límite alcanzado (AccessService
        // lo reportaría como capability_limit_reached con limit "unlimited").
        if ($profile->category !== ProfileCategory::TourismCompany) {
            throw new ApiProblemException(
                403,
                'capability_not_available',
                __('Esta categoría de perfil no puede enviar solicitudes de contacto.'),
            );
        }
        $requestHash = $this->payloadHash($data);

        try {
            return DB::transaction(function () use ($actor, $profile, $data, $idempotencyKey, $requestHash, $render): IdempotentResponse {
                $sender = $this->profiles->lockById($profile->id);

                if ($idempotencyKey !== null) {
                    $stored = $this->idempotency->find($sender, $actor, self::OPERATION_CREATE, $idempotencyKey);

                    if ($stored !== null) {
                        $this->assertReplayAllowed($actor, $sender);

                        if (! hash_equals($stored['request_hash'], $requestHash)) {
                            throw new ApiProblemException(
                                409,
                                'idempotency_conflict',
                                __('La clave de idempotencia ya fue usada con otro contenido.'),
                            );
                        }

                        return new IdempotentResponse($stored['response_status'], $stored['response_body'], true);
                    }
                }

                $this->access->assertAllowed($actor, $sender, AccessService::CAPABILITY_SEND_CONTACT_REQUESTS);
                $now = $this->clock->now();
                $recipient = $this->profiles->findDirectoryTarget($sender, $data['target_profile_id'], $now);

                if ($recipient === null) {
                    throw new ApiProblemException(404, 'not_found', __('El recurso solicitado no existe.'));
                }
                if ($recipient->owner_user_id === $sender->owner_user_id) {
                    throw new ApiProblemException(422, 'validation_failed', __('Los datos enviados no son válidos.'), [
                        'target_profile_id' => [__('No podés enviar una solicitud a otro perfil de tu cuenta.')],
                    ]);
                }

                $this->assertRequestedRange($data, $now);
                $this->assertPairAllowsNewRequest($sender, $recipient, $now);
                $this->consumeQuota($sender, $now);

                $created = $this->requests->create([
                    'sender_profile_id' => $sender->id,
                    'recipient_profile_id' => $recipient->id,
                    'service_description' => $data['service_description'],
                    'requested_from' => $data['requested_from'],
                    'requested_to' => $data['requested_to'],
                    'requested_city_id' => $data['requested_city_id'],
                    'status' => ContactRequestStatus::Pending,
                    'availability_status' => $this->availabilityStatus($recipient, $data),
                    'expires_at' => $now->addHours((int) config('contactur.contact_requests.expiration_hours')),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                // T19: en la transacción; un replay idempotente vuelve antes y la clave
                // `contact_request_received:{id}` cubre cualquier otro reintento.
                $this->notifications->contactRequestReceived($created, $sender, $recipient);
                $this->prepareForResponse(new Collection([$created]), $now);
                $body = $render($created);

                if ($idempotencyKey !== null) {
                    $this->idempotency->create(
                        $sender,
                        $actor,
                        self::OPERATION_CREATE,
                        $idempotencyKey,
                        $requestHash,
                        201,
                        $body,
                        $now,
                    );
                }

                return new IdempotentResponse(201, $body, false);
            });
        } catch (UniqueConstraintViolationException $exception) {
            // Respaldo del índice único parcial si una escritura escapara al lock del emisor.
            if (str_contains($exception->getMessage(), 'contact_requests_pending_pair_unique')) {
                throw new ApiProblemException(409, 'contact_request_pending', __('Ya existe una solicitud pendiente para este perfil.'));
            }

            throw $exception;
        }
    }

    /** @return array{items: Collection<int, ContactRequest>, next_cursor: string|null} */
    public function list(
        User $actor,
        string $profilePublicId,
        ?string $direction,
        ?ContactRequestStatus $status,
        ?int $cursor,
        int $perPage,
    ): array {
        $profile = $this->participantProfile($actor, $profilePublicId, AccessService::CAPABILITY_VIEW_HISTORY);
        $now = $this->clock->now();
        $page = $this->requests->listForParticipant($profile, $direction, $status, $cursor, $perPage, $now);
        $this->prepareForResponse($page['items'], $now);

        return $page;
    }

    public function show(User $actor, string $profilePublicId, string $requestPublicId): ContactRequest
    {
        $profile = $this->participantProfile($actor, $profilePublicId, AccessService::CAPABILITY_VIEW_HISTORY);
        $request = $this->requests->findForParticipant($profile, $requestPublicId);

        if ($request === null) {
            throw new ApiProblemException(404, 'not_found', __('El recurso solicitado no existe.'));
        }

        $this->prepareForResponse(new Collection([$request]), $this->clock->now());

        return $request;
    }

    public function cancel(User $actor, string $profilePublicId, string $requestPublicId): ContactRequest
    {
        $profile = $this->participantProfile($actor, $profilePublicId, AccessService::CAPABILITY_REJECT_PENDING_REQUESTS);

        return DB::transaction(function () use ($profile, $requestPublicId): ContactRequest {
            $request = $this->requests->findForParticipant($profile, $requestPublicId, lock: true);

            if ($request === null) {
                throw new ApiProblemException(404, 'not_found', __('El recurso solicitado no existe.'));
            }
            if ($request->sender_profile_id !== $profile->id) {
                throw new ApiProblemException(403, 'forbidden', __('Solo el perfil emisor puede cancelar la solicitud.'));
            }

            $now = $this->clock->now();
            if ($request->effectiveStatus($now) !== ContactRequestStatus::Pending) {
                throw new ApiProblemException(409, 'contact_request_not_pending', __('La solicitud ya no está pendiente.'));
            }

            $this->requests->cancel($request, $now);
            $this->prepareForResponse(new Collection([$request]), $now);

            return $request;
        });
    }

    /**
     * R14: solo el destino, sobre una pendiente no vencida, con acceso vigente de
     * ambas partes. La fila bloqueada serializa aceptar/rechazar/cancelar y el job:
     * exactamente una transición gana y las demás ven un estado terminal (409).
     * Aceptar no toca el calendario ni confirma contratación (D11).
     */
    public function accept(User $actor, string $profilePublicId, string $requestPublicId): ContactRequest
    {
        $profile = $this->participantProfile($actor, $profilePublicId, AccessService::CAPABILITY_REJECT_PENDING_REQUESTS);

        return DB::transaction(function () use ($actor, $profile, $requestPublicId): ContactRequest {
            [$request, $now] = $this->lockPendingForRecipient($profile, $requestPublicId);
            $this->access->assertAllowed($actor, $profile, AccessService::CAPABILITY_RECEIVE_CONTACT_REQUESTS);
            $participants = $this->requests->participants($request, withContact: false);
            $this->assertCounterpartActive($participants[$request->sender_profile_id] ?? null);

            $this->requests->respond($request, ContactRequestStatus::Accepted, $now);
            // T19: fila in-app en esta transacción; correo y broadcast después del commit.
            $sender = $participants[$request->sender_profile_id] ?? null;
            if ($sender instanceof Profile) {
                $this->notifications->contactRequestResponded(NotificationType::ContactRequestAccepted, $request, $sender, $profile);
            }
            $this->prepareForResponse(new Collection([$request]), $now);

            return $request;
        });
    }

    /** R14/D21: rechazar sigue permitido con acceso vencido; con suspensión no. */
    public function reject(User $actor, string $profilePublicId, string $requestPublicId): ContactRequest
    {
        $profile = $this->participantProfile($actor, $profilePublicId, AccessService::CAPABILITY_REJECT_PENDING_REQUESTS);

        return DB::transaction(function () use ($profile, $requestPublicId): ContactRequest {
            [$request, $now] = $this->lockPendingForRecipient($profile, $requestPublicId);

            $this->requests->respond($request, ContactRequestStatus::Rejected, $now);
            // T19: fila in-app en esta transacción; correo y broadcast después del commit.
            $sender = $this->requests->participants($request, withContact: false)[$request->sender_profile_id] ?? null;
            if ($sender instanceof Profile) {
                $this->notifications->contactRequestResponded(NotificationType::ContactRequestRejected, $request, $sender, $profile);
            }
            $this->prepareForResponse(new Collection([$request]), $now);

            return $request;
        });
    }

    /**
     * R16: contactos declarados de ambos perfiles, solo para participantes de una
     * `accepted` con las dos partes con acceso vigente y sin suspensión (cuenta,
     * perfil o grant). El estado se reevalúa en cada consulta: perder acceso
     * después del match vuelve a ocultar los datos.
     */
    public function contacts(User $actor, string $profilePublicId, string $requestPublicId): MatchedContacts
    {
        $profile = $this->profileService->owned($actor, $profilePublicId);
        $this->assertActiveAccess($actor, $profile);
        $request = $this->requests->findForParticipant($profile, $requestPublicId);

        if ($request === null) {
            throw new ApiProblemException(404, 'not_found', __('El recurso solicitado no existe.'));
        }
        if ($request->effectiveStatus($this->clock->now()) !== ContactRequestStatus::Accepted) {
            throw new ApiProblemException(
                409,
                'contact_request_not_accepted',
                __('Los datos de contacto solo están disponibles tras aceptar la solicitud.'),
            );
        }

        $participants = $this->requests->participants($request, withContact: true);
        $counterpartId = $request->sender_profile_id === $profile->id
            ? $request->recipient_profile_id
            : $request->sender_profile_id;
        $self = $participants[$profile->id] ?? null;
        $counterpart = $participants[$counterpartId] ?? null;

        if ($self === null || $counterpart === null) {
            throw new ApiProblemException(404, 'not_found', __('El recurso solicitado no existe.'));
        }
        $this->assertCounterpartActive($counterpart);

        return new MatchedContacts($request->public_id, $self, $counterpart);
    }

    /**
     * R15: job idempotente. Materializa en lotes atómicos las `pending` con
     * expires_at <= now; una segunda corrida no encuentra filas. Devuelve los
     * public_id que cambiaron en esta corrida.
     *
     * T19/P23: cada lote y sus `contact_request_expired` (emisor y destino) van en
     * la misma transacción. Solo se notifican las filas que cambiaron en este lote
     * y la clave `contact_request_expired:{id}:{rol}` impide duplicar aun si otra
     * vía (materialización al crear) ya registró el vencimiento.
     *
     * @return list<string>
     */
    public function expireDue(int $batchSize): array
    {
        $now = $this->clock->now();
        $expired = [];

        do {
            $batch = DB::transaction(function () use ($now, $batchSize): array {
                $batch = $this->requests->expireDue($now, $batchSize);

                foreach ($this->requests->withParticipants($batch) as $request) {
                    $this->notifications->contactRequestExpired($request, $request->sender, $request->recipient);
                }

                return $batch;
            });
            array_push($expired, ...$batch);
        } while (count($batch) === $batchSize);

        return $expired;
    }

    /**
     * Fila bloqueada y validada para que el destino responda. Tercero → 404,
     * emisor → 403, no pendiente (efectivo) → 409. El instante devuelto es el
     * mismo con que se evaluó el estado y se guarda en responded_at.
     *
     * @return array{ContactRequest, CarbonImmutable}
     */
    private function lockPendingForRecipient(Profile $profile, string $requestPublicId): array
    {
        $request = $this->requests->findForParticipant($profile, $requestPublicId, lock: true);

        if ($request === null) {
            throw new ApiProblemException(404, 'not_found', __('El recurso solicitado no existe.'));
        }
        if ($request->recipient_profile_id !== $profile->id) {
            throw new ApiProblemException(403, 'forbidden', __('Solo el perfil destino puede responder la solicitud.'));
        }

        $now = $this->clock->now();
        if ($request->effectiveStatus($now) !== ContactRequestStatus::Pending) {
            throw new ApiProblemException(409, 'contact_request_not_pending', __('La solicitud ya no está pendiente.'));
        }

        return [$request, $now];
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
     * La otra parte evaluada con su propio owner: grant efectivo `active` y sin
     * suspensión de cuenta ni de perfil. No se revela cuál de esos falló.
     */
    private function assertCounterpartActive(?Profile $counterpart): void
    {
        $owner = $counterpart?->getRelation('owner');

        if ($counterpart === null || ! $owner instanceof User || ! $this->access->evaluate($owner, $counterpart)->allowed) {
            throw new ApiProblemException(
                403,
                'counterpart_access_unavailable',
                __('La otra parte no tiene acceso vigente para concretar el contacto.'),
            );
        }
    }

    private function participantProfile(User $actor, string $profilePublicId, string $capability): Profile
    {
        $profile = $this->profileService->owned($actor, $profilePublicId);
        $this->access->assertAllowed($actor, $profile, $capability);

        return $profile;
    }

    private function assertReplayAllowed(User $actor, Profile $sender): void
    {
        $evaluation = $this->access->evaluate($actor, $sender, AccessService::CAPABILITY_SEND_CONTACT_REQUESTS);
        $onlyQuotaExhausted = $evaluation->denialCode === 'capability_limit_reached'
            && $sender->category === ProfileCategory::TourismCompany;

        if (! $evaluation->allowed && ! $onlyQuotaExhausted) {
            $this->access->assertAllowed($actor, $sender, AccessService::CAPABILITY_SEND_CONTACT_REQUESTS);
        }
    }

    /**
     * D13: una aceptación previa bloquea nuevas solicitudes al mismo perfil; una
     * pendiente vigente también; tras rechazo/expiración/cancelación se espera el
     * cooldown desde el cierre más reciente. Una pendiente vencida por reloj se
     * materializa como `expired` para liberar el índice único parcial.
     */
    private function assertPairAllowsNewRequest(Profile $sender, Profile $recipient, CarbonImmutable $now): void
    {
        $history = $this->requests->lockPair($sender, $recipient);
        $latestClosure = null;

        foreach ($history as $previous) {
            $effective = $previous->effectiveStatus($now);

            if ($effective === ContactRequestStatus::Accepted) {
                throw new ApiProblemException(
                    409,
                    'contact_request_already_accepted',
                    __('Ya existe una solicitud aceptada con este perfil.'),
                );
            }
            if ($effective === ContactRequestStatus::Pending) {
                throw new ApiProblemException(
                    409,
                    'contact_request_pending',
                    __('Ya existe una solicitud pendiente para este perfil.'),
                );
            }
            if ($previous->status === ContactRequestStatus::Pending) {
                $this->requests->markExpired($previous, $now);
                // T19: misma clave que el job; si el job ya no la ve, se notifica acá.
                $this->notifications->contactRequestExpired($previous, $sender, $recipient);
            }

            $closedAt = match ($effective) {
                ContactRequestStatus::Rejected => $previous->responded_at ?? $previous->updated_at,
                ContactRequestStatus::Cancelled => $previous->cancelled_at ?? $previous->updated_at,
                default => $previous->expires_at,
            };
            if ($latestClosure === null || $closedAt->greaterThan($latestClosure)) {
                $latestClosure = $closedAt;
            }
        }

        if ($latestClosure === null) {
            return;
        }

        $retryAt = $latestClosure->addHours((int) config('contactur.contact_requests.resend_cooldown_hours'));
        if ($now->lessThan($retryAt)) {
            throw new ApiProblemException(
                409,
                'contact_request_cooldown',
                __('Todavía no podés reenviar una solicitud a este perfil.'),
                ['retry_after' => [$retryAt->utc()->toISOString()]],
            );
        }
    }

    /**
     * Chequeo transaccional de cuota (D22): null ilimitado, 0 bloqueado. La fila
     * del mes de negocio se crea si falta y se bloquea antes de comparar e
     * incrementar; así /access refleja el uso real sin tocar AccessService.
     */
    private function consumeQuota(Profile $sender, CarbonImmutable $now): void
    {
        $grant = $this->grants->findForProfile($sender);
        $limit = $grant?->monthly_contact_limit;
        $monthStart = $now->setTimezone(AvailabilityDay::BUSINESS_TIMEZONE)->startOfMonth()->toDateString();
        $used = $this->usage->lockMonth($sender, $monthStart, $now);

        if ($limit !== null && $used >= $limit) {
            throw new ApiProblemException(403, 'capability_limit_reached', __('Se alcanzó el límite de la capacidad solicitada.'), [
                'capability' => [AccessService::CAPABILITY_SEND_CONTACT_REQUESTS],
                'limit' => [(string) $limit],
            ]);
        }

        $this->usage->increment($sender, $monthStart, $now);
    }

    /**
     * Fechas de contexto: no pasadas en fecha de negocio. El largo máximo y el
     * orden ya los valida el controller.
     *
     * @param  array{requested_from: string|null, requested_to: string|null}  $data
     */
    private function assertRequestedRange(array $data, CarbonImmutable $now): void
    {
        $today = $now->setTimezone(AvailabilityDay::BUSINESS_TIMEZONE)->toDateString();

        if ($data['requested_from'] !== null && $data['requested_from'] < $today) {
            throw new ApiProblemException(422, 'validation_failed', __('Los datos enviados no son válidos.'), [
                'requested_from' => [__('La fecha no puede ser anterior a hoy.')],
            ]);
        }
    }

    /**
     * Informativo y fijado al crear (D11): `available` si todos los días pedidos
     * están habilitados en una misma ciudad (la pedida, si la hay). Nunca reserva.
     *
     * @param  array{requested_from: string|null, requested_to: string|null, requested_city_id: int|null}  $data
     */
    private function availabilityStatus(Profile $recipient, array $data): string
    {
        if ($data['requested_from'] === null || $data['requested_to'] === null) {
            return ContactRequest::AVAILABILITY_UNSPECIFIED;
        }

        $requestedDays = (int) CarbonImmutable::parse($data['requested_from'])
            ->diffInDays(CarbonImmutable::parse($data['requested_to'])) + 1;
        $days = $this->availability->availableDays(
            $recipient,
            $data['requested_from'],
            $data['requested_to'],
            $data['requested_city_id'],
        );
        $bestCity = $days->countBy(static fn (AvailabilityDay $day): int => $day->city_id)->max() ?? 0;

        return $bestCity >= $requestedDays
            ? ContactRequest::AVAILABILITY_AVAILABLE
            : ContactRequest::AVAILABILITY_UNAVAILABLE;
    }

    /**
     * Relaciones por lote, estado efectivo y badge `verified` de cada perfil con
     * una sola consulta para todo el lote.
     *
     * @param  Collection<int, ContactRequest>  $requests
     */
    private function prepareForResponse(Collection $requests, CarbonImmutable $now): void
    {
        if ($requests->isEmpty()) {
            return;
        }

        $this->requests->loadForResponse($requests);
        $profileIds = [];
        foreach ($requests as $request) {
            $profileIds[$request->sender_profile_id] = $request->sender_profile_id;
            $profileIds[$request->recipient_profile_id] = $request->recipient_profile_id;
        }
        $verified = $this->profiles->verifiedFlags(array_values($profileIds), [ProfileDocument::STATUS_APPROVED], $now);

        foreach ($requests as $request) {
            $request->setAttribute('effective_status', $request->effectiveStatus($now)->value);
            $request->syncOriginalAttribute('effective_status');

            foreach ([$request->sender, $request->recipient] as $party) {
                $party->setAttribute('verified', $verified[$party->id] ?? false);
                $party->syncOriginalAttribute('verified');
            }
        }
    }

    /**
     * Hash del payload normalizado: claves fijas en orden fijo, opcionales en null
     * y tipos canónicos, para que el mismo contenido produzca el mismo hash.
     *
     * @param  array{target_profile_id: string, service_description: string, requested_from: string|null, requested_to: string|null, requested_city_id: int|null}  $data
     */
    private function payloadHash(array $data): string
    {
        return hash('sha256', json_encode([
            'target_profile_id' => $data['target_profile_id'],
            'service_description' => $data['service_description'],
            'requested_from' => $data['requested_from'],
            'requested_to' => $data['requested_to'],
            'requested_city_id' => $data['requested_city_id'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
