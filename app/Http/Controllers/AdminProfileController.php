<?php

namespace App\Http\Controllers;

use App\Enums\AccessEffectiveState;
use App\Enums\ProfileCategory;
use App\Enums\ProfileStatus;
use App\Http\Resources\AdminProfileResource;
use App\Services\AdminBackofficeService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

final class AdminProfileController extends ApiController
{
    public function __construct(private readonly AdminBackofficeService $backoffice) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $this->validatePayload($request, [
            'q' => ['sometimes', 'string', 'min:1', 'max:100'],
            'category' => ['sometimes', 'string', Rule::enum(ProfileCategory::class)],
            'status' => ['sometimes', 'string', Rule::enum(ProfileStatus::class)],
            'access_state' => ['sometimes', 'string', Rule::enum(AccessEffectiveState::class)],
            'cursor' => ['sometimes', 'string', 'regex:/^[0-9]+$/', 'max:20'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ], ['q', 'category', 'status', 'access_state', 'cursor', 'per_page']);
        $perPage = isset($data['per_page']) ? (int) $data['per_page'] : 20;
        $result = $this->backoffice->listProfiles(
            actor: $this->authenticatedUser($request),
            search: isset($data['q']) ? (string) $data['q'] : null,
            category: isset($data['category']) ? (string) $data['category'] : null,
            status: isset($data['status']) ? (string) $data['status'] : null,
            accessState: isset($data['access_state']) ? (string) $data['access_state'] : null,
            cursor: isset($data['cursor']) ? (int) $data['cursor'] : null,
            perPage: $perPage,
        );

        return AdminProfileResource::collection($result['items'])->additional([
            'meta' => [
                'next_cursor' => $result['next_cursor'],
                'per_page' => $perPage,
                'total' => $result['total'],
            ],
        ]);
    }

    public function updateStatus(Request $request, string $profile): AdminProfileResource
    {
        $data = $this->validatePayload($request, [
            'status' => ['required', 'string', Rule::in([
                ProfileStatus::Published->value,
                ProfileStatus::Suspended->value,
            ])],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ], ['status', 'reason']);

        return new AdminProfileResource($this->backoffice->updateProfileStatus(
            $this->authenticatedUser($request),
            $profile,
            (string) $data['status'],
            (string) $data['reason'],
            (string) $request->attributes->get('request_id'),
        ));
    }
}
