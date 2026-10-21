<?php

namespace App\Repositories;

use App\Models\AccountToken;
use Carbon\CarbonImmutable;

final class AccountTokenRepository
{
    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): AccountToken
    {
        return AccountToken::query()->create($attributes);
    }

    public function lockByHash(string $tokenHash): ?AccountToken
    {
        return AccountToken::query()
            ->where('token_hash', $tokenHash)
            ->lockForUpdate()
            ->first();
    }

    public function invalidateActive(int $userId, string $purpose, CarbonImmutable $now): void
    {
        AccountToken::query()
            ->where('user_id', $userId)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => $now]);
    }

    public function consume(AccountToken $token, CarbonImmutable $now): void
    {
        $token->forceFill(['consumed_at' => $now])->save();
    }
}
