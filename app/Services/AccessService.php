<?php

namespace App\Services;

use App\Contracts\Clock;
use App\Data\AccessEvaluation;
use App\Enums\AccessEffectiveState;
use App\Enums\AccessGrantStatus;
use App\Enums\ProfileCategory;
use App\Enums\ProfileStatus;
use App\Exceptions\ApiProblemException;
use App\Models\AccessGrant;
use App\Models\Profile;
use App\Models\User;
use App\Repositories\AccessGrantRepository;
use App\Repositories\AuditLogRepository;
use App\Repositories\ProfileRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class AccessService
{
    public const CAPABILITY_SEARCH = 'can_search';

    public const CAPABILITY_SEND_CONTACT_REQUESTS = 'can_send_contact_requests';

    public const CAPABILITY_PUBLISH_AVAILABILITY = 'can_publish_availability';

    public const CAPABILITY_RECEIVE_CONTACT_REQUESTS = 'can_receive_contact_requests';

    public const CAPABILITY_USE_GALLERY = 'can_use_gallery';

    public const CAPABILITY_VIEW_HISTORY = 'can_view_history';

    public const CAPABILITY_REJECT_PENDING_REQUESTS = 'can_reject_pending_requests';

    public function __construct(
        private readonly AccessGrantRepository $grants,
        private readonly AuditLogRepository $auditLogs,
        private readonly ProfileRepository $profiles,
        private readonly RealtimeInvalidationService $realtime,
        private readonly NotificationService $notifications,
        private readonly Clock $clock,
    ) {}

    public function evaluate(User $actor, Profile $profile, ?string $capability = null): AccessEvaluation
    {
        $now = $this->clock->now();
        $grant = $this->grants->findForProfile($profile);
        [$effectiveState, $baseDenial] = $this->effectiveState($actor, $profile, $grant, $now);
        $baseAllowed = $effectiveState === AccessEffectiveState::Active;
        $contactQuota = $this->contactQuota($profile, $grant, $now);
        $contactLimitAvailable = $contactQuota === null
            || $contactQuota['limit'] === null
            || $contactQuota['used'] < $contactQuota['limit'];
        $isReceiver = $profile->category !== ProfileCategory::TourismCompany;
        $isSender = $profile->category === ProfileCategory::TourismCompany;

        $capabilities = [
            self::CAPABILITY_SEARCH => $baseAllowed,
            self::CAPABILITY_SEND_CONTACT_REQUESTS => $baseAllowed && $isSender && $contactLimitAvailable,
            self::CAPABILITY_PUBLISH_AVAILABILITY => $baseAllowed && $isReceiver,
            self::CAPABILITY_RECEIVE_CONTACT_REQUESTS => $baseAllowed && $isReceiver,
            self::CAPABILITY_USE_GALLERY => $baseAllowed && ($grant === null ? false : $grant->gallery_enabled),
            self::CAPABILITY_VIEW_HISTORY => $baseDenial !== 'account_suspended',
            self::CAPABILITY_REJECT_PENDING_REQUESTS => $effectiveState !== AccessEffectiveState::Suspended,
        ];

        if ($capability !== null && ! array_key_exists($capability, $capabilities)) {
            throw new InvalidArgumentException('Unknown access capability: '.$capability);
        }

        $allowed = $capability === null ? $baseAllowed : $capabilities[$capability];
        $denialCode = $allowed ? null : $baseDenial;
        $capabilityLimit = null;

        if (! $allowed && $baseAllowed && $capability !== null) {
            $denialCode = 'capability_limit_reached';
            $capabilityLimit = match ($capability) {
                self::CAPABILITY_SEND_CONTACT_REQUESTS => $grant?->monthly_contact_limit,
                default => 0,
            };
        }

        return new AccessEvaluation(
            profileId: $profile->public_id,
            effectiveState: $effectiveState->value,
            allowed: $allowed,
            denialCode: $denialCode,
            grant: $grant,
            capabilities: $capabilities,
            contactQuota: $contactQuota,
            evaluatedAt: $now,
            requestedCapability: $capability,
            capabilityLimit: $capabilityLimit,
        );
    }

    public function assertAllowed(User $actor, Profile $profile, string $capability): AccessEvaluation
    {
        $evaluation = $this->evaluate($actor, $profile, $capability);

        if ($evaluation->allowed) {
            return $evaluation;
        }

        $fields = [];
        if ($evaluation->denialCode === 'capability_limit_reached') {
            $fields = [
                'capability' => [$capability],
                'limit' => [$evaluation->capabilityLimit === null ? 'unlimited' : (string) $evaluation->capabilityLimit],
            ];
        }

        throw new ApiProblemException(
            403,
            $evaluation->denialCode ?? 'subscription_required',
            $evaluation->denialCode === 'capability_limit_reached'
                ? __('Se alcanzó el límite de la capacidad solicitada.')
                : __('El perfil no tiene acceso a esta funcionalidad.'),
            $fields,
        );
    }

    /**
     * @param array{
     *   status: string,
     *   starts_at: CarbonImmutable|null,
     *   expires_at: CarbonImmutable|null,
     *   city_limit: int|null,
     *   monthly_contact_limit: int|null,
     *   gallery_enabled: bool,
     *   expected_version: int,
     *   cities_to_keep?: array<int, int>,
     *   reason: string
     * } $data
     */
    public function replaceGrant(User $actor, string $profilePublicId, array $data, string $requestId): AccessEvaluation
    {
        $this->assertActiveAdmin($actor);

        return DB::transaction(function () use ($actor, $profilePublicId, $data, $requestId): AccessEvaluation {
            $profile = $this->profiles->findByPublicId($profilePublicId);
            if ($profile === null) {
                throw new ApiProblemException(404, 'not_found', __('El recurso solicitado no existe.'));
            }

            $lockedProfile = $this->profiles->lockById($profile->id);
            $grant = $this->grants->lockForProfile($lockedProfile);
            $currentVersion = $grant === null ? 0 : $grant->version;

            if ($currentVersion !== $data['expected_version']) {
                throw new ApiProblemException(409, 'stale_access_grant', __('El grant fue modificado por otra operación.'));
            }

            $reason = trim($data['reason']);
            if (mb_strlen($reason) < 3) {
                throw new ApiProblemException(422, 'validation_failed', __('Los datos enviados no son válidos.'), [
                    'reason' => [__('El motivo debe tener al menos 3 caracteres no vacíos.')],
                ]);
            }

            $before = $this->grantSnapshot($grant);
            [$removedCityIds, $primaryCityBefore] = $this->applyCityLimit(
                $lockedProfile,
                $data['city_limit'],
                array_key_exists('cities_to_keep', $data) ? $data['cities_to_keep'] : null,
            );
            $attributes = [
                'status' => AccessGrantStatus::from($data['status']),
                'starts_at' => $data['starts_at'],
                'expires_at' => $data['expires_at'],
                'city_limit' => $data['city_limit'],
                'monthly_contact_limit' => $data['monthly_contact_limit'],
                'gallery_enabled' => $data['gallery_enabled'],
                'version' => $currentVersion + 1,
                'updated_by' => $actor->id,
                'reason' => $reason,
            ];
            $updated = $grant === null
                ? $this->grants->create($lockedProfile, $attributes)
                : $this->grants->update($grant, $attributes);
            $lockedProfile->setRelation('accessGrant', $updated);
            $lockedProfile->load('owner');
            $safeChanges = [
                'before' => $before,
                'after' => $this->grantSnapshot($updated),
            ];

            if ($removedCityIds !== []) {
                $safeChanges['removed_city_ids'] = $removedCityIds;
            }
            if ($primaryCityBefore !== null && $lockedProfile->primary_city_id === null) {
                $safeChanges['primary_city_id'] = ['before' => $primaryCityBefore, 'after' => null];
            }

            $audit = $this->auditLogs->create(
                actor: $actor,
                targetType: 'profile',
                targetId: $lockedProfile->public_id,
                action: 'access_grant.replaced',
                reason: $reason,
                safeChanges: $safeChanges,
                requestId: $requestId,
                createdAt: $this->clock->now(),
            );
            // T19: aviso esencial al titular; versión del grant, sin motivo ni admin.
            $this->notifications->administrativeChange(
                $lockedProfile->owner,
                $lockedProfile,
                'access_updated',
                $audit->public_id,
                ['access_grant_version' => $updated->version],
            );
            // Cada reemplazo versiona el grant: directorio y titular re-evalúan tras el commit.
            $this->realtime->accessChanged($lockedProfile);

            return $this->evaluate($lockedProfile->owner, $lockedProfile, null);
        });
    }

    /** @return array{AccessEffectiveState, string|null} */
    private function effectiveState(
        User $actor,
        Profile $profile,
        ?AccessGrant $grant,
        CarbonImmutable $now,
    ): array {
        if ($actor->status === User::STATUS_SUSPENDED) {
            return [AccessEffectiveState::Suspended, 'account_suspended'];
        }

        if ($profile->owner_user_id !== $actor->id) {
            $profile->loadMissing('owner');
            $owner = $profile->getRelation('owner');

            if (! $owner instanceof User || $owner->status === User::STATUS_SUSPENDED) {
                return [AccessEffectiveState::Suspended, 'account_suspended'];
            }
        }

        if ($profile->status === ProfileStatus::Suspended) {
            return [AccessEffectiveState::Suspended, 'profile_suspended'];
        }
        if ($grant === null || $grant->status === AccessGrantStatus::Inactive) {
            return [AccessEffectiveState::Inactive, 'subscription_required'];
        }
        if ($grant->status === AccessGrantStatus::Suspended) {
            return [AccessEffectiveState::Suspended, 'profile_suspended'];
        }
        if ($grant->starts_at !== null && $grant->starts_at->greaterThan($now)) {
            return [AccessEffectiveState::Inactive, 'subscription_required'];
        }
        if ($grant->expires_at !== null && ! $now->lessThan($grant->expires_at)) {
            return [AccessEffectiveState::Expired, 'subscription_expired'];
        }

        return [AccessEffectiveState::Active, null];
    }

    /** @return array{month_start: string, used: int, limit: int|null, remaining: int|null}|null */
    private function contactQuota(Profile $profile, ?AccessGrant $grant, CarbonImmutable $now): ?array
    {
        if ($profile->category !== ProfileCategory::TourismCompany || $grant === null) {
            return null;
        }

        $monthStart = $now
            ->setTimezone('America/Argentina/Buenos_Aires')
            ->startOfMonth();
        $used = $this->grants->contactUsage($profile, $monthStart);
        $limit = $grant->monthly_contact_limit;

        return [
            'month_start' => $monthStart->toDateString(),
            'used' => $used,
            'limit' => $limit,
            'remaining' => $limit === null ? null : max(0, $limit - $used),
        ];
    }

    /**
     * @param  array<int, int>|null  $citiesToKeep
     * @return array{array<int, int>, int|null}
     */
    private function applyCityLimit(Profile $profile, ?int $cityLimit, ?array $citiesToKeep): array
    {
        $currentCityIds = $this->profiles->cityIds($profile);
        $primaryCityBefore = $profile->primary_city_id;
        $requiresSelection = $cityLimit !== null && count($currentCityIds) > $cityLimit;

        if ($cityLimit === 0 && $citiesToKeep === null) {
            $this->cityLimitValidationError('cities_to_keep', __('Debe indicar una lista vacía cuando city_limit es 0.'));
        }
        if ($requiresSelection && $citiesToKeep === null) {
            $this->cityLimitValidationError('cities_to_keep', __('Debe seleccionar explícitamente las ciudades a conservar.'));
        }
        if ($citiesToKeep === null) {
            return [[], $primaryCityBefore];
        }
        if ($cityLimit === null) {
            $this->cityLimitValidationError('cities_to_keep', __('No corresponde seleccionar ciudades cuando el límite es ilimitado.'));
        }
        if (count($citiesToKeep) !== count(array_unique($citiesToKeep))) {
            $this->cityLimitValidationError('cities_to_keep', __('Las ciudades no pueden repetirse.'));
        }
        if (count(array_diff($citiesToKeep, $currentCityIds)) > 0) {
            $this->cityLimitValidationError('cities_to_keep', __('Todas las ciudades deben pertenecer actualmente al perfil.'));
        }
        if (count($citiesToKeep) > $cityLimit) {
            $this->cityLimitValidationError('cities_to_keep', __('La selección supera el nuevo city_limit.'));
        }
        if ($cityLimit === 0 && $citiesToKeep !== []) {
            $this->cityLimitValidationError('cities_to_keep', __('La selección debe estar vacía cuando city_limit es 0.'));
        }

        if (! $requiresSelection && $cityLimit !== 0) {
            return [[], $primaryCityBefore];
        }

        if (
            $profile->primary_city_id !== null
            && ! in_array($profile->primary_city_id, $citiesToKeep, true)
        ) {
            if ($cityLimit !== 0) {
                $this->cityLimitValidationError(
                    'cities_to_keep',
                    __('La ciudad principal no puede excluirse; actualizala explícitamente antes del downgrade.'),
                );
            }

            $this->profiles->updatePrimaryCity($profile, null);
        }

        $removedCityIds = array_values(array_diff($currentCityIds, $citiesToKeep));
        if ($removedCityIds !== []) {
            $this->realtime->availabilityChanged(
                $profile,
                $this->profiles->blockFutureAvailability($profile, $removedCityIds, $this->clock->now()),
            );
            $this->profiles->replaceCities($profile, $citiesToKeep);
        }

        return [$removedCityIds, $primaryCityBefore];
    }

    private function cityLimitValidationError(string $field, string $message): never
    {
        throw new ApiProblemException(422, 'validation_failed', __('Los datos enviados no son válidos.'), [
            $field => [$message],
        ]);
    }

    /** @return array<string, mixed>|null */
    private function grantSnapshot(?AccessGrant $grant): ?array
    {
        if ($grant === null) {
            return null;
        }

        return [
            'status' => $grant->status->value,
            'starts_at' => $grant->starts_at?->utc()->toISOString(),
            'expires_at' => $grant->expires_at?->utc()->toISOString(),
            'city_limit' => $grant->city_limit,
            'monthly_contact_limit' => $grant->monthly_contact_limit,
            'gallery_enabled' => $grant->gallery_enabled,
            'version' => $grant->version,
        ];
    }

    private function assertActiveAdmin(User $actor): void
    {
        if ($actor->system_role !== User::ROLE_ADMIN || $actor->status !== User::STATUS_ACTIVE) {
            throw new ApiProblemException(403, 'forbidden', __('No tenés permisos para realizar esta acción.'));
        }
    }
}
