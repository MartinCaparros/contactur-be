<?php

namespace App\Http\Controllers;

use App\Http\Resources\AuditLogsResource;
use App\Services\AuditLogService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

final class AdminAuditLogController extends ApiController
{
    public function __construct(private readonly AuditLogService $auditLogs) {}

    public function index(Request $request): AuditLogsResource
    {
        $data = $this->validatePayload($request, [
            'action' => ['sometimes', 'string', 'max:100'],
            'target_type' => ['sometimes', 'string', 'max:100'],
            'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'cursor' => ['sometimes', 'string', 'regex:/^[0-9]+$/', 'max:20'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ], ['action', 'target_type', 'date_from', 'date_to', 'cursor', 'per_page']);
        $perPage = isset($data['per_page']) ? (int) $data['per_page'] : 20;
        $result = $this->auditLogs->list(
            actor: $this->authenticatedUser($request),
            action: isset($data['action']) ? (string) $data['action'] : null,
            targetType: isset($data['target_type']) ? (string) $data['target_type'] : null,
            dateFrom: isset($data['date_from']) ? CarbonImmutable::parse((string) $data['date_from']) : null,
            dateTo: isset($data['date_to']) ? CarbonImmutable::parse((string) $data['date_to']) : null,
            cursor: isset($data['cursor']) ? (int) $data['cursor'] : null,
            perPage: $perPage,
        );

        return new AuditLogsResource([...$result, 'per_page' => $perPage]);
    }
}
