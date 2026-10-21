<?php

namespace App\Http\Controllers;

use App\Data\DirectorySearchFilters;
use App\Enums\ProfileCategory;
use App\Http\Resources\DirectoryProfileResource;
use App\Http\Resources\DirectorySearchResource;
use App\Http\Resources\PublicAvailabilityResource;
use App\Models\AvailabilityDay;
use App\Services\DirectoryService;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

final class DirectoryController extends ApiController
{
    private const DATE_RULES = ['string', 'date_format:Y-m-d'];

    public function __construct(private readonly DirectoryService $directory) {}

    public function search(Request $request, string $profile): DirectorySearchResource
    {
        $maxPerPage = (int) config('contactur.search.max_per_page');
        $data = $this->validatePayload($request, [
            'type' => ['sometimes', 'string', Rule::in(array_map(
                static fn (ProfileCategory $category): string => $category->value,
                array_filter(ProfileCategory::cases(), static fn (ProfileCategory $category): bool => $category->isReceiver()),
            ))],
            'name' => ['sometimes', 'string', 'min:1', 'max:100'],
            'city_id' => ['sometimes', 'integer', 'min:1', 'exists:cities,id'],
            ...$this->rangeRules($request, false),
            'match_mode' => ['sometimes', 'string', Rule::in([
                DirectorySearchFilters::MATCH_ALL,
                DirectorySearchFilters::MATCH_ANY,
            ])],
            // Query string: openapi-fetch serializa booleanos como true/false.
            'verified' => ['sometimes', 'string', Rule::in(['true', 'false', '1', '0'])],
            'cursor' => ['sometimes', 'string', 'max:2048'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.$maxPerPage],
        ], ['type', 'name', 'city_id', 'date_from', 'date_to', 'match_mode', 'verified', 'cursor', 'per_page']);

        $filters = new DirectorySearchFilters(
            type: isset($data['type']) ? (string) $data['type'] : null,
            name: isset($data['name']) ? mb_strtolower(trim((string) $data['name'])) : null,
            cityId: isset($data['city_id']) ? (int) $data['city_id'] : null,
            dateFrom: isset($data['date_from']) ? (string) $data['date_from'] : null,
            dateTo: isset($data['date_to']) ? (string) $data['date_to'] : null,
            matchMode: isset($data['match_mode']) ? (string) $data['match_mode'] : DirectorySearchFilters::MATCH_ALL,
            verified: isset($data['verified']) ? in_array($data['verified'], ['true', '1'], true) : null,
        );

        return new DirectorySearchResource($this->directory->search(
            $this->authenticatedUser($request),
            $profile,
            $filters,
            isset($data['cursor']) ? (string) $data['cursor'] : null,
            isset($data['per_page']) ? (int) $data['per_page'] : (int) config('contactur.search.default_per_page'),
        ));
    }

    public function show(Request $request, string $profile, string $target): DirectoryProfileResource
    {
        return new DirectoryProfileResource($this->directory->profile(
            $this->authenticatedUser($request),
            $profile,
            $target,
        ));
    }

    /** Exige mes o rango (mutuamente excluyentes); el rango respeta el máximo configurable. */
    public function availability(Request $request, string $profile, string $target): PublicAvailabilityResource
    {
        $data = $this->validatePayload($request, [
            'month' => ['sometimes', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/', 'prohibits:date_from,date_to'],
            ...$this->rangeRules($request, true),
            'city_id' => ['sometimes', 'integer', 'min:1', 'exists:cities,id'],
        ], ['month', 'date_from', 'date_to', 'city_id']);

        if (isset($data['month'])) {
            $start = CarbonImmutable::parse($data['month'].'-01', AvailabilityDay::BUSINESS_TIMEZONE);
            [$from, $to] = [$start->toDateString(), $start->endOfMonth()->toDateString()];
        } else {
            [$from, $to] = [(string) $data['date_from'], (string) $data['date_to']];
        }

        return new PublicAvailabilityResource($this->directory->availability(
            $this->authenticatedUser($request),
            $profile,
            $target,
            $from,
            $to,
            isset($data['city_id']) ? (int) $data['city_id'] : null,
        ));
    }

    /**
     * date_from y date_to van juntos (un día único es date_from = date_to),
     * date_to >= date_from y el rango inclusivo no supera SEARCH_MAX_RANGE_DAYS.
     *
     * @return array<string, array<int, mixed>>
     */
    private function rangeRules(Request $request, bool $requiredWithoutMonth): array
    {
        $maxDays = (int) config('contactur.search.max_range_days');
        $presence = $requiredWithoutMonth ? ['required_without:month'] : [];

        return [
            'date_from' => [...$presence, 'required_with:date_to', ...self::DATE_RULES],
            'date_to' => [
                ...$presence,
                'required_with:date_from',
                ...self::DATE_RULES,
                'after_or_equal:date_from',
                static function (string $attribute, mixed $value, Closure $fail) use ($request, $maxDays): void {
                    $from = $request->query('date_from');
                    // El formato lo reportan date_format/after_or_equal; acá solo el largo.
                    if (
                        ! is_string($from)
                        || ! is_string($value)
                        || preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) !== 1
                        || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1
                    ) {
                        return;
                    }

                    try {
                        $start = CarbonImmutable::parse($from);
                        $end = CarbonImmutable::parse($value);
                    } catch (Throwable) {
                        return;
                    }

                    if ($end->lessThan($start)) {
                        return;
                    }

                    if ((int) $start->diffInDays($end) + 1 > $maxDays) {
                        $fail(__('El rango no puede superar :days días.', ['days' => $maxDays]));
                    }
                },
            ],
        ];
    }
}
