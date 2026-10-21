<?php

namespace App\Http\Middleware;

use App\Contracts\Clock;
use App\Exceptions\ApiProblemException;
use App\Models\User;
use App\Repositories\AuthSessionRepository;
use App\Repositories\UserRepository;
use App\Services\AccessTokenService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class AuthenticateAccessToken
{
    public function __construct(
        private readonly AccessTokenService $tokens,
        private readonly AuthSessionRepository $sessions,
        private readonly UserRepository $users,
        private readonly Clock $clock,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $rawToken = $request->bearerToken();

            if ($rawToken === null || $rawToken === '') {
                throw new \UnexpectedValueException('Missing token.');
            }

            $payload = $this->tokens->parseAndValidate($rawToken);
            $sid = $payload['sid'];
            $subject = $payload['sub'];
            $userId = (int) $subject;
            $session = $this->sessions->findBySid($sid);

            if (
                $session === null
                || $session->user_id !== $userId
                || $session->revoked_at !== null
                || $session->expires_at->lessThanOrEqualTo($this->clock->now())
            ) {
                throw new \UnexpectedValueException('Invalid session.');
            }

            $user = $this->users->findById($userId);

            if (
                $user === null
                || ! in_array($user->status, [User::STATUS_ACTIVE, User::STATUS_SUSPENDED], true)
            ) {
                throw new \UnexpectedValueException('Invalid account.');
            }
        } catch (Throwable) {
            throw new ApiProblemException(401, 'unauthorized', __('No autorizado.'));
        }

        $request->attributes->set('auth_sid', $sid);
        $request->setUserResolver(static fn (): User => $user);

        return $next($request);
    }
}
