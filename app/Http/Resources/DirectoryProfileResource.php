<?php

namespace App\Http\Resources;

use App\Data\DirectoryProfile;
use App\Enums\ProfileCategory;
use App\Models\FleetVehicle;
use App\Models\Qualification;
use App\Models\Reference;
use App\Models\Specialty;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * DirectoryProfileData. Sin contacto (R12): el contacto solo se entrega por el
 * endpoint protegido tras match aceptado. Documentos solo como estado.
 * visible_references (T20/R17): solo referencias que el target recibió, que su
 * titular marcó como visibles y que administración no moderó; más nuevas primero
 * y con tope `contactur.references.directory_limit` (default 20). Cada una sale
 * como PublicReference, sin visibilidad, moderación ni solicitud de origen.
 *
 * @mixin DirectoryProfile
 */
final class DirectoryProfileResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $profile = $this->profile;
        $category = $profile->category;
        $hasQualifications = in_array($category, [ProfileCategory::Guide, ProfileCategory::Instructor], true);

        return [
            'profile' => (new SafeProfileResource($profile))->resolve($request),
            'document_statuses' => $this->documentStatuses,
            'qualifications' => $hasQualifications
                ? $profile->qualifications->map(static fn (Qualification $qualification): array => [
                    'title' => $qualification->title,
                    'institution' => $qualification->institution,
                    'completed_on' => $qualification->completed_on->toDateString(),
                    'expires_on' => $qualification->expires_on?->toDateString(),
                ])->all()
                : [],
            'fleet_vehicles' => $category === ProfileCategory::TransportCompany
                ? $profile->fleetVehicles->map(static fn (FleetVehicle $vehicle): array => [
                    'vehicle_type' => $vehicle->vehicle_type,
                    'quantity' => $vehicle->quantity,
                    'capacity' => $vehicle->capacity,
                ])->all()
                : [],
            'specialties' => $category === ProfileCategory::Instructor
                ? $profile->specialties->map(static fn (Specialty $item): array => SafeProfileResource::catalogItem($item))->all()
                : [],
            'visible_references' => $this->visibleReferences
                ->map(static fn (Reference $reference): array => (new PublicReferenceResource($reference))->resolve($request))
                ->values()
                ->all(),
        ];
    }
}
