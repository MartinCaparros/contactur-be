<?php

namespace App\Services;

use App\Contracts\Clock;
use App\Enums\AccessEffectiveState;
use App\Enums\ContactRequestStatus;
use App\Enums\ProfileCategory;
use App\Exceptions\ApiProblemException;
use App\Models\User;
use App\Repositories\AdminMetricsRepository;
use App\Repositories\DocumentRepository;
use App\Repositories\ProfileRepository;
use App\Repositories\SupportInquiryRepository;
use App\Repositories\UserRepository;
use Carbon\CarbonImmutable;

/**
 * Métricas agregadas del backoffice.
 *
 * Servicio propio y no AdminBackofficeService: ese servicio modela la autoridad
 * administrativa sobre `users.status` y `profiles.status`, es decir mutaciones
 * transaccionales con lock, motivo obligatorio y auditoría. Las métricas son lo
 * opuesto: lectura pura, sin transacción, sin auditoría y atravesando cuatro
 * dominios (cuentas, perfiles, documentos y las dos fuentes todavía inexistentes).
 * Meterlas ahí obligaría a ese servicio a depender además de DocumentRepository y
 * mezclaría una frontera de escritura con una de lectura. Acá queda un único lugar
 * donde leer qué significa cada contador y cómo se resuelve la ausencia de fuente.
 *
 * Las métricas no auditan: consultarlas no cambia nada.
 */
final class AdminMetricsService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly ProfileRepository $profiles,
        private readonly DocumentRepository $documents,
        private readonly AdminMetricsRepository $metrics,
        private readonly SupportInquiryRepository $supportInquiries,
        private readonly Clock $clock,
    ) {}

    /**
     * $dateFrom/$dateTo son fechas locales (America/Argentina/Buenos_Aires) y
     * delimitan un rango inclusivo en los dos extremos, igual que en
     * AdminAuditLogController: [date_from 00:00, date_to+1 día 00:00). Cada
     * contador aplica el rango sobre la fecha de creación de la entidad que
     * cuenta; el detalle exacto está documentado en cada método de repositorio.
     *
     * @return array{
     *     users: array<string, int>,
     *     profiles_by_category: array<string, int>,
     *     profiles_by_access_state: array<string, int>,
     *     documents_pending: int,
     *     contact_requests_by_status: array<string, int>,
     *     support_inquiries_pending: int
     * }
     */
    public function summary(User $actor, ?CarbonImmutable $dateFrom, ?CarbonImmutable $dateTo): array
    {
        $this->assertActiveAdmin($actor);
        $createdFrom = $dateFrom?->startOfDay();
        $createdUntil = $dateTo?->addDay()->startOfDay();

        return [
            'users' => $this->zeroFilled(
                [User::STATUS_ACTIVE, User::STATUS_SUSPENDED],
                $this->users->countByStatus($createdFrom, $createdUntil),
            ),
            'profiles_by_category' => $this->zeroFilled(
                array_map(static fn (ProfileCategory $case): string => $case->value, ProfileCategory::cases()),
                $this->profiles->countByCategory($createdFrom, $createdUntil),
            ),
            'profiles_by_access_state' => $this->zeroFilled(
                array_map(static fn (AccessEffectiveState $case): string => $case->value, AccessEffectiveState::cases()),
                $this->profiles->countByEffectiveAccessState($this->clock->now(), $createdFrom, $createdUntil),
            ),
            'documents_pending' => $this->documents->countPending($createdFrom, $createdUntil),
            'contact_requests_by_status' => $this->zeroFilled(
                array_map(static fn (ContactRequestStatus $case): string => $case->value, ContactRequestStatus::cases()),
                $this->metrics->contactRequestsByStatus($createdFrom, $createdUntil),
            ),
            // T19: la tabla existe; consultas `pending` creadas en el rango.
            'support_inquiries_pending' => $this->supportInquiries->countPending($createdFrom, $createdUntil),
        ];
    }

    /**
     * Completa con 0 las claves del enum que no devolvieron filas para que la UI no
     * tenga que inventar las categorías faltantes. Se aplica a los cuatro mapas
     * cuyo enum existe en el código (User::STATUS_*, ProfileCategory,
     * AccessEffectiveState y, desde T17, ContactRequestStatus). Los conteos de
     * solicitudes usan el `status` persistido: una pendiente vencida por reloj
     * cuenta como pending hasta que se materialice (job del lote B o reenvío).
     *
     * @param  array<int, string>  $keys
     * @param  array<string, int>  $counts
     * @return array<string, int>
     */
    private function zeroFilled(array $keys, array $counts): array
    {
        $filled = [];

        foreach ($keys as $key) {
            $filled[$key] = $counts[$key] ?? 0;
        }

        // Un valor fuera del enum sería un dato real que no se puede ocultar: se
        // agrega al final en lugar de descartarlo en silencio.
        foreach ($counts as $key => $total) {
            if (! array_key_exists($key, $filled)) {
                $filled[$key] = $total;
            }
        }

        return $filled;
    }

    private function assertActiveAdmin(User $actor): void
    {
        if ($actor->system_role !== User::ROLE_ADMIN || $actor->status !== User::STATUS_ACTIVE) {
            throw new ApiProblemException(403, 'forbidden', __('No tenés permisos para realizar esta acción.'));
        }
    }
}
