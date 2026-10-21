<?php

namespace App\Repositories;

use App\Enums\NotificationType;
use App\Models\Profile;
use App\Models\ProfileNotification;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class NotificationRepository
{
    /**
     * Outbox idempotente: si `dedupe_key` ya existe no inserta nada y devuelve
     * null. Una transacción concurrente con la misma clave espera a la otra y, si
     * esa confirma, tampoco inserta.
     *
     * @param  array<string, mixed>  $safeData
     * @return array{id: int, public_id: string}|null
     */
    public function insertIfAbsent(
        int $userId,
        ?int $profileId,
        NotificationType $type,
        array $safeData,
        string $dedupeKey,
        string $emailStatus,
        CarbonImmutable $now,
    ): ?array {
        $row = DB::selectOne(<<<'SQL'
            INSERT INTO profile_notifications
                (public_id, user_id, profile_id, type, safe_data, dedupe_key, email_status, created_at)
            VALUES (?, ?, ?, ?, ?::jsonb, ?, ?, ?)
            ON CONFLICT (dedupe_key) DO NOTHING
            RETURNING id, public_id
            SQL, [
            (string) Str::ulid(),
            $userId,
            $profileId,
            $type->value,
            json_encode((object) $safeData, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $dedupeKey,
            $emailStatus,
            $now->utc()->format('Y-m-d H:i:s.uP'),
        ]);

        return $row === null ? null : [
            'id' => (int) data_get($row, 'id'),
            'public_id' => (string) data_get($row, 'public_id'),
        ];
    }

    /**
     * Correos que siguen `pending` desde antes de $olderThan (el push a la cola
     * falló después del commit o el worker se perdió el job).
     *
     * @return list<int>
     */
    public function pendingEmailIds(CarbonImmutable $olderThan, int $limit): array
    {
        return array_values(array_map('intval', ProfileNotification::query()
            ->where('email_status', ProfileNotification::EMAIL_PENDING)
            ->where('created_at', '<', $olderThan->utc()->format('Y-m-d H:i:s.uP'))
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->all()));
    }

    /**
     * Notificaciones del perfil más las de cuenta de su titular (profile_id null).
     * Nunca las de otro perfil, aunque sea de la misma cuenta (R05).
     *
     * @return array{items: Collection<int, ProfileNotification>, next_cursor: string|null}
     */
    public function listForProfile(Profile $profile, bool $unreadOnly, ?int $cursor, int $perPage): array
    {
        $items = $this->visibleTo($profile)
            ->when($unreadOnly, fn (Builder $query) => $query->whereNull('read_at'))
            ->when($cursor !== null, fn (Builder $query) => $query->where('id', '<', $cursor))
            ->orderByDesc('id')
            ->limit($perPage + 1)
            ->get();
        $hasMore = $items->count() > $perPage;
        /** @var Collection<int, ProfileNotification> $page */
        $page = $items->take($perPage)->values();

        return [
            'items' => $page,
            'next_cursor' => $hasMore ? (string) $page->last()?->id : null,
        ];
    }

    /**
     * T21: no leídas con la misma regla de visibilidad que el centro de
     * notificaciones (propias del perfil + de cuenta del titular). Una de cuenta
     * se lee una sola vez, así que deja de contar en todos los perfiles propios.
     */
    public function unreadCountForProfile(Profile $profile): int
    {
        return $this->visibleTo($profile)->whereNull('read_at')->count();
    }

    public function findVisibleToProfile(Profile $profile, string $publicId): ?ProfileNotification
    {
        return $this->visibleTo($profile)->where('public_id', $publicId)->first();
    }

    /** Idempotente: solo la primera lectura fija read_at; las siguientes lo conservan. */
    public function markRead(ProfileNotification $notification, CarbonImmutable $now): ProfileNotification
    {
        ProfileNotification::query()
            ->whereKey($notification->id)
            ->whereNull('read_at')
            ->update(['read_at' => $now->utc()->format('Y-m-d H:i:s.uP')]);

        return $notification->refresh();
    }

    public function findById(int $id): ?ProfileNotification
    {
        return ProfileNotification::query()->find($id);
    }

    public function lockById(int $id): ?ProfileNotification
    {
        return ProfileNotification::query()->whereKey($id)->lockForUpdate()->first();
    }

    /**
     * Transición condicional del correo: solo sale de `pending`. Devuelve false si
     * otra ejecución ya la resolvió (enviado, omitido o fallido).
     */
    public function resolvePendingEmail(int $id, string $status, ?CarbonImmutable $emailedAt): bool
    {
        return ProfileNotification::query()
            ->whereKey($id)
            ->where('email_status', ProfileNotification::EMAIL_PENDING)
            ->update([
                'email_status' => $status,
                'emailed_at' => $emailedAt?->utc()->format('Y-m-d H:i:s.uP'),
            ]) === 1;
    }

    /** @return Builder<ProfileNotification> */
    private function visibleTo(Profile $profile): Builder
    {
        return ProfileNotification::query()->where(function (Builder $query) use ($profile): void {
            $query->where('profile_id', $profile->id)
                ->orWhere(function (Builder $account) use ($profile): void {
                    $account->whereNull('profile_id')->where('user_id', $profile->owner_user_id);
                });
        });
    }
}
