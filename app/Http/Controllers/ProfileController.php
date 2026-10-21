<?php

namespace App\Http\Controllers;

use App\Enums\ProfileCategory;
use App\Http\Resources\ProfileCitiesResource;
use App\Http\Resources\ProfileResource;
use App\Http\Resources\TermsAcceptanceResource;
use App\Models\Profile;
use App\Services\ProfileService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ProfileController extends ApiController
{
    public function __construct(private readonly ProfileService $profiles) {}

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatePayload($request, [
            'category' => ['required', 'string', Rule::enum(ProfileCategory::class)],
            'display_name' => ['required', 'string', 'min:2', 'max:160'],
        ], ['category', 'display_name']);
        $profile = $this->profiles->create(
            $this->authenticatedUser($request),
            (string) $data['category'],
            (string) $data['display_name'],
        );

        return (new ProfileResource($profile))->response()->setStatusCode(201);
    }

    public function show(Request $request, string $profile): ProfileResource
    {
        return new ProfileResource($this->profiles->show($this->authenticatedUser($request), $profile));
    }

    public function update(Request $request, string $profile): ProfileResource
    {
        $actor = $this->authenticatedUser($request);
        $ownedProfile = $this->profiles->owned($actor, $profile);
        [$rules, $allowedFields] = $this->updateValidation($ownedProfile);
        $data = $this->validatePayload($request, $rules, $allowedFields, true);

        return new ProfileResource($this->profiles->update($actor, $ownedProfile, $data));
    }

    public function complete(Request $request, string $profile): ProfileResource
    {
        $this->validatePayload($request, [], []);

        return new ProfileResource($this->profiles->complete($this->authenticatedUser($request), $profile));
    }

    public function acceptTerms(Request $request, string $profile): JsonResponse
    {
        $strictTrue = static function (string $attribute, mixed $value, Closure $fail): void {
            if ($value !== true) {
                $fail(__('El campo debe ser verdadero.'));
            }
        };
        $data = $this->validatePayload($request, [
            'version' => ['required', 'string', Rule::in([ProfileService::CURRENT_TERMS_VERSION])],
            'accepted' => ['required', $strictTrue],
        ], ['version', 'accepted']);
        $acceptance = $this->profiles->acceptTerms(
            $this->authenticatedUser($request),
            $profile,
            (string) $data['version'],
        );

        return (new TermsAcceptanceResource($acceptance))->response()->setStatusCode(201);
    }

    public function replaceCities(Request $request, string $profile): ProfileCitiesResource
    {
        $data = $this->validatePayload($request, [
            'city_ids' => ['required', 'array', 'max:100'],
            'city_ids.*' => ['required', 'integer', 'distinct:strict', Rule::exists('cities', 'id')],
            'availability_resolution' => ['sometimes', 'string', Rule::in(['block_removed_cities'])],
        ], ['city_ids', 'availability_resolution']);

        return new ProfileCitiesResource($this->profiles->replaceCities(
            $this->authenticatedUser($request),
            $profile,
            array_map('intval', $data['city_ids']),
            isset($data['availability_resolution']) ? (string) $data['availability_resolution'] : null,
        ));
    }

    /** @return array{array<string, mixed>, array<int, string>} */
    private function updateValidation(Profile $profile): array
    {
        $rules = [
            'display_name' => ['sometimes', 'string', 'min:2', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'contact_email' => ['sometimes', 'nullable', 'string', 'email:rfc', 'max:254'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'primary_city_id' => ['sometimes', 'nullable', 'integer', Rule::exists('cities', 'id')],
            'language_ids' => ['sometimes', 'array', 'max:30'],
            'language_ids.*' => ['required', 'integer', 'distinct:strict', Rule::exists('languages', 'id')],
            'service_ids' => ['sometimes', 'array', 'max:100'],
            'service_ids.*' => ['required', 'integer', 'distinct:strict', Rule::exists('services', 'id')],
        ];
        $allowed = ['display_name', 'description', 'contact_email', 'phone', 'primary_city_id', 'language_ids', 'service_ids'];

        match ($profile->category) {
            ProfileCategory::TourismCompany => $this->addTourismCompanyValidation($rules, $allowed),
            ProfileCategory::Guide => $this->addGuideValidation($rules, $allowed),
            ProfileCategory::Driver => $this->addDriverValidation($rules, $allowed),
            ProfileCategory::Instructor => $this->addInstructorValidation($rules, $allowed),
            ProfileCategory::TransportCompany => $this->addTransportValidation($rules, $allowed),
        };

        return [$rules, $allowed];
    }

    /**
     * @param  array<string, mixed>  $rules
     * @param  array<int, string>  $allowed
     */
    private function addTourismCompanyValidation(array &$rules, array &$allowed): void
    {
        $rules['legal_name'] = ['sometimes', 'string', 'min:2', 'max:200'];
        $rules['years_active'] = ['sometimes', 'nullable', 'integer', 'min:0'];
        array_push($allowed, 'legal_name', 'years_active');
    }

    /**
     * @param  array<string, mixed>  $rules
     * @param  array<int, string>  $allowed
     */
    private function addGuideValidation(array &$rules, array &$allowed): void
    {
        $rules['education_summary'] = ['sometimes', 'nullable', 'string', 'max:3000'];
        $this->addQualificationsValidation($rules);
        array_push($allowed, 'education_summary', 'qualifications');
    }

    /**
     * @param  array<string, mixed>  $rules
     * @param  array<int, string>  $allowed
     */
    private function addDriverValidation(array &$rules, array &$allowed): void
    {
        $rules['license_type'] = ['sometimes', 'nullable', 'string', 'max:120'];
        $rules['vehicle_type'] = ['sometimes', 'nullable', 'string', 'max:120'];
        $rules['vehicle_capacity'] = ['sometimes', 'nullable', 'integer', 'min:1'];
        array_push($allowed, 'license_type', 'vehicle_type', 'vehicle_capacity');
    }

    /**
     * @param  array<string, mixed>  $rules
     * @param  array<int, string>  $allowed
     */
    private function addInstructorValidation(array &$rules, array &$allowed): void
    {
        $rules['training_summary'] = ['sometimes', 'nullable', 'string', 'max:3000'];
        $rules['specialty_ids'] = ['sometimes', 'array', 'max:100'];
        $rules['specialty_ids.*'] = ['required', 'integer', 'distinct:strict', Rule::exists('specialties', 'id')];
        $this->addQualificationsValidation($rules);
        array_push($allowed, 'training_summary', 'specialty_ids', 'qualifications');
    }

    /**
     * @param  array<string, mixed>  $rules
     * @param  array<int, string>  $allowed
     */
    private function addTransportValidation(array &$rules, array &$allowed): void
    {
        $rules['legal_name'] = ['sometimes', 'string', 'min:2', 'max:200'];
        $rules['fleet_description'] = ['sometimes', 'nullable', 'string', 'max:3000'];
        $rules['fleet_vehicles'] = ['sometimes', 'array', 'max:100'];
        $rules['fleet_vehicles.*'] = ['required', 'array:vehicle_type,quantity,capacity'];
        $rules['fleet_vehicles.*.vehicle_type'] = ['required', 'string', 'min:1', 'max:120'];
        $rules['fleet_vehicles.*.quantity'] = ['required', 'integer', 'min:1'];
        $rules['fleet_vehicles.*.capacity'] = ['required', 'integer', 'min:1'];
        array_push($allowed, 'legal_name', 'fleet_description', 'fleet_vehicles');
    }

    /** @param array<string, mixed> $rules */
    private function addQualificationsValidation(array &$rules): void
    {
        $rules['qualifications'] = ['sometimes', 'array', 'max:100'];
        $rules['qualifications.*'] = ['required', 'array:title,institution,completed_on,expires_on'];
        $rules['qualifications.*.title'] = ['required', 'string', 'min:1', 'max:160'];
        $rules['qualifications.*.institution'] = ['required', 'string', 'min:1', 'max:160'];
        $rules['qualifications.*.completed_on'] = ['required', 'date_format:Y-m-d'];
        $rules['qualifications.*.expires_on'] = ['present', 'nullable', 'date_format:Y-m-d'];
    }
}
