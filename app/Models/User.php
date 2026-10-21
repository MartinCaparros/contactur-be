<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;

/**
 * @property int $id
 * @property string $public_id
 * @property string $name
 * @property string $email
 * @property string|null $pending_email
 * @property CarbonImmutable|null $pending_email_requested_at
 * @property CarbonImmutable|null $email_verified_at
 * @property string $password
 * @property string $system_role
 * @property string $status
 * @property string $locale
 * @property string $timezone
 * @property array<int, array{event_type: string, email_enabled: bool}>|null $notification_preferences
 */
#[Fillable([
    'name',
    'email',
    'pending_email',
    'pending_email_requested_at',
    'email_verified_at',
    'password',
    'locale',
    'timezone',
    'notification_preferences',
])]
#[Hidden(['id', 'public_id', 'password', 'remember_token', 'pending_email', 'pending_email_requested_at'])]
class User extends Authenticatable implements HasLocalePreference, JWTSubject
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUlids, Notifiable;

    /** Valores de `users.locale` (contrato `AccountLocale`). */
    public const LOCALE_SPANISH = 'es-AR';

    public const LOCALE_ENGLISH = 'en';

    public const ROLE_USER = 'user';

    public const ROLE_ADMIN = 'admin';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public function newUniqueId(): string
    {
        return (string) Str::ulid();
    }

    /** @return array<int, string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /**
     * Idioma de Laravel para los correos y notificaciones de esta cuenta. Laravel lo usa
     * solo al notificar al usuario (`$user->notify()`, `Mail::to($user)`); los envíos a
     * una dirección suelta lo fijan con `->locale()`.
     */
    public function preferredLocale(): string
    {
        return self::laravelLocale($this->locale);
    }

    /** `en` para la cuenta en inglés; cualquier otro valor guardado es español. */
    public static function laravelLocale(?string $accountLocale): string
    {
        return $accountLocale === self::LOCALE_ENGLISH ? 'en' : 'es';
    }

    /** Valor de `users.locale` para el idioma de Laravel de la request. */
    public static function accountLocaleFor(string $laravelLocale): string
    {
        return $laravelLocale === 'en' ? self::LOCALE_ENGLISH : self::LOCALE_SPANISH;
    }

    public function getJWTIdentifier(): string
    {
        return (string) $this->getKey();
    }

    /** @return array<string, mixed> */
    public function getJWTCustomClaims(): array
    {
        return [];
    }

    /** @return HasMany<Profile, $this> */
    public function profiles(): HasMany
    {
        return $this->hasMany(Profile::class, 'owner_user_id')->orderBy('id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'email_verified_at' => 'immutable_datetime',
            'pending_email_requested_at' => 'immutable_datetime',
            'password' => 'hashed',
            'notification_preferences' => 'array',
        ];
    }
}
