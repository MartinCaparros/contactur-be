<?php

namespace App\Repositories;

use App\Models\Profile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Registro de Idempotency-Key: perfil actor + actor + operación + key con el hash
 * del payload normalizado y el snapshot (status y cuerpo) de la respuesta original.
 */
final class IdempotencyRepository
{
    private const TABLE = 'idempotency_keys';

    /** @return array{request_hash: string, response_status: int, response_body: array<string, mixed>}|null */
    public function find(Profile $profile, User $actor, string $operation, string $key): ?array
    {
        $row = DB::table(self::TABLE)
            ->where('profile_id', $profile->id)
            ->where('actor_user_id', $actor->id)
            ->where('operation', $operation)
            ->where('idempotency_key', $key)
            ->first(['request_hash', 'response_status', 'response_body']);

        if ($row === null) {
            return null;
        }

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $row->response_body, true, 512, JSON_THROW_ON_ERROR);

        return [
            'request_hash' => (string) $row->request_hash,
            'response_status' => (int) $row->response_status,
            'response_body' => $body,
        ];
    }

    /** @param array<string, mixed> $body */
    public function create(
        Profile $profile,
        User $actor,
        string $operation,
        string $key,
        string $requestHash,
        int $status,
        array $body,
        CarbonImmutable $createdAt,
    ): void {
        DB::table(self::TABLE)->insert([
            'profile_id' => $profile->id,
            'actor_user_id' => $actor->id,
            'operation' => $operation,
            'idempotency_key' => $key,
            'request_hash' => $requestHash,
            'response_status' => $status,
            'response_body' => json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => $createdAt->utc()->format('Y-m-d H:i:s.uP'),
        ]);
    }
}
