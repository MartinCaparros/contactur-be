<?php

namespace App\Services;

use App\Contracts\Clock;
use App\Models\AuthSession;
use App\Models\User;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\JWT;

final class AccessTokenService
{
    public function __construct(
        private readonly JWT $jwt,
        private readonly Clock $clock,
    ) {}

    public function issue(User $user, AuthSession $session): string
    {
        $now = $this->clock->now();

        return $this->jwt->claims([
            'iss' => (string) config('contactur.jwt.issuer'),
            'aud' => (string) config('contactur.jwt.audience'),
            'sid' => $session->sid,
            'iat' => $now->timestamp,
            'nbf' => $now->timestamp,
            'exp' => $now->addSeconds($this->expiresIn())->timestamp,
            'jti' => (string) Str::ulid(),
        ])->fromUser($user);
    }

    /** @return array<string, mixed> */
    public function parseAndValidate(string $token): array
    {
        $payload = $this->jwt->setToken($token)->getPayload()->toArray();
        $expectedAudience = (string) config('contactur.jwt.audience');
        $audience = $payload['aud'] ?? null;
        $subject = $payload['sub'] ?? null;
        $hasExpectedAudience = $audience === $expectedAudience
            || (is_array($audience) && in_array($expectedAudience, $audience, true));

        if (
            ($payload['iss'] ?? null) !== config('contactur.jwt.issuer')
            || ! $hasExpectedAudience
            || ! is_string($payload['sid'] ?? null)
            || ! is_string($subject)
            || ! $this->isCanonicalUserId($subject)
        ) {
            throw new \UnexpectedValueException('Invalid access token claims.');
        }

        return $payload;
    }

    public function expiresIn(): int
    {
        return max(1, (int) config('jwt.ttl')) * 60;
    }

    private function isCanonicalUserId(string $subject): bool
    {
        if (preg_match('/^[1-9][0-9]*$/D', $subject) !== 1) {
            return false;
        }

        $userId = (int) $subject;

        return $userId > 0 && (string) $userId === $subject;
    }
}
