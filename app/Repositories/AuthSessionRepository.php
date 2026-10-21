<?php

namespace App\Repositories;

use App\Models\AuthSession;
use Carbon\CarbonImmutable;

final class AuthSessionRepository
{
    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): AuthSession
    {
        return AuthSession::query()->create($attributes);
    }

    public function findBySid(string $sid): ?AuthSession
    {
        return AuthSession::query()->find($sid);
    }

    public function lockByRefreshHash(string $refreshHash): ?AuthSession
    {
        return AuthSession::query()
            ->where('refresh_hash', $refreshHash)
            ->lockForUpdate()
            ->first();
    }

    public function markRotated(
        AuthSession $session,
        string $replacementSid,
        CarbonImmutable $now,
    ): void {
        $session->fill([
            'last_used_at' => $now,
            'rotated_at' => $now,
            'revoked_at' => $now,
            'replaced_by_sid' => $replacementSid,
        ]);
        $session->save();
    }

    public function revokeSid(string $sid, CarbonImmutable $now): void
    {
        AuthSession::query()
            ->whereKey($sid)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => $now]);
    }

    public function revokeFamily(string $familyId, CarbonImmutable $now): void
    {
        AuthSession::query()
            ->where('family_id', $familyId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => $now]);
    }

    public function revokeAllForUser(int $userId, CarbonImmutable $now): void
    {
        AuthSession::query()
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => $now]);
    }
}
