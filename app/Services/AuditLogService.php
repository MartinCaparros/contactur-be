<?php

namespace App\Services;

use App\Exceptions\ApiProblemException;
use App\Models\AuditLog;
use App\Models\User;
use App\Repositories\AuditLogRepository;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

final class AuditLogService
{
    public function __construct(private readonly AuditLogRepository $auditLogs) {}

    /** @return array{items: Collection<int, AuditLog>, next_cursor: string|null, total: int} */
    public function list(
        User $actor,
        ?string $action,
        ?string $targetType,
        ?CarbonImmutable $dateFrom,
        ?CarbonImmutable $dateTo,
        ?int $cursor,
        int $perPage,
    ): array {
        if ($actor->system_role !== User::ROLE_ADMIN || $actor->status !== User::STATUS_ACTIVE) {
            throw new ApiProblemException(403, 'forbidden', __('No tenés permisos para realizar esta acción.'));
        }

        return $this->auditLogs->list($action, $targetType, $dateFrom, $dateTo, $cursor, $perPage);
    }
}
