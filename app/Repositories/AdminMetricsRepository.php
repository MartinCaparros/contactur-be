<?php

namespace App\Repositories;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contador de `contact_requests` que el contrato pide para /admin/metrics. El de
 * `support_inquiries` se mudó a SupportInquiryRepository::countPending en T19,
 * cuando la tabla llegó con su migración.
 *
 * La decisión importante es no devolver 0 mientras no haya tabla: 0 afirmaría que
 * se contaron filas y no había ninguna. En su lugar se devuelve la ausencia de
 * dato (mapa vacío) y se pobla sola en cuanto la tabla aparezca, igual que
 * ProfileRepository::blockFutureAvailability hace con `availability_days`.
 *
 * "Fecha" es la fecha de creación de la fila (`created_at`), porque la entidad
 * contada es la solicitud, no su resolución.
 */
final class AdminMetricsRepository
{
    private const CONTACT_REQUESTS_TABLE = 'contact_requests';

    /**
     * Columnas mínimas para poder contar de forma honesta: sin `status` no hay con
     * qué agrupar y sin `created_at` el rango de fechas filtraría por nada.
     */
    private const REQUIRED_COLUMNS = ['status', 'created_at'];

    /**
     * Mapa vacío cuando la fuente no existe: `{}` ya comunica "sin datos" sin
     * inventar claves de un enum cuya feature todavía no está implementada.
     *
     * @return array<string, int>
     */
    public function contactRequestsByStatus(?CarbonImmutable $createdFrom, ?CarbonImmutable $createdUntil): array
    {
        if (! $this->sourceIsCountable(self::CONTACT_REQUESTS_TABLE)) {
            return [];
        }

        $counts = [];
        $rows = DB::table(self::CONTACT_REQUESTS_TABLE)
            ->when($createdFrom !== null, fn ($query) => $query->where('created_at', '>=', $createdFrom))
            ->when($createdUntil !== null, fn ($query) => $query->where('created_at', '<', $createdUntil))
            ->groupBy('status')
            ->selectRaw('status, COUNT(*) AS total')
            ->get();

        foreach ($rows as $row) {
            $counts[(string) $row->status] = (int) $row->total;
        }

        ksort($counts);

        return $counts;
    }

    private function sourceIsCountable(string $table): bool
    {
        if (! Schema::hasTable($table)) {
            return false;
        }

        foreach (self::REQUIRED_COLUMNS as $column) {
            if (! Schema::hasColumn($table, $column)) {
                return false;
            }
        }

        return true;
    }
}
