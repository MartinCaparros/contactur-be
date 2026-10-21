<?php

namespace App\Http\Controllers;

use App\Http\Resources\AdminMetricsResource;
use App\Services\AdminMetricsService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

final class AdminMetricsController extends ApiController
{
    public function __construct(private readonly AdminMetricsService $metrics) {}

    /**
     * El rango se valida igual que en AdminAuditLogController: formato Y-m-d estricto
     * y date_to nunca anterior a date_from.
     */
    public function index(Request $request): AdminMetricsResource
    {
        $data = $this->validatePayload($request, [
            'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ], ['date_from', 'date_to']);

        return new AdminMetricsResource($this->metrics->summary(
            actor: $this->authenticatedUser($request),
            dateFrom: isset($data['date_from']) ? CarbonImmutable::parse((string) $data['date_from']) : null,
            dateTo: isset($data['date_to']) ? CarbonImmutable::parse((string) $data['date_to']) : null,
        ));
    }
}
