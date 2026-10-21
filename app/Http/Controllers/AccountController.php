<?php

namespace App\Http\Controllers;

use App\Http\Resources\CurrentAccountResource;
use App\Models\User;
use App\Services\AccountService;
use App\Services\AuthService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AccountController extends ApiController
{
    private const NOTIFICATION_EVENTS = [
        'welcome',
        'email_verified',
        'contact_request_received',
        'contact_request_accepted',
        'contact_request_rejected',
        'contact_request_expired',
        'administrative_change',
    ];

    public function __construct(
        private readonly AccountService $accounts,
        private readonly AuthService $auth,
    ) {}

    public function show(Request $request): CurrentAccountResource
    {
        return new CurrentAccountResource($this->accounts->current($this->authenticatedUser($request)));
    }

    public function update(Request $request): CurrentAccountResource
    {
        $strictBoolean = static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_bool($value)) {
                $fail(__('El campo debe ser booleano.'));
            }
        };
        $data = $this->validatePayload($request, [
            'name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'locale' => ['sometimes', 'string', 'in:'.User::LOCALE_SPANISH.','.User::LOCALE_ENGLISH],
            'timezone' => ['sometimes', 'string', 'in:America/Argentina/Buenos_Aires'],
            'notification_preferences' => ['sometimes', 'array', 'max:20'],
            'notification_preferences.*' => ['required', 'array:event_type,email_enabled'],
            'notification_preferences.*.event_type' => [
                'required',
                'string',
                'distinct',
                'in:'.implode(',', self::NOTIFICATION_EVENTS),
            ],
            'notification_preferences.*.email_enabled' => ['required', $strictBoolean],
        ], ['name', 'locale', 'timezone', 'notification_preferences'], true);

        return new CurrentAccountResource($this->accounts->update(
            $this->authenticatedUser($request),
            $data,
        ));
    }

    public function changeEmail(Request $request): JsonResponse
    {
        $data = $this->validatePayload($request, [
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'password' => ['required', 'string', 'min:1', 'max:128'],
        ], ['email', 'password']);
        $this->auth->requestEmailChange(
            $this->authenticatedUser($request),
            (string) $data['email'],
            (string) $data['password'],
        );

        return response()->json(['data' => ['accepted' => true]], 202);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $data = $this->validatePayload($request, [
            'current_password' => ['required', 'string', 'min:1', 'max:128'],
            'new_password' => ['required', 'string', 'min:12', 'max:128', 'confirmed'],
            'new_password_confirmation' => ['required', 'string', 'min:12', 'max:128'],
        ], ['current_password', 'new_password', 'new_password_confirmation']);
        $this->auth->changePassword(
            $this->authenticatedUser($request),
            (string) $data['current_password'],
            (string) $data['new_password'],
        );

        return response()->json(['data' => ['message' => __('Contraseña actualizada.')]]);
    }
}
