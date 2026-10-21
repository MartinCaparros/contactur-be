<?php

namespace App\Http\Controllers;

use App\Http\Resources\AdminUserResource;
use App\Models\User;
use App\Services\AdminBackofficeService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

final class AdminUserController extends ApiController
{
    public function __construct(private readonly AdminBackofficeService $backoffice) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $this->validatePayload($request, [
            'q' => ['sometimes', 'string', 'min:1', 'max:100'],
            'status' => ['sometimes', 'string', Rule::in([User::STATUS_ACTIVE, User::STATUS_SUSPENDED])],
            'cursor' => ['sometimes', 'string', 'regex:/^[0-9]+$/', 'max:20'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ], ['q', 'status', 'cursor', 'per_page']);
        $perPage = isset($data['per_page']) ? (int) $data['per_page'] : 20;
        $result = $this->backoffice->listUsers(
            actor: $this->authenticatedUser($request),
            search: isset($data['q']) ? (string) $data['q'] : null,
            status: isset($data['status']) ? (string) $data['status'] : null,
            cursor: isset($data['cursor']) ? (int) $data['cursor'] : null,
            perPage: $perPage,
        );

        return AdminUserResource::collection($result['items'])->additional([
            'meta' => [
                'next_cursor' => $result['next_cursor'],
                'per_page' => $perPage,
                'total' => $result['total'],
            ],
        ]);
    }

    public function updateStatus(Request $request, string $user): AdminUserResource
    {
        $data = $this->validatePayload($request, [
            'status' => ['required', 'string', Rule::in([User::STATUS_ACTIVE, User::STATUS_SUSPENDED])],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ], ['status', 'reason']);

        return new AdminUserResource($this->backoffice->updateUserStatus(
            $this->authenticatedUser($request),
            $user,
            (string) $data['status'],
            (string) $data['reason'],
            (string) $request->attributes->get('request_id'),
        ));
    }
}
