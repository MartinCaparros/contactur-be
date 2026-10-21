<?php

use App\Exceptions\ApiProblemException;
use App\Http\Middleware\AuthenticateAccessToken;
use App\Http\Middleware\RequestId;
use App\Http\Middleware\RequireActiveAccount;
use App\Http\Middleware\RequireActiveAdmin;
use App\Http\Middleware\SetRequestLocale;
use App\Support\ApiErrorResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        apiPrefix: 'api/v1',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(RequestId::class);
        $middleware->append(SetRequestLocale::class);
        $middleware->alias([
            'auth.access' => AuthenticateAccessToken::class,
            'account.active' => RequireActiveAccount::class,
            'admin.active' => RequireActiveAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReport([
            ApiProblemException::class,
        ]);
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (ApiProblemException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiErrorResponse::make(
                $request,
                $exception->status,
                $exception->errorCode,
                $exception->getMessage(),
                $exception->fields,
            );
        });

        $exceptions->render(function (ValidationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiErrorResponse::make(
                $request,
                422,
                'validation_failed',
                __('Los datos enviados no son válidos.'),
                $exception->errors(),
            );
        });

        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiErrorResponse::make($request, 401, 'unauthorized', __('No autorizado.'));
        });

        $exceptions->render(function (HttpResponseException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return $exception->getResponse();
        });

        $exceptions->render(function (HttpExceptionInterface $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $status = $exception->getStatusCode();
            [$code, $message] = match ($status) {
                404 => ['not_found', __('El recurso solicitado no existe.')],
                405 => ['method_not_allowed', __('El método no está permitido.')],
                429 => ['rate_limited', __('Demasiadas solicitudes. Intentá nuevamente más tarde.')],
                default => ['request_failed', __('No se pudo procesar la solicitud.')],
            };

            return ApiErrorResponse::make($request, $status, $code, $message);
        });

        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiErrorResponse::make(
                $request,
                500,
                'internal_error',
                __('No se pudo procesar la solicitud.'),
            );
        });
    })->create();
