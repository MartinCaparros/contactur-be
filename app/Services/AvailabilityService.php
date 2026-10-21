<?php

namespace App\Services;

use App\Contracts\Clock;
use App\Data\AvailabilityMonth;
use App\Exceptions\ApiProblemException;
use App\Models\AvailabilityDay;
use App\Models\Profile;
use App\Models\User;
use App\Repositories\AvailabilityRepository;
use App\Repositories\ProfileRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Calendario transaccional de perfiles receptores (R08/R09, D11, D12).
 *
 * El default es bloqueado: solo existen filas para días habilitados. El PUT
 * recibe estados explícitos por fecha; el servidor nunca invierte un día por su
 * cuenta, así que reaplicar el mismo lote deja exactamente el mismo resultado.
 */
final class AvailabilityService
{
    public function __construct(
        private readonly ProfileService $profileService,
        private readonly AccessService $access,
        private readonly ProfileRepository $profiles,
        private readonly AvailabilityRepository $availability,
        private readonly RealtimeInvalidationService $realtime,
        private readonly Clock $clock,
    ) {}

    /**
     * Perfil propio con calendario: perfil ajeno → 404 (no revela existencia);
     * tourism_company → 403 capability_not_available.
     */
    public function ownedReceiver(User $actor, string $publicId): Profile
    {
        $profile = $this->profileService->owned($actor, $publicId);

        if (! $profile->category->hasAvailability()) {
            throw new ApiProblemException(
                403,
                'capability_not_available',
                __('Esta categoría de perfil no tiene calendario de disponibilidad.'),
            );
        }

        return $profile;
    }

    /**
     * Lectura del propio calendario. No exige acceso vigente: como el resto de
     * las lecturas del owner (D21), un perfil vencido o suspendido puede ver su
     * calendario aunque no pueda publicarlo.
     */
    public function month(Profile $profile, string $month): AvailabilityMonth
    {
        return $this->monthView($profile, $month);
    }

    /**
     * Aplica el lote completo o nada. Orden bajo el lock del perfil: acceso
     * vigente → versión optimista (409) → validación de negocio (422) → escritura.
     *
     * La versión se incrementa una sola vez por lote y solo si el lote cambia algo:
     * un lote idéntico al estado actual responde 200 con la misma versión, así un
     * reintento no provoca 409 espurios a otros editores ni invalidaciones vacías.
     *
     * @param  array<int, array{date: string, available: bool, city_id: int|null}>  $days
     */
    public function replace(User $actor, Profile $profile, int $expectedVersion, array $days): AvailabilityMonth
    {
        return DB::transaction(function () use ($actor, $profile, $expectedVersion, $days): AvailabilityMonth {
            $locked = $this->profiles->lockById($profile->id);
            $evaluation = $this->access->assertAllowed(
                $actor,
                $locked,
                AccessService::CAPABILITY_PUBLISH_AVAILABILITY,
            );

            if ($locked->availability_version !== $expectedVersion) {
                throw new ApiProblemException(
                    409,
                    'stale_availability',
                    __('El calendario cambió; recargalo antes de volver a aplicar.'),
                );
            }

            $today = $this->today();
            $dates = array_values(array_map(static fn (array $day): string => $day['date'], $days));
            $this->assertBatchIsValid($locked, $days, $dates, $today, $evaluation->grant?->city_limit);

            $current = $this->availability->cityIdsByDate($locked, $dates);
            $upserts = [];
            $deletes = [];

            foreach ($days as $day) {
                $currentCityId = $current[$day['date']] ?? null;

                if ($day['available']) {
                    if ($currentCityId !== $day['city_id']) {
                        $upserts[$day['date']] = (int) $day['city_id'];
                    }
                } elseif ($currentCityId !== null) {
                    $deletes[] = $day['date'];
                }
            }

            if ($upserts !== [] || $deletes !== []) {
                $this->availability->delete($locked, $deletes);
                $this->availability->upsert($locked, $upserts);
                $this->profiles->incrementAvailabilityVersion($locked);
                // Solo un lote que sube la versión invalida; sale tras el commit.
                $this->realtime->availabilityChanged($locked, array_merge(array_keys($upserts), $deletes));
            }

            // El contrato devuelve un único mes: se usa el de la fecha más temprana
            // del lote, con el mismo formato que el GET (mes completo).
            return $this->monthView($locked, substr(min($dates), 0, 7));
        });
    }

    /**
     * Valida todo antes de escribir. Los errores por ítem usan el índice que
     * mandó el cliente (days.N.date / days.N.city_id).
     *
     * @param  array<int, array{date: string, available: bool, city_id: int|null}>  $days
     * @param  array<int, string>  $dates
     */
    private function assertBatchIsValid(
        Profile $profile,
        array $days,
        array $dates,
        string $today,
        ?int $cityLimit,
    ): void {
        $profileCityIds = $this->profiles->cityIds($profile);
        $errors = [];
        $batchCityIds = [];

        foreach ($days as $index => $day) {
            // Y-m-d validado en el controller: la comparación lexicográfica es cronológica.
            if ($day['date'] <= $today) {
                $errors['days.'.$index.'.date'][] = __('Solo se pueden editar fechas posteriores a hoy.');
            }

            if ($day['available']) {
                if (! in_array($day['city_id'], $profileCityIds, true)) {
                    $errors['days.'.$index.'.city_id'][] = __('La ciudad no pertenece al perfil.');
                } else {
                    $batchCityIds[] = (int) $day['city_id'];
                }
            }
        }

        if ($errors !== []) {
            throw new ApiProblemException(422, 'validation_failed', __('Los datos enviados no son válidos.'), $errors);
        }

        if ($cityLimit === null) {
            return;
        }

        $resulting = array_unique(array_merge(
            $this->availability->futureCityIdsExcluding($profile, $today, $dates),
            $batchCityIds,
        ));

        if (count($resulting) > $cityLimit) {
            throw new ApiProblemException(422, 'validation_failed', __('Los datos enviados no son válidos.'), [
                'days' => [$cityLimit === 0
                    ? __('El acceso del perfil no permite habilitar días en ninguna ciudad.')
                    : __('La disponibilidad resultante usaría :count ciudades y el límite es :limit.', [
                        'count' => count($resulting),
                        'limit' => $cityLimit,
                    ])],
            ]);
        }
    }

    private function monthView(Profile $profile, string $month): AvailabilityMonth
    {
        $start = CarbonImmutable::parse($month.'-01', AvailabilityDay::BUSINESS_TIMEZONE)->startOfDay();
        $end = $start->endOfMonth()->startOfDay();
        $rows = $this->availability
            ->forRange($profile, $start->toDateString(), $end->toDateString())
            ->keyBy('date');
        $days = [];

        for ($day = $start; $day->lessThanOrEqualTo($end); $day = $day->addDay()) {
            $date = $day->toDateString();
            $days[] = ['date' => $date, 'city' => $rows->get($date)?->city];
        }

        return new AvailabilityMonth($profile->public_id, $start->format('Y-m'), $profile->availability_version, $days);
    }

    /** "Hoy" es la fecha local de negocio, no la fecha UTC. */
    private function today(): string
    {
        return $this->clock->now()->setTimezone(AvailabilityDay::BUSINESS_TIMEZONE)->toDateString();
    }
}
