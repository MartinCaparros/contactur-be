<?php

namespace App\Repositories;

use App\Models\AvailabilityDay;
use App\Models\Profile;
use Illuminate\Database\Eloquent\Collection;

final class AvailabilityRepository
{
    /** @return Collection<int, AvailabilityDay> */
    public function forRange(Profile $profile, string $fromDate, string $toDate): Collection
    {
        return AvailabilityDay::query()
            ->where('profile_id', $profile->id)
            ->whereBetween('date', [$fromDate, $toDate])
            ->with('city')
            ->orderBy('date')
            ->get();
    }

    /**
     * Días habilitados del rango (solo existen filas para días disponibles),
     * opcionalmente acotados a una ciudad; vista de lectura entre miembros.
     *
     * @return Collection<int, AvailabilityDay>
     */
    public function availableDays(Profile $profile, string $fromDate, string $toDate, ?int $cityId): Collection
    {
        return AvailabilityDay::query()
            ->where('profile_id', $profile->id)
            ->whereBetween('date', [$fromDate, $toDate])
            ->when($cityId !== null, fn ($query) => $query->where('city_id', $cityId))
            ->with('city')
            ->orderBy('date')
            ->get();
    }

    /**
     * Ciudad actual de cada fecha pedida que hoy está habilitada.
     *
     * @param  array<int, string>  $dates
     * @return array<string, int>
     */
    public function cityIdsByDate(Profile $profile, array $dates): array
    {
        if ($dates === []) {
            return [];
        }

        $current = [];
        $rows = AvailabilityDay::query()
            ->where('profile_id', $profile->id)
            ->whereIn('date', $dates)
            ->get(['date', 'city_id']);

        foreach ($rows as $row) {
            $current[$row->date] = $row->city_id;
        }

        return $current;
    }

    /**
     * Ciudades distintas con disponibilidad estrictamente futura que el lote no
     * toca; junto con las ciudades del lote forman el conjunto resultante que se
     * compara contra city_limit.
     *
     * @param  array<int, string>  $excludedDates
     * @return array<int, int>
     */
    public function futureCityIdsExcluding(Profile $profile, string $today, array $excludedDates): array
    {
        return AvailabilityDay::query()
            ->where('profile_id', $profile->id)
            ->where('date', '>', $today)
            ->when($excludedDates !== [], fn ($query) => $query->whereNotIn('date', $excludedDates))
            ->distinct()
            ->pluck('city_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * T21: días habilitados del perfil estrictamente posteriores a $date (fecha
     * local de negocio Y-m-d). Usa el unique (profile_id, date).
     */
    public function countAfter(Profile $profile, string $date): int
    {
        return AvailabilityDay::query()
            ->where('profile_id', $profile->id)
            ->where('date', '>', $date)
            ->count();
    }

    /**
     * Upsert sobre el unique (profile_id, date): una fecha conserva una sola fila
     * y cambiar de ciudad reescribe esa fila en lugar de duplicarla.
     *
     * @param  array<string, int>  $cityIdsByDate
     */
    public function upsert(Profile $profile, array $cityIdsByDate): void
    {
        if ($cityIdsByDate === []) {
            return;
        }

        $rows = [];
        foreach ($cityIdsByDate as $date => $cityId) {
            $rows[] = ['profile_id' => $profile->id, 'date' => $date, 'city_id' => $cityId];
        }

        AvailabilityDay::query()->upsert($rows, ['profile_id', 'date'], ['city_id', 'updated_at']);
    }

    /** @param array<int, string> $dates */
    public function delete(Profile $profile, array $dates): void
    {
        if ($dates === []) {
            return;
        }

        AvailabilityDay::query()
            ->where('profile_id', $profile->id)
            ->whereIn('date', $dates)
            ->delete();
    }
}
