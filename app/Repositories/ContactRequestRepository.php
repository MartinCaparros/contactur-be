<?php

namespace App\Repositories;

use App\Enums\ContactRequestStatus;
use App\Models\ContactRequest;
use App\Models\Profile;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class ContactRequestRepository
{
    public const DIRECTION_SENT = 'sent';

    public const DIRECTION_RECEIVED = 'received';

    /**
     * Relaciones de SafeProfile para emisor y destino. Nunca `contact` ni `owner`:
     * contact_email, phone y el email de la cuenta no se cargan (R12/R13).
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
    public function create(array $attributes): ContactRequest
    {
        return ContactRequest::query()->create($attributes);
    }

    /**
     * Historial completo del par (emisor, destino) bloqueado para decidir
     * pendiente única, aceptación previa y cooldown en la misma transacción.
     *
     * @return Collection<int, ContactRequest>
     */
    public function lockPair(Profile $sender, Profile $recipient): Collection
    {
        return ContactRequest::query()
            ->where('sender_profile_id', $sender->id)
            ->where('recipient_profile_id', $recipient->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * T19: solicitudes recién vencidas con emisor y destino (sin `contact` ni
     * `owner`) para registrar sus notificaciones en la misma transacción.
     *
     * @param  list<string>  $publicIds
     * @return Collection<int, ContactRequest>
     */
    public function withParticipants(array $publicIds): Collection
    {
        if ($publicIds === []) {
            return new Collection;
        }

        return ContactRequest::query()
            ->whereIn('public_id', $publicIds)
            ->with(['sender', 'recipient'])
            ->orderBy('id')
            ->get();
    }

    /** T19: datos del correo `contact_request_received` (servicio, fechas, ciudad). */
    public function findForNotificationEmail(string $publicId): ?ContactRequest
    {
        return ContactRequest::query()->where('public_id', $publicId)->with('requestedCity')->first();
    }

    /** Materializa una pendiente vencida por reloj para liberar el índice único parcial. */
    public function markExpired(ContactRequest $request, CarbonImmutable $now): void
    {
        $request->forceFill([
            'status' => ContactRequestStatus::Expired,
            'updated_at' => $now,
        ])->save();
    }

    public function cancel(ContactRequest $request, CarbonImmutable $now): void
    {
        $request->forceFill([
            'status' => ContactRequestStatus::Cancelled,
            'cancelled_at' => $now,
            'updated_at' => $now,
        ])->save();
    }

    /** Respuesta del destino (accepted/rejected) sobre la fila ya bloqueada. */
    public function respond(ContactRequest $request, ContactRequestStatus $status, CarbonImmutable $now): void
    {
        $request->forceFill([
            'status' => $status,
            'responded_at' => $now,
            'updated_at' => $now,
        ])->save();
    }

    /**
     * R15: un lote atómico `pending` con expires_at <= now → `expired`. SKIP LOCKED
     * saltea filas que una respuesta/cancelación tiene bloqueadas (se resuelven allí
     * o en la próxima corrida) y el `status = 'pending'` externo hace que una fila
     * ya terminal nunca vuelva a cambiar. Devuelve solo los public_id que cambiaron
     * en esta sentencia: reintentar no repite ninguno.
     *
     * @return list<string>
     */
    public function expireDue(CarbonImmutable $now, int $limit): array
    {
        $at = $now->utc()->format('Y-m-d H:i:s.uP');
        $rows = DB::select(<<<'SQL'
            UPDATE contact_requests
            SET status = ?, updated_at = ?
            WHERE status = ?
              AND id IN (
                SELECT id FROM contact_requests
                WHERE status = ? AND expires_at <= ?
                ORDER BY id
                LIMIT ?
                FOR UPDATE SKIP LOCKED
              )
            RETURNING public_id
            SQL, [
            ContactRequestStatus::Expired->value,
            $at,
            ContactRequestStatus::Pending->value,
            ContactRequestStatus::Pending->value,
            $at,
            $limit,
        ], false);

        return array_values(array_map(static fn (mixed $row): string => (string) data_get($row, 'public_id'), $rows));
    }

    /**
     * Emisor y destino como modelos aparte (no las relaciones que serializa
     * ContactRequestResource) con `owner` para evaluar acceso y suspensión, y
     * `contact` solo para GET /contact. Indexados por id interno.
     *
     * @return array<int, Profile>
     */
    public function participants(ContactRequest $request, bool $withContact): array
    {
        return Profile::query()
            ->with($withContact ? ['owner', 'contact'] : ['owner'])
            ->whereIn('id', [$request->sender_profile_id, $request->recipient_profile_id])
            ->get()
            ->keyBy('id')
            ->all();
    }

    public function findForParticipant(Profile $participant, string $publicId, bool $lock = false): ?ContactRequest
    {
        return ContactRequest::query()
            ->where('public_id', $publicId)
            ->where(fn (Builder $query) => $this->constrainParticipant($query, $participant, null))
            ->when($lock, fn (Builder $query) => $query->lockForUpdate())
            ->first();
    }

    /**
     * Solo filas donde el perfil es emisor o destino. `status` filtra por estado
     * efectivo: `pending` excluye las vencidas por reloj y `expired` las incluye.
     * Orden id desc con cursor numérico (id < cursor), igual que los listados admin.
     *
     * @return array{items: Collection<int, ContactRequest>, next_cursor: string|null}
     */
    public function listForParticipant(
        Profile $participant,
        ?string $direction,
        ?ContactRequestStatus $status,
        ?int $cursor,
        int $perPage,
        CarbonImmutable $now,
    ): array {
        $items = ContactRequest::query()
            ->where(fn (Builder $query) => $this->constrainParticipant($query, $participant, $direction))
            ->when($status !== null, fn (Builder $query) => $this->constrainEffectiveStatus($query, $status, $now))
            ->when($cursor !== null, fn (Builder $query) => $query->where('id', '<', $cursor))
            ->orderByDesc('id')
            ->limit($perPage + 1)
            ->get();
        $hasMore = $items->count() > $perPage;
        /** @var Collection<int, ContactRequest> $page */
        $page = $items->take($perPage)->values();

        return [
            'items' => $page,
            'next_cursor' => $hasMore ? (string) $page->last()?->id : null,
        ];
    }

    /**
     * T21: conteo por estado EFECTIVO de las solicitudes donde el perfil es emisor
     * o destino, en una sola consulta (COUNT … FILTER). Mismo criterio que
     * constrainEffectiveStatus y ContactRequest::effectiveStatus: una `pending` con
     * expires_at <= now cuenta como `expired` aunque el job todavía no la haya
     * materializado. `cancelled` no se informa en el dashboard.
     *
     * @return array{pending: int, accepted: int, rejected: int, expired: int}
     */
    public function effectiveStatusCountsForParticipant(Profile $participant, CarbonImmutable $now): array
    {
        // Con offset explícito: el binding por defecto de Carbon descarta la zona.
        $at = $now->utc()->format('Y-m-d H:i:s.uP');
        $pending = ContactRequestStatus::Pending->value;
        $row = ContactRequest::query()
            ->where(fn (Builder $query) => $this->constrainParticipant($query, $participant, null))
            ->selectRaw(
                'COUNT(*) FILTER (WHERE status = ? AND expires_at > ?) AS pending, '
                .'COUNT(*) FILTER (WHERE status = ?) AS accepted, '
                .'COUNT(*) FILTER (WHERE status = ?) AS rejected, '
                .'COUNT(*) FILTER (WHERE status = ? OR (status = ? AND expires_at <= ?)) AS expired',
                [
                    $pending,
                    $at,
                    ContactRequestStatus::Accepted->value,
                    ContactRequestStatus::Rejected->value,
                    ContactRequestStatus::Expired->value,
                    $pending,
                    $at,
                ],
            )
            ->toBase()
            ->first();

        return [
            'pending' => (int) data_get($row, 'pending', 0),
            'accepted' => (int) data_get($row, 'accepted', 0),
            'rejected' => (int) data_get($row, 'rejected', 0),
            'expired' => (int) data_get($row, 'expired', 0),
        ];
    }

    /**
     * Carga por lote (sin N+1) lo que serializa ContactRequestResource.
     *
     * @param  Collection<int, ContactRequest>  $requests
     */
    public function loadForResponse(Collection $requests): void
    {
        $requests->load([
            'requestedCity',
            'sender' => static fn ($query) => $query->with(self::SAFE_PROFILE_RELATIONS),
            'recipient' => static fn ($query) => $query->with(self::SAFE_PROFILE_RELATIONS),
        ]);
    }

    /** @param Builder<ContactRequest> $query */
    private function constrainParticipant(Builder $query, Profile $participant, ?string $direction): void
    {
        match ($direction) {
            self::DIRECTION_SENT => $query->where('sender_profile_id', $participant->id),
            self::DIRECTION_RECEIVED => $query->where('recipient_profile_id', $participant->id),
            default => $query
                ->where('sender_profile_id', $participant->id)
                ->orWhere('recipient_profile_id', $participant->id),
        };
    }

    /** @param Builder<ContactRequest> $query */
    private function constrainEffectiveStatus(Builder $query, ContactRequestStatus $status, CarbonImmutable $now): void
    {
        // Con offset explícito: el binding por defecto de Carbon descarta la zona.
        $at = $now->utc()->format('Y-m-d H:i:s.uP');

        match ($status) {
            ContactRequestStatus::Pending => $query
                ->where('status', ContactRequestStatus::Pending->value)
                ->where('expires_at', '>', $at),
            ContactRequestStatus::Expired => $query->where(fn (Builder $inner) => $inner
                ->where('status', ContactRequestStatus::Expired->value)
                ->orWhere(fn (Builder $pending) => $pending
                    ->where('status', ContactRequestStatus::Pending->value)
                    ->where('expires_at', '<=', $at))),
            default => $query->where('status', $status->value),
        };
    }
}
