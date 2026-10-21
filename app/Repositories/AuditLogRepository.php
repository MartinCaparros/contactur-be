<?php

namespace App\Repositories;

use App\Models\AuditLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

final class AuditLogRepository
{
    /** @param array<string, mixed> $safeChanges */
    public function create(
        User $actor,
        string $targetType,
        string $targetId,
        string $action,
        string $reason,
        array $safeChanges,
        string $requestId,
        CarbonImmutable $createdAt,
    ): AuditLog {
        return AuditLog::query()->create([
            'public_id' => (string) Str::ulid(),
            'actor_user_id' => $actor->id,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'action' => $action,
            'reason' => $reason,
            'safe_changes' => $safeChanges,
            'request_id' => $requestId,
            'created_at' => $createdAt,
        ]);
    }

    /**
     * @return array{items: Collection<int, AuditLog>, next_cursor: string|null, total: int}
     */
    public function list(
        ?string $action,
        ?string $targetType,
        ?CarbonImmutable $dateFrom,
        ?CarbonImmutable $dateTo,
        ?int $cursor,
        int $perPage,
    ): array {
        $base = AuditLog::query()
            ->when($action !== null, fn ($query) => $query->where('action', $action))
            ->when($targetType !== null, fn ($query) => $query->where('target_type', $targetType))
            ->when($dateFrom !== null, fn ($query) => $query->where('created_at', '>=', $dateFrom->startOfDay()))
            ->when($dateTo !== null, fn ($query) => $query->where('created_at', '<', $dateTo->addDay()->startOfDay()));
        $total = (clone $base)->count();
        $items = $base
            ->when($cursor !== null, fn ($query) => $query->where('id', '<', $cursor))
            ->with('actor:id,public_id')
            ->orderByDesc('id')
            ->limit($perPage + 1)
            ->get();
        $hasMore = $items->count() > $perPage;
        /** @var Collection<int, AuditLog> $page */
        $page = $items->take($perPage)->values();

        return [
            'items' => $page,
            'next_cursor' => $hasMore ? (string) $page->last()?->id : null,
            'total' => $total,
        ];
    }
}
