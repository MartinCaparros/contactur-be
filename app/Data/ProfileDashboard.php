<?php

namespace App\Data;

/**
 * Resultado de GET /profiles/{profile}/dashboard (T21). `metrics` conserva el
 * orden de DashboardMetricKey; una métrica que no aplica a la categoría no está
 * (no se informa 0).
 */
final readonly class ProfileDashboard
{
    /**
     * @param  array<string, int>  $metrics  clave de DashboardMetricKey → valor ≥ 0
     */
    public function __construct(
        public string $profileId,
        public array $metrics,
        public AccessEvaluation $access,
    ) {}
}
