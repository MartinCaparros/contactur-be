<?php

namespace App\Http\Controllers;

use App\Http\Resources\NotificationResource;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class NotificationController extends ApiController
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function index(Request $request, string $profile): AnonymousResourceCollection
    {
        $maxPerPage = (int) config('contactur.notifications.max_per_page');
        $data = $this->validatePayload($request, [
            // Query string: "true"/"false"/"1"/"0" (boolean del contrato serializado).
            'unread' => ['sometimes', 'string', 'in:true,false,1,0'],
            'cursor' => ['sometimes', 'string', 'regex:/^[0-9]+$/', 'max:20'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.$maxPerPage],
        ], ['unread', 'cursor', 'per_page']);
        $perPage = isset($data['per_page'])
            ? (int) $data['per_page']
            : (int) config('contactur.notifications.default_per_page');

        $page = $this->notifications->list(
            $this->authenticatedUser($request),
            $profile,
            in_array($data['unread'] ?? 'false', ['true', '1'], true),
            isset($data['cursor']) ? (int) $data['cursor'] : null,
            $perPage,
        );

        return NotificationResource::collection($page['items'])->additional([
            'meta' => [
                'next_cursor' => $page['next_cursor'],
                'per_page' => $perPage,
            ],
        ]);
    }

    /** Sin body en el contrato: cualquier campo es 422. */
    public function read(Request $request, string $profile, string $notification): NotificationResource
    {
        $this->validatePayload($request, [], []);

        return new NotificationResource($this->notifications->markRead(
            $this->authenticatedUser($request),
            $profile,
            $notification,
        ));
    }
}
