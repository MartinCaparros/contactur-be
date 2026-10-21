<?php

namespace App\Http\Controllers;

use App\Http\Resources\AuthSessionResource;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthController extends ApiController
{
    public function __construct(private readonly AuthService $auth) {}

    public function register(Request $request): JsonResponse
    {
        $data = $this->validatePayload($request, [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'password' => ['required', 'string', 'min:12', 'max:128', 'confirmed'],
            'password_confirmation' => ['required', 'string', 'min:12', 'max:128'],
        ], ['name', 'email', 'password', 'password_confirmation']);

        $resource = new AuthSessionResource($this->auth->register(
            [
                'name' => (string) $data['name'],
                'email' => (string) $data['email'],
                'password' => (string) $data['password'],
            ],
            $request->ip(),
            $request->userAgent(),
        ));

        return $resource->response()->setStatusCode(201);
    }

    public function login(Request $request): AuthSessionResource
    {
        $data = $this->validatePayload($request, [
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'password' => ['required', 'string', 'min:1', 'max:128'],
        ], ['email', 'password']);

        return new AuthSessionResource($this->auth->login(
            (string) $data['email'],
            (string) $data['password'],
            $request->ip(),
            $request->userAgent(),
        ));
    }

    public function refresh(Request $request): AuthSessionResource
    {
        $data = $this->validatePayload($request, [
            'refresh_token' => ['required', 'string', 'min:20', 'max:4096'],
        ], ['refresh_token']);

        return new AuthSessionResource($this->auth->refresh(
            (string) $data['refresh_token'],
            $request->ip(),
            $request->userAgent(),
        ));
    }

    public function logout(Request $request): Response
    {
        $this->auth->logout((string) $request->attributes->get('auth_sid'));

        return response()->noContent();
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $this->validatePayload($request, [
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
        ], ['email']);
        $this->auth->requestPasswordReset((string) $data['email']);

        return response()->json(['data' => ['accepted' => true]], 202);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $this->validatePayload($request, [
            'token' => ['required', 'string', 'min:20', 'max:4096'],
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'password' => ['required', 'string', 'min:12', 'max:128', 'confirmed'],
            'password_confirmation' => ['required', 'string', 'min:12', 'max:128'],
        ], ['token', 'email', 'password', 'password_confirmation']);
        $this->auth->resetPassword([
            'token' => (string) $data['token'],
            'email' => (string) $data['email'],
            'password' => (string) $data['password'],
        ]);

        return response()->json(['data' => ['message' => __('Contraseña restablecida.')]]);
    }

    public function verifyEmail(Request $request): JsonResponse
    {
        $data = $this->validatePayload($request, [
            'token' => ['required', 'string', 'min:20', 'max:4096'],
        ], ['token']);
        $this->auth->verifyEmail((string) $data['token']);

        return response()->json(['data' => ['message' => __('Email verificado.')]]);
    }

    public function resendEmail(Request $request): JsonResponse
    {
        $this->auth->resendEmailVerification($this->authenticatedUser($request));

        return response()->json(['data' => ['accepted' => true]], 202);
    }
}
