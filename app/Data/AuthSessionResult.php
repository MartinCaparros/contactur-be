<?php

namespace App\Data;

use App\Models\User;

final readonly class AuthSessionResult
{
    public function __construct(
        public User $user,
        public string $accessToken,
        public string $refreshToken,
        public int $expiresIn,
    ) {}
}
