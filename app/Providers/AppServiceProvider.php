<?php

namespace App\Providers;

use App\Contracts\Clock;
use App\Models\User;
use App\Support\ApiErrorResponse;
use App\Support\SystemClock;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Clock::class, SystemClock::class);
    }

    public function boot(): void
    {
        RateLimiter::for('auth.login', function (Request $request): Limit {
            return Limit::perMinute(max(1, (int) config('contactur.rate_limits.login_per_minute')))
                ->by('login:'.$request->ip().':'.$this->emailKey($request))
                ->response(fn (Request $request, array $headers) => ApiErrorResponse::make(
                    $request,
                    429,
                    'rate_limited',
                    __('Demasiadas solicitudes. Intentá nuevamente más tarde.'),
                    [],
                    $headers,
                ));
        });

        // T22: el alta no tenía límite (12 altas seguidas desde una IP respondieron 201).
        // Por IP y por email: frena la creación masiva de cuentas y el reintento sobre una
        // misma dirección sin bloquear a otros visitantes.
        RateLimiter::for('auth.register', function (Request $request): array {
            $response = fn (Request $request, array $headers) => ApiErrorResponse::make(
                $request,
                429,
                'rate_limited',
                __('Demasiadas solicitudes. Intentá nuevamente más tarde.'),
                [],
                $headers,
            );

            return [
                Limit::perMinute(max(1, (int) config('contactur.rate_limits.register_per_minute')))
                    ->by('register-ip:'.$request->ip())
                    ->response($response),
                Limit::perHour(max(1, (int) config('contactur.rate_limits.register_per_email_hour')))
                    ->by('register-email:'.$this->emailKey($request))
                    ->response($response),
            ];
        });

        RateLimiter::for('auth.recovery', function (Request $request): Limit {
            return Limit::perMinute(max(1, (int) config('contactur.rate_limits.recovery_per_minute')))
                ->by('recovery:'.$request->ip().':'.$this->emailKey($request))
                ->response(fn (Request $request, array $headers) => ApiErrorResponse::make(
                    $request,
                    429,
                    'rate_limited',
                    __('Demasiadas solicitudes. Intentá nuevamente más tarde.'),
                    [],
                    $headers,
                ));
        });

        RateLimiter::for('auth.resend', function (Request $request): Limit {
            $user = $request->user();
            $identity = $user instanceof User ? (string) $user->id : (string) $request->ip();

            return Limit::perMinute(max(1, (int) config('contactur.rate_limits.resend_per_minute')))
                ->by('resend:'.$identity.':'.$request->ip())
                ->response(fn (Request $request, array $headers) => ApiErrorResponse::make(
                    $request,
                    429,
                    'rate_limited',
                    __('Demasiadas solicitudes. Intentá nuevamente más tarde.'),
                    [],
                    $headers,
                ));
        });
        // T19/R23: público, por IP. Hora y día como límites independientes.
        RateLimiter::for('support.inquiries', function (Request $request): array {
            $ip = (string) $request->ip();
            $response = fn (Request $request, array $headers) => ApiErrorResponse::make(
                $request,
                429,
                'rate_limited',
                __('Demasiadas solicitudes. Intentá nuevamente más tarde.'),
                [],
                $headers,
            );

            return [
                Limit::perHour(max(1, (int) config('contactur.rate_limits.support_inquiries_per_hour')))
                    ->by('support-inquiries-hour:'.$ip)
                    ->response($response),
                Limit::perDay(max(1, (int) config('contactur.rate_limits.support_inquiries_per_day')))
                    ->by('support-inquiries-day:'.$ip)
                    ->response($response),
            ];
        });

        RateLimiter::for('realtime.auth', function (Request $request): Limit {
            $user = $request->user();
            $identity = $user instanceof User ? (string) $user->id : (string) $request->ip();

            // Por cuenta: el BFF concentra la IP de todos los navegadores.
            return Limit::perMinute(max(1, (int) config('contactur.rate_limits.broadcast_auth_per_minute')))
                ->by('broadcast-auth:'.$identity)
                ->response(fn (Request $request, array $headers) => ApiErrorResponse::make(
                    $request,
                    429,
                    'rate_limited',
                    __('Demasiadas solicitudes. Intentá nuevamente más tarde.'),
                    [],
                    $headers,
                ));
        });
    }

    private function emailKey(Request $request): string
    {
        $email = $request->input('email');

        return hash('sha256', is_string($email) ? mb_strtolower(trim($email)) : 'none');
    }
}
