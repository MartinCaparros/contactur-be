<?php

namespace App\Services;

use App\Contracts\Clock;
use App\Enums\ProfileStatus;
use App\Exceptions\ApiProblemException;
use App\Models\Profile;
use App\Models\User;
use App\Repositories\AccessGrantRepository;
use App\Repositories\AuditLogRepository;
use App\Repositories\ProfileRepository;
use App\Repositories\UserRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Backoffice de cuentas y perfiles.
 *
 * Servicio propio en lugar de extender AccountService o ProfileService: esos dos
 * modelan al titular actuando sobre lo suyo (assertOwner, assertMutable, cambio
 * de email con contraseña) y AccessService modela la frontera de habilitación
 * comercial. La autoridad administrativa sobre users.status y profiles.status es
 * una tercera frontera: mezclarla con las anteriores obligaría a condicionar
 * reglas de propiedad por rol dentro del mismo método. Acá queda explícita, con
 * su propio assertActiveAdmin como defensa en profundidad del middleware.
 */
final class AdminBackofficeService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly ProfileRepository $profiles,
        private readonly AccessGrantRepository $grants,
        private readonly AuditLogRepository $auditLogs,
        private readonly AccessService $access,
        private readonly RealtimeInvalidationService $realtime,
        private readonly NotificationService $notifications,
        private readonly Clock $clock,
    ) {}

    /** @return array{items: Collection<int, User>, next_cursor: string|null, total: int} */
    public function listUsers(
        User $actor,
        ?string $search,
        ?string $status,
        ?int $cursor,
        int $perPage,
    ): array {
        $this->assertActiveAdmin($actor);

        return $this->users->listForAdmin($search, $status, $cursor, $perPage);
    }

    /** @return array{items: Collection<int, Profile>, next_cursor: string|null, total: int} */
    public function listProfiles(
        User $actor,
        ?string $search,
        ?string $category,
        ?string $status,
        ?string $accessState,
        ?int $cursor,
        int $perPage,
    ): array {
        $this->assertActiveAdmin($actor);
        $result = $this->profiles->listForAdmin(
            $search,
            $category,
            $status,
            $accessState,
            $cursor,
            $perPage,
            $this->clock->now(),
        );

        foreach ($result['items'] as $profile) {
            $this->attachEvaluation($profile);
        }

        return $result;
    }

    public function updateUserStatus(
        User $actor,
        string $userPublicId,
        string $status,
        string $reason,
        string $requestId,
    ): User {
        $this->assertActiveAdmin($actor);
        $normalizedReason = $this->normalizedReason($reason);

        return DB::transaction(function () use ($actor, $userPublicId, $status, $normalizedReason, $requestId): User {
            $target = $this->users->lockByPublicId($userPublicId);
            if ($target === null) {
                throw new ApiProblemException(404, 'not_found', __('El recurso solicitado no existe.'));
            }

            // Ninguna cuenta administradora puede quedar suspendida desde el backoffice.
            // La regla cubre la auto-suspensión (el actor siempre es admin activo por
            // middleware, así que target === actor entra acá) y la suspensión cruzada
            // entre administradores. Se elige 409 y no 403 porque el actor sí tiene el
            // permiso: lo que no admite la transición es el estado del recurso, que es
            // exactamente el caso que el contrato describe como Conflict. El code
            // admin_account_protected nombra el recurso protegido, no al actor, para que
            // el mismo mensaje sirva en los dos escenarios.
            if ($status === User::STATUS_SUSPENDED && $target->system_role === User::ROLE_ADMIN) {
                throw new ApiProblemException(
                    409,
                    'admin_account_protected',
                    __('Una cuenta administradora no puede suspenderse desde el backoffice.'),
                );
            }

            $before = $target->status;

            // Mismo estado: 200 sin auditoría. Un PATCH de estado es declarativo, así que
            // repetirlo no es un conflicto y no debe ensuciar la auditoría con un cambio
            // que nunca ocurrió. El contrato permite 409 pero no lo exige.
            if ($before === $status) {
                return $target->loadCount('profiles');
            }

            $updated = $this->users->updateStatus($target, $status);
            $audit = $this->auditLogs->create(
                actor: $actor,
                targetType: 'user',
                targetId: $updated->public_id,
                action: $status === User::STATUS_SUSPENDED ? 'user.suspended' : 'user.reactivated',
                reason: $normalizedReason,
                safeChanges: [
                    'status' => ['before' => $before, 'after' => $status],
                ],
                requestId: $requestId,
                createdAt: $this->clock->now(),
            );
            // T19: evento de cuenta (profile_id null), correo esencial.
            $this->notifications->administrativeChange(
                $updated,
                null,
                $status === User::STATUS_SUSPENDED ? 'account_suspended' : 'account_reactivated',
                $audit->public_id,
            );

            // La suspensión de cuenta corta (o devuelve) todos sus perfiles a la vez.
            foreach ($this->profiles->ownedSummaries($updated) as $profile) {
                $this->realtime->accessChanged($profile);
            }

            return $updated->loadCount('profiles');
        });
    }

    public function updateProfileStatus(
        User $actor,
        string $profilePublicId,
        string $status,
        string $reason,
        string $requestId,
    ): Profile {
        $this->assertActiveAdmin($actor);
        $normalizedReason = $this->normalizedReason($reason);

        return DB::transaction(function () use ($actor, $profilePublicId, $status, $normalizedReason, $requestId): Profile {
            $candidate = $this->profiles->findByPublicId($profilePublicId);
            if ($candidate === null) {
                throw new ApiProblemException(404, 'not_found', __('El recurso solicitado no existe.'));
            }

            $locked = $this->profiles->lockById($candidate->id);
            // El grant se bloquea pero nunca se escribe: suspender o restaurar un perfil
            // no concede, revoca ni versiona habilitación. El lock solo serializa esta
            // operación contra AccessService::replaceGrant.
            $grant = $this->grants->lockForProfile($locked);
            $before = $locked->status;
            $target = $this->resolveProfileStatus($locked, $status);

            if ($before !== $target) {
                $this->profiles->updateStatus($locked, $target);
                $locked->loadMissing('owner');
                $owner = $locked->getRelation('owner');
                $audit = $this->auditLogs->create(
                    actor: $actor,
                    targetType: 'profile',
                    targetId: $locked->public_id,
                    action: $target === ProfileStatus::Suspended ? 'profile.suspended' : 'profile.restored',
                    reason: $normalizedReason,
                    safeChanges: [
                        'status' => ['before' => $before->value, 'after' => $target->value],
                        'owner_id' => $owner instanceof User ? $owner->public_id : null,
                        'access_grant_version' => $grant?->version,
                    ],
                    requestId: $requestId,
                    createdAt: $this->clock->now(),
                );
                if ($owner instanceof User) {
                    $this->notifications->administrativeChange(
                        $owner,
                        $locked,
                        $target === ProfileStatus::Suspended ? 'profile_suspended' : 'profile_restored',
                        $audit->public_id,
                    );
                }
                $this->realtime->accessChanged($locked);
            }

            return $this->attachEvaluation(
                $this->profiles->loadForAdminResponse($locked->refresh(), $this->clock->now()),
            );
        });
    }

    /**
     * El contrato solo admite published|suspended como intención. Restaurar devuelve
     * al perfil su estado legítimo fuera de suspensión: published si ya había
     * completado el onboarding, draft si nunca lo completó. Publicar un borrador
     * incompleto desde el backoffice fabricaría una ficha lista sin cumplir los
     * requisitos de finalización, y rechazar la restauración dejaría a un borrador
     * suspendido sin salida, porque el titular no puede completarlo mientras está
     * suspendido.
     */
    private function resolveProfileStatus(Profile $profile, string $status): ProfileStatus
    {
        if ($status === ProfileStatus::Suspended->value) {
            return ProfileStatus::Suspended;
        }

        return $profile->completed_at === null ? ProfileStatus::Draft : ProfileStatus::Published;
    }

    private function attachEvaluation(Profile $profile): Profile
    {
        $profile->loadMissing('owner');
        $owner = $profile->getRelation('owner');

        if (! $owner instanceof User) {
            throw new ApiProblemException(404, 'not_found', __('El recurso solicitado no existe.'));
        }

        $profile->setRelation('accessEvaluation', $this->access->evaluate($owner, $profile));

        return $profile;
    }

    private function normalizedReason(string $reason): string
    {
        $trimmed = trim($reason);

        if (mb_strlen($trimmed) < 3) {
            throw new ApiProblemException(422, 'validation_failed', __('Los datos enviados no son válidos.'), [
                'reason' => [__('El motivo debe tener al menos 3 caracteres no vacíos.')],
            ]);
        }

        return $trimmed;
    }

    private function assertActiveAdmin(User $actor): void
    {
        if ($actor->system_role !== User::ROLE_ADMIN || $actor->status !== User::STATUS_ACTIVE) {
            throw new ApiProblemException(403, 'forbidden', __('No tenés permisos para realizar esta acción.'));
        }
    }
}
