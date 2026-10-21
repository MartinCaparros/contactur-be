<?php

namespace App\Services;

use App\Contracts\Clock;
use App\Enums\AccessGrantStatus;
use App\Enums\ProfileCategory;
use App\Enums\ProfileStatus;
use App\Exceptions\ApiProblemException;
use App\Models\AccessGrant;
use App\Models\Profile;
use App\Models\ProfileDocument;
use App\Models\TermsAcceptance;
use App\Models\User;
use App\Policies\ProfilePolicy;
use App\Repositories\AccessGrantRepository;
use App\Repositories\ProfileRepository;
use Illuminate\Support\Facades\DB;

final class ProfileService
{
    public const CURRENT_TERMS_VERSION = 'demo-v1';

    public function __construct(
        private readonly ProfileRepository $profiles,
        private readonly AccessGrantRepository $accessGrants,
        private readonly ProfilePolicy $policy,
        private readonly RealtimeInvalidationService $realtime,
        private readonly Clock $clock,
    ) {}

    public function create(User $actor, string $category, string $displayName): Profile
    {
        return DB::transaction(function () use ($actor, $category, $displayName): Profile {
            $profile = $this->profiles->create($actor, ProfileCategory::from($category), $displayName);

            return $this->profiles->loadForOwnerResponse($profile, $this->clock->now());
        });
    }

    public function owned(User $actor, string $publicId): Profile
    {
        $profile = $this->profiles->findByPublicId($publicId);

        if ($profile === null || ! $this->policy->view($actor, $profile)) {
            throw new ApiProblemException(404, 'not_found', __('El recurso solicitado no existe.'));
        }

        return $profile;
    }

    public function show(User $actor, string $publicId): Profile
    {
        return $this->profiles->loadForOwnerResponse($this->owned($actor, $publicId), $this->clock->now());
    }

    /** @param array<string, mixed> $data */
    public function update(User $actor, Profile $profile, array $data): Profile
    {
        return DB::transaction(function () use ($actor, $profile, $data): Profile {
            $locked = $this->profiles->lockById($profile->id);
            $this->assertOwner($actor, $locked);
            $grant = $this->accessGrants->lockForProfile($locked);
            $this->assertMutable($locked, $grant);

            if (array_key_exists('primary_city_id', $data) && $data['primary_city_id'] !== null) {
                $cityId = (int) $data['primary_city_id'];
                if (! $this->profiles->hasCity($locked, $cityId)) {
                    throw new ApiProblemException(422, 'validation_failed', __('Los datos enviados no son válidos.'), [
                        'primary_city_id' => [__('La ciudad principal debe pertenecer a las ciudades seleccionadas.')],
                    ]);
                }
            }

            $base = $this->only($data, ['display_name', 'description', 'primary_city_id']);
            if ($base !== []) {
                $this->profiles->updateBase($locked, $base);
            }

            $contact = $this->only($data, ['contact_email', 'phone']);
            if ($contact !== []) {
                $this->profiles->updateContact($locked, $contact);
            }

            $details = $this->only($data, match ($locked->category) {
                ProfileCategory::TourismCompany => ['legal_name', 'years_active'],
                ProfileCategory::Guide => ['education_summary'],
                ProfileCategory::Driver => ['license_type', 'vehicle_type', 'vehicle_capacity'],
                ProfileCategory::Instructor => ['training_summary'],
                ProfileCategory::TransportCompany => ['legal_name', 'fleet_description'],
            });
            if ($details !== []) {
                $this->profiles->updateCategoryDetails($locked, $details);
            }

            if (array_key_exists('language_ids', $data)) {
                $this->profiles->syncLanguages($locked, array_map('intval', $data['language_ids']));
            }
            if (array_key_exists('service_ids', $data)) {
                $this->profiles->syncServices($locked, array_map('intval', $data['service_ids']));
            }
            if (array_key_exists('specialty_ids', $data)) {
                $this->profiles->syncSpecialties($locked, array_map('intval', $data['specialty_ids']));
            }
            if (array_key_exists('qualifications', $data)) {
                $this->profiles->replaceQualifications($locked, $data['qualifications']);
            }
            if (array_key_exists('fleet_vehicles', $data)) {
                $this->profiles->replaceFleetVehicles($locked, $data['fleet_vehicles']);
            }

            $this->profiles->advanceOnboarding($locked, 2);

            return $this->profiles->loadForOwnerResponse($locked->refresh(), $this->clock->now());
        });
    }

    /** @param array<int, int> $cityIds */
    public function replaceCities(
        User $actor,
        string $publicId,
        array $cityIds,
        ?string $availabilityResolution = null,
    ): Profile {
        return DB::transaction(function () use ($actor, $publicId, $cityIds, $availabilityResolution): Profile {
            $profile = $this->owned($actor, $publicId);
            $locked = $this->profiles->lockById($profile->id);
            $this->assertOwner($actor, $locked);
            $grant = $this->accessGrants->lockForProfile($locked);
            $this->assertMutable($locked, $grant);

            if ($grant?->city_limit !== null && count($cityIds) > $grant->city_limit) {
                throw new ApiProblemException(422, 'validation_failed', __('Los datos enviados no son válidos.'), [
                    'city_ids' => [__('La selección supera el city_limit configurado para el perfil.')],
                ]);
            }

            if ($locked->primary_city_id !== null && ! in_array($locked->primary_city_id, $cityIds, true)) {
                throw new ApiProblemException(422, 'validation_failed', __('Los datos enviados no son válidos.'), [
                    'city_ids' => [__('No se puede quitar la ciudad principal; cambiála antes de reemplazar ciudades.')],
                ]);
            }

            // Quitar una ciudad con días futuros habilitados nunca pierde fechas en
            // silencio: exige block_removed_cities, que las bloquea y versiona.
            $removedCityIds = array_values(array_diff($this->profiles->cityIds($locked), $cityIds));
            if ($removedCityIds !== []) {
                $now = $this->clock->now();
                if (
                    $availabilityResolution !== 'block_removed_cities'
                    && $this->profiles->hasFutureAvailability($locked, $removedCityIds, $now)
                ) {
                    throw new ApiProblemException(422, 'validation_failed', __('Los datos enviados no son válidos.'), [
                        'availability_resolution' => [__('Las ciudades quitadas tienen disponibilidad futura; indicá block_removed_cities para bloquear esas fechas.')],
                    ]);
                }

                $this->realtime->availabilityChanged(
                    $locked,
                    $this->profiles->blockFutureAvailability($locked, $removedCityIds, $now),
                );
            }

            $this->profiles->replaceCities($locked, $cityIds);
            $this->profiles->advanceOnboarding($locked, 2);
            $locked->load('cities');

            return $locked;
        });
    }

    public function acceptTerms(User $actor, string $publicId, string $version): TermsAcceptance
    {
        return DB::transaction(function () use ($actor, $publicId, $version): TermsAcceptance {
            $profile = $this->owned($actor, $publicId);
            $locked = $this->profiles->lockById($profile->id);
            $this->assertOwner($actor, $locked);
            $this->assertMutable($locked, $this->accessGrants->lockForProfile($locked));

            if ($this->profiles->termsAcceptance($actor, $locked, $version) !== null) {
                throw new ApiProblemException(409, 'terms_already_accepted', __('Los términos ya fueron aceptados.'));
            }

            $acceptance = $this->profiles->createTermsAcceptance($actor, $locked, $version, $this->clock->now());
            $acceptance->setAttribute('profile_public_id', $locked->public_id);
            $this->profiles->advanceOnboarding($locked, 4);

            return $acceptance;
        });
    }

    public function complete(User $actor, string $publicId): Profile
    {
        return DB::transaction(function () use ($actor, $publicId): Profile {
            $profile = $this->owned($actor, $publicId);
            $locked = $this->profiles->lockById($profile->id);
            $this->assertOwner($actor, $locked);
            $this->assertMutable($locked, $this->accessGrants->lockForProfile($locked));
            $loaded = $this->profiles->loadForOwnerResponse($locked, $this->clock->now());
            $errors = $this->completionErrors($actor, $loaded);

            if ($errors !== []) {
                throw new ApiProblemException(422, 'profile_incomplete', __('El perfil todavía no cumple los requisitos de finalización.'), $errors);
            }

            $this->profiles->markCompleted($loaded, $this->clock->now());

            return $this->profiles->loadForOwnerResponse($loaded->refresh(), $this->clock->now());
        });
    }

    /** @return array<string, array<int, string>> */
    private function completionErrors(User $actor, Profile $profile): array
    {
        $errors = [];

        if ($actor->email_verified_at === null) {
            $errors['email'] = [__('La cuenta debe tener el email verificado.')];
        }
        if ($profile->description === null || trim($profile->description) === '') {
            $errors['description'] = [__('La descripción es obligatoria.')];
        }
        if ($profile->contact === null || $profile->contact->contact_email === null) {
            $errors['contact_email'] = [__('El email de contacto es obligatorio.')];
        }
        if ($profile->contact === null || $profile->contact->phone === null) {
            $errors['phone'] = [__('El teléfono es obligatorio.')];
        }
        if ($profile->cities->isEmpty()) {
            $errors['cities'] = [__('Debe seleccionar al menos una ciudad.')];
        }
        if ($profile->primary_city_id === null || ! $profile->cities->contains('id', $profile->primary_city_id)) {
            $errors['primary_city_id'] = [__('Debe seleccionar una ciudad principal válida.')];
        }
        if ($profile->languages->isEmpty()) {
            $errors['languages'] = [__('Debe seleccionar al menos un idioma.')];
        }
        if ($profile->services->isEmpty()) {
            $errors['services'] = [__('Debe seleccionar al menos un servicio.')];
        }

        match ($profile->category) {
            ProfileCategory::TourismCompany => $this->requireText($errors, 'legal_name', $profile->companyDetails?->legal_name),
            ProfileCategory::Guide => $this->requireText($errors, 'education_summary', $profile->guideDetails?->education_summary),
            ProfileCategory::Driver => $this->requireDriverFields($errors, $profile),
            ProfileCategory::Instructor => $this->requireInstructorFields($errors, $profile),
            ProfileCategory::TransportCompany => $this->requireTransportFields($errors, $profile),
        };

        $missingDocuments = $this->profiles->missingRequiredDocuments(
            $profile,
            [ProfileDocument::STATUS_PENDING, ProfileDocument::STATUS_APPROVED],
            $this->clock->now(),
        );
        if ($missingDocuments !== []) {
            $errors['documents'] = [__('Faltan documentos requeridos: :documents.', [
                'documents' => implode(', ', array_map(
                    static fn (string $label): string => __($label),
                    array_values($missingDocuments),
                )),
            ])];
        }
        if ($this->profiles->termsAcceptance($actor, $profile, self::CURRENT_TERMS_VERSION) === null) {
            $errors['terms'] = [__('Debe aceptar los términos vigentes :version.', ['version' => self::CURRENT_TERMS_VERSION])];
        }

        return $errors;
    }

    /** @param array<string, array<int, string>> $errors */
    private function requireDriverFields(array &$errors, Profile $profile): void
    {
        $this->requireText($errors, 'license_type', $profile->driverDetails?->license_type);
        $this->requireText($errors, 'vehicle_type', $profile->driverDetails?->vehicle_type);
        if (($profile->driverDetails->vehicle_capacity ?? 0) < 1) {
            /** @var string $message */
            $message = __('La capacidad del vehículo es obligatoria.');
            $errors['vehicle_capacity'] = [$message];
        }
    }

    /** @param array<string, array<int, string>> $errors */
    private function requireInstructorFields(array &$errors, Profile $profile): void
    {
        $this->requireText($errors, 'training_summary', $profile->instructorDetails?->training_summary);
        if ($profile->specialties->isEmpty()) {
            /** @var string $message */
            $message = __('Debe seleccionar al menos una especialidad.');
            $errors['specialties'] = [$message];
        }
    }

    /** @param array<string, array<int, string>> $errors */
    private function requireTransportFields(array &$errors, Profile $profile): void
    {
        $this->requireText($errors, 'legal_name', $profile->transportDetails?->legal_name);
        $this->requireText($errors, 'fleet_description', $profile->transportDetails?->fleet_description);
        if ($profile->fleetVehicles->isEmpty()) {
            /** @var string $message */
            $message = __('Debe declarar al menos un vehículo de flota.');
            $errors['fleet_vehicles'] = [$message];
        }
    }

    /** @param array<string, array<int, string>> $errors */
    private function requireText(array &$errors, string $field, ?string $value): void
    {
        if ($value === null || trim($value) === '') {
            /** @var string $message */
            $message = __('El campo es obligatorio para esta categoría.');
            $errors[$field] = [$message];
        }
    }

    private function assertMutable(Profile $profile, ?AccessGrant $grant): void
    {
        if (
            $profile->status === ProfileStatus::Suspended
            || $grant?->status === AccessGrantStatus::Suspended
        ) {
            throw new ApiProblemException(403, 'profile_suspended', __('El perfil está suspendido.'));
        }
    }

    private function assertOwner(User $actor, Profile $profile): void
    {
        if (! $this->policy->update($actor, $profile)) {
            throw new ApiProblemException(404, 'not_found', __('El recurso solicitado no existe.'));
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $keys
     * @return array<string, mixed>
     */
    private function only(array $data, array $keys): array
    {
        return array_intersect_key($data, array_flip($keys));
    }
}
