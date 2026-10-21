<?php

namespace App\Http\Resources;

use App\Data\DirectorySearchPage;
use App\Models\Profile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * SearchResponse. Sin rango, availability_status es siempre `unspecified` y los
 * conteos son 0/0: no se afirma disponibilidad global. `total` se omite (el
 * contrato lo admite solo cuando el conteo es seguro y eficiente).
 *
 * @mixin DirectorySearchPage
 */
final class DirectorySearchResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $requested = $this->requestedDays;

        return [
            'data' => $this->items->map(static function (Profile $profile) use ($request, $requested): array {
                $matched = $requested === 0 ? 0 : (int) $profile->getAttribute('matched_days');

                return [
                    'safe_profile' => (new SafeProfileResource($profile))->resolve($request),
                    'matched_days' => $matched,
                    'requested_days' => $requested,
                    'availability_status' => match (true) {
                        $requested === 0 => 'unspecified',
                        $matched >= $requested => 'available',
                        default => 'partial',
                    },
                ];
            })->all(),
            'meta' => [
                'next_cursor' => $this->nextCursor,
                'per_page' => $this->perPage,
                'search_seed' => $this->seed,
            ],
        ];
    }
}
