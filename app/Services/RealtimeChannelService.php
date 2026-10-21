<?php

namespace App\Services;

use App\Enums\AccessEffectiveState;
use App\Events\RealtimeInvalidation;
use App\Exceptions\ApiProblemException;
use App\Models\User;
use App\Repositories\ProfileRepository;
use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Contracts\Broadcasting\Factory as BroadcastFactory;
use RuntimeException;

/**
 * Autorización de canales privados de Reverb (R10, contrato
 * authorizePrivateBroadcastChannel). Se revalida en cada suscripción: un
 * socket abierto antes de una revocación no se re-autoriza al reconectar.
 *
 * Canales:
 * - private-profile.{public_id}: solo el titular del perfil, cuenta activa y
 *   perfil no suspendido. Un acceso vencido sí puede suscribirse (D21: lee su
 *   estado y avisos).
 * - private-directory: la cuenta necesita al menos un perfil propio con
 *   can_search vigente, la misma evaluación que exige GET /search.
 *
 * Perfil ajeno e inexistente responden el mismo 403 `forbidden`, así la
 * respuesta no revela si un ID pertenece a otra cuenta.
 */
final class RealtimeChannelService
{
    public const BROADCAST_CONNECTION = 'reverb';

    public const DIRECTORY_CHANNEL = 'private-'.RealtimeInvalidation::DIRECTORY_CHANNEL;

    public const PROFILE_CHANNEL_PREFIX = 'private-'.RealtimeInvalidation::PROFILE_CHANNEL_PREFIX;

    /** Formas de canal aceptadas; cualquier otra es un 422 de validación. */
    public const CHANNEL_PATTERN = '/\Aprivate-(?:directory|profile\.[0-9A-HJKMNP-TV-Z]{26})\z/';

    /**
     * Formato de socket_id de Pusher/Reverb (`^\d+\.\d+$`). Se usa \A…\z y no
     * ^…$ porque en PCRE `$` acepta un salto de línea final, que el SDK de
     * Pusher rechaza con excepción (sería un 500 en vez de un 422).
     */
    public const SOCKET_ID_PATTERN = '/\A\d+\.\d+\z/';

    public function __construct(
        private readonly ProfileRepository $profiles,
        private readonly AccessService $access,
        private readonly BroadcastFactory $broadcast,
    ) {}

    /** @return array{auth: string, channel_data: null} */
    public function authorize(User $actor, string $channelName, string $socketId): array
    {
        // Defensa en profundidad: la ruta ya exige account.active.
        if ($actor->status !== User::STATUS_ACTIVE) {
            throw new ApiProblemException(403, 'account_suspended', __('La cuenta está suspendida.'));
        }

        if ($channelName === self::DIRECTORY_CHANNEL) {
            $this->assertCanJoinDirectory($actor);
        } elseif (preg_match(self::CHANNEL_PATTERN, $channelName) === 1) {
            $this->assertCanJoinProfile($actor, substr($channelName, strlen(self::PROFILE_CHANNEL_PREFIX)));
        } else {
            throw new ApiProblemException(422, 'validation_failed', __('Los datos enviados no son válidos.'), [
                'channel_name' => [__('El canal solicitado no existe.')],
            ]);
        }

        return [
            'auth' => $this->sign($channelName, $socketId),
            'channel_data' => null,
        ];
    }

    private function assertCanJoinProfile(User $actor, string $profilePublicId): void
    {
        $profile = $this->profiles->findByPublicId($profilePublicId);

        if ($profile === null || $profile->owner_user_id !== $actor->id) {
            throw $this->forbidden();
        }

        $evaluation = $this->access->evaluate($actor, $profile);

        if ($evaluation->effectiveState === AccessEffectiveState::Suspended->value) {
            throw new ApiProblemException(
                403,
                $evaluation->denialCode ?? 'profile_suspended',
                $evaluation->denialCode === 'account_suspended' ? __('La cuenta está suspendida.') : __('El perfil está suspendido.'),
            );
        }
    }

    private function assertCanJoinDirectory(User $actor): void
    {
        foreach ($this->profiles->ownedSummaries($actor) as $profile) {
            if ($this->access->evaluate($actor, $profile, AccessService::CAPABILITY_SEARCH)->allowed) {
                return;
            }
        }

        throw $this->forbidden();
    }

    /**
     * Firma HMAC de Pusher (`key:hmac_sha256(socket_id:channel, secret)`) con el
     * SDK oficial y las credenciales de la conexión reverb, nunca del cliente.
     */
    private function sign(string $channelName, string $socketId): string
    {
        $broadcaster = $this->broadcast->connection(self::BROADCAST_CONNECTION);

        if (! $broadcaster instanceof PusherBroadcaster) {
            throw new RuntimeException('La conexión reverb no es un broadcaster compatible con Pusher.');
        }

        $signed = json_decode(
            $broadcaster->getPusher()->authorizeChannel($channelName, $socketId),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        if (! is_array($signed) || ! isset($signed['auth']) || ! is_string($signed['auth'])) {
            throw new RuntimeException('Respuesta de firma de canal inesperada.');
        }

        return $signed['auth'];
    }

    private function forbidden(): ApiProblemException
    {
        return new ApiProblemException(403, 'forbidden', __('No tenés permisos para suscribirte a este canal.'));
    }
}
