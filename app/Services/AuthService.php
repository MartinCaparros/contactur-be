<?php

namespace App\Services;

use App\Contracts\Clock;
use App\Data\AuthSessionResult;
use App\Exceptions\ApiProblemException;
use App\Models\AccountToken;
use App\Models\AuthSession;
use App\Models\User;
use App\Notifications\EmailChangedNotification;
use App\Notifications\EmailVerificationNotification;
use App\Notifications\EmailVerifiedNotification;
use App\Notifications\PasswordResetNotification;
use App\Repositories\AccountTokenRepository;
use App\Repositories\AuthSessionRepository;
use App\Repositories\UserRepository;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

final class AuthService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly AuthSessionRepository $sessions,
        private readonly AccountTokenRepository $tokens,
        private readonly AccessTokenService $accessTokens,
        private readonly NotificationService $notifications,
        private readonly Clock $clock,
    ) {}

    /** @param array{name: string, email: string, password: string} $data */
    public function register(array $data, ?string $ipAddress, ?string $userAgent): AuthSessionResult
    {
        $email = $this->normalizeEmail($data['email']);

        if ($this->users->findByEmail($email) !== null) {
            throw $this->conflict('email_conflict', __('No se pudo registrar la cuenta con ese email.'));
        }

        try {
            [$user, $session, $refreshToken, $verificationToken] = DB::transaction(function () use ($data, $email, $ipAddress, $userAgent): array {
                $user = $this->users->create([
                    'name' => trim($data['name']),
                    'email' => $email,
                    'password' => Hash::make($data['password']),
                    'system_role' => User::ROLE_USER,
                    'status' => User::STATUS_ACTIVE,
                    // El idioma de la request (Accept-Language del BFF): el correo de
                    // verificación y los siguientes salen en el idioma del alta.
                    'locale' => User::accountLocaleFor(App::getLocale()),
                    'timezone' => 'America/Argentina/Buenos_Aires',
                    'notification_preferences' => [],
                ]);
                [$session, $refreshToken] = $this->createSession($user, null, null, $ipAddress, $userAgent);
                $verificationToken = $this->createAccountToken(
                    $user,
                    AccountToken::PURPOSE_EMAIL_VERIFICATION,
                    (int) config('contactur.auth.email_verification_ttl_minutes'),
                );
                // T19: bienvenida solo in-app; el correo de verificación ya sale abajo.
                $this->notifications->welcome($user);

                return [$user, $session, $refreshToken, $verificationToken];
            });
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23505') {
                throw $this->conflict('email_conflict', __('No se pudo registrar la cuenta con ese email.'));
            }

            throw $exception;
        }

        $user->notify(new EmailVerificationNotification($verificationToken));

        return $this->sessionResult($user, $session, $refreshToken);
    }

    public function login(string $email, string $password, ?string $ipAddress, ?string $userAgent): AuthSessionResult
    {
        $user = $this->users->findByEmail($this->normalizeEmail($email));

        if (
            $user === null
            || $user->status !== User::STATUS_ACTIVE
            || ! Hash::check($password, $user->password)
        ) {
            throw $this->unauthorized();
        }

        [$session, $refreshToken] = DB::transaction(
            fn (): array => $this->createSession($user, null, null, $ipAddress, $userAgent),
        );

        return $this->sessionResult($user, $session, $refreshToken);
    }

    public function refresh(string $refreshToken, ?string $ipAddress, ?string $userAgent): AuthSessionResult
    {
        $now = $this->clock->now();
        $refreshHash = $this->hashToken($refreshToken);

        $rotated = DB::transaction(function () use ($refreshHash, $now, $ipAddress, $userAgent): ?array {
            $current = $this->sessions->lockByRefreshHash($refreshHash);

            if ($current === null) {
                return null;
            }

            if ($current->revoked_at !== null || $current->rotated_at !== null) {
                $this->sessions->revokeFamily($current->family_id, $now);

                return null;
            }

            if ($current->expires_at->lessThanOrEqualTo($now)) {
                $this->sessions->revokeFamily($current->family_id, $now);

                return null;
            }

            $user = $this->users->findById($current->user_id);

            if ($user === null || $user->status !== User::STATUS_ACTIVE) {
                $this->sessions->revokeFamily($current->family_id, $now);

                return null;
            }

            [$replacement, $newRefreshToken] = $this->createSession(
                $user,
                $current->family_id,
                $current->expires_at,
                $ipAddress,
                $userAgent,
            );
            $this->sessions->markRotated($current, $replacement->sid, $now);

            return [$user, $replacement, $newRefreshToken];
        });

        if ($rotated === null) {
            throw $this->unauthorized();
        }

        [$user, $session, $newRefreshToken] = $rotated;

        return $this->sessionResult($user, $session, $newRefreshToken);
    }

    public function logout(string $sid): void
    {
        $this->sessions->revokeSid($sid, $this->clock->now());
    }

    public function requestPasswordReset(string $email): void
    {
        $user = $this->users->findByEmail($this->normalizeEmail($email));

        if ($user === null || $user->status !== User::STATUS_ACTIVE) {
            Hash::check(Str::random(32), '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');

            return;
        }

        $rawToken = DB::transaction(fn (): string => $this->createAccountToken(
            $user,
            AccountToken::PURPOSE_PASSWORD_RESET,
            (int) config('contactur.auth.password_reset_ttl_minutes'),
        ));

        $user->notify(new PasswordResetNotification($rawToken, $user->email));
    }

    /** @param array{token: string, email: string, password: string} $data */
    public function resetPassword(array $data): void
    {
        $now = $this->clock->now();
        $email = $this->normalizeEmail($data['email']);

        $reset = DB::transaction(function () use ($data, $email, $now): bool {
            $token = $this->tokens->lockByHash($this->hashToken($data['token']));

            if (
                $token === null
                || $token->purpose !== AccountToken::PURPOSE_PASSWORD_RESET
                || $token->consumed_at !== null
                || $token->expires_at->lessThanOrEqualTo($now)
            ) {
                return false;
            }

            $user = $this->users->findById($token->user_id);

            if ($user === null || $this->normalizeEmail($user->email) !== $email) {
                return false;
            }

            $this->users->update($user, ['password' => Hash::make($data['password'])]);
            $this->tokens->consume($token, $now);
            $this->sessions->revokeAllForUser($user->id, $now);

            return true;
        });

        if (! $reset) {
            throw $this->invalidToken();
        }
    }

    public function verifyEmail(string $rawToken): void
    {
        $now = $this->clock->now();

        $result = DB::transaction(function () use ($rawToken, $now): ?array {
            $token = $this->tokens->lockByHash($this->hashToken($rawToken));

            if ($token === null || $token->expires_at->lessThanOrEqualTo($now)) {
                return null;
            }

            $user = $this->users->findById($token->user_id);

            if ($user === null) {
                return null;
            }

            if ($token->purpose === AccountToken::PURPOSE_EMAIL_VERIFICATION) {
                if ($token->consumed_at !== null) {
                    return $user->email_verified_at !== null ? [$user, null, false] : null;
                }

                $user = $this->users->update($user, ['email_verified_at' => $now]);
                $this->tokens->consume($token, $now);
                // T19: in-app; el correo sigue siendo EmailVerifiedNotification (no se duplica).
                $this->notifications->emailVerified($user, $token->id, emailChange: false);

                return [$user, null, true];
            }

            if ($token->purpose !== AccountToken::PURPOSE_EMAIL_CHANGE || $token->target_email === null) {
                return null;
            }

            $targetEmail = $this->normalizeEmail($token->target_email);

            if ($token->consumed_at !== null) {
                return $this->normalizeEmail($user->email) === $targetEmail ? [$user, null, false] : null;
            }

            if (
                $user->pending_email === null
                || $this->normalizeEmail($user->pending_email) !== $targetEmail
            ) {
                return null;
            }

            if ($this->users->emailBelongsToAnotherUser($targetEmail, $user->id)) {
                throw $this->conflict('email_conflict', __('El email solicitado ya no está disponible.'));
            }

            $oldEmail = $user->email;
            $user = $this->users->update($user, [
                'email' => $targetEmail,
                'pending_email' => null,
                'pending_email_requested_at' => null,
                'email_verified_at' => $now,
            ]);
            $this->tokens->consume($token, $now);
            $this->notifications->emailVerified($user, $token->id, emailChange: true);

            return [$user, $oldEmail, true];
        });

        if ($result === null) {
            throw $this->invalidToken();
        }

        [$user, $oldEmail, $notifyVerified] = $result;

        if ($oldEmail !== null) {
            // Dirección suelta: sin HasLocalePreference, el idioma de la cuenta va explícito.
            Notification::route('mail', $oldEmail)
                ->notify((new EmailChangedNotification($user->email))->locale($user->preferredLocale()));
        }

        if ($notifyVerified) {
            $user->notify(new EmailVerifiedNotification);
        }
    }

    public function resendEmailVerification(User $user): void
    {
        if ($user->email_verified_at !== null) {
            throw $this->conflict('email_already_verified', __('El email ya fue verificado.'));
        }

        $rawToken = DB::transaction(fn (): string => $this->createAccountToken(
            $user,
            AccountToken::PURPOSE_EMAIL_VERIFICATION,
            (int) config('contactur.auth.email_verification_ttl_minutes'),
        ));

        $user->notify(new EmailVerificationNotification($rawToken));
    }

    public function requestEmailChange(User $user, string $email, string $password): void
    {
        if (! Hash::check($password, $user->password)) {
            throw new ApiProblemException(422, 'validation_failed', __('Los datos enviados no son válidos.'), [
                'password' => [__('La contraseña no es correcta.')],
            ]);
        }

        $newEmail = $this->normalizeEmail($email);

        if (
            $newEmail === $this->normalizeEmail($user->email)
            || $this->users->emailBelongsToAnotherUser($newEmail, $user->id)
        ) {
            throw $this->conflict('email_conflict', __('El email solicitado no está disponible.'));
        }

        [$updatedUser, $rawToken] = DB::transaction(function () use ($user, $newEmail): array {
            $updatedUser = $this->users->update($user, [
                'pending_email' => $newEmail,
                'pending_email_requested_at' => $this->clock->now(),
            ]);
            $rawToken = $this->createAccountToken(
                $updatedUser,
                AccountToken::PURPOSE_EMAIL_CHANGE,
                (int) config('contactur.auth.email_change_ttl_minutes'),
                $newEmail,
            );

            return [$updatedUser, $rawToken];
        });

        // Dirección suelta: sin HasLocalePreference, el idioma de la cuenta va explícito.
        Notification::route('mail', $newEmail)
            ->notify((new EmailVerificationNotification($rawToken, true))->locale($updatedUser->preferredLocale()));
    }

    public function changePassword(User $user, string $currentPassword, string $newPassword): void
    {
        if (! Hash::check($currentPassword, $user->password)) {
            throw new ApiProblemException(422, 'validation_failed', __('Los datos enviados no son válidos.'), [
                'current_password' => [__('La contraseña actual no es correcta.')],
            ]);
        }

        DB::transaction(function () use ($user, $newPassword): void {
            $this->users->update($user, ['password' => Hash::make($newPassword)]);
            $this->sessions->revokeAllForUser($user->id, $this->clock->now());
        });
    }

    /**
     * @return array{0: AuthSession, 1: string}
     */
    private function createSession(
        User $user,
        ?string $familyId,
        ?CarbonImmutable $expiresAt,
        ?string $ipAddress,
        ?string $userAgent,
    ): array {
        $rawToken = Str::random(64);
        $session = $this->sessions->create([
            'user_id' => $user->id,
            'family_id' => $familyId ?? (string) Str::ulid(),
            'refresh_hash' => $this->hashToken($rawToken),
            'expires_at' => $expiresAt ?? $this->clock->now()->addMinutes(
                (int) config('contactur.auth.refresh_ttl_minutes'),
            ),
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent === null ? null : Str::limit($userAgent, 255, ''),
        ]);

        return [$session, $rawToken];
    }

    private function createAccountToken(
        User $user,
        string $purpose,
        int $ttlMinutes,
        ?string $targetEmail = null,
    ): string {
        $now = $this->clock->now();
        $rawToken = Str::random(64);
        $this->tokens->invalidateActive($user->id, $purpose, $now);
        $this->tokens->create([
            'user_id' => $user->id,
            'purpose' => $purpose,
            'token_hash' => $this->hashToken($rawToken),
            'target_email' => $targetEmail,
            'expires_at' => $now->addMinutes($ttlMinutes),
        ]);

        return $rawToken;
    }

    private function sessionResult(User $user, AuthSession $session, string $refreshToken): AuthSessionResult
    {
        return new AuthSessionResult(
            $user,
            $this->accessTokens->issue($user, $session),
            $refreshToken,
            $this->accessTokens->expiresIn(),
        );
    }

    private function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    private function normalizeEmail(string $email): string
    {
        return Str::lower(trim($email));
    }

    private function unauthorized(): ApiProblemException
    {
        return new ApiProblemException(401, 'unauthorized', __('No autorizado.'));
    }

    private function invalidToken(): ApiProblemException
    {
        return new ApiProblemException(422, 'validation_failed', __('Los datos enviados no son válidos.'), [
            'token' => [__('El token no es válido o expiró.')],
        ]);
    }

    private function conflict(string $code, string $message): ApiProblemException
    {
        return new ApiProblemException(409, $code, $message);
    }
}
