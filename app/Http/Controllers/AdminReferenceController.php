<?php

namespace App\Http\Controllers;

use App\Http\Resources\AdminReferenceResource;
use App\Http\Resources\ReferenceResource;
use App\Services\ReferenceService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/** Moderación de referencias (T20/R17/R20): listado y ocultar/restaurar con motivo auditado. */
final class AdminReferenceController extends ApiController
{
    private const PUBLIC_ID_RULES = ['string', 'size:26', 'regex:/^[0-9A-HJKMNP-TV-Z]+$/'];

    public function __construct(private readonly ReferenceService $references) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $maxPerPage = (int) config('contactur.references.max_per_page');
        $data = $this->validatePayload($request, [
            // Query string: "true"/"false"/"1"/"0" (boolean del contrato serializado).
            'moderated' => ['sometimes', 'string', Rule::in(['true', 'false', '1', '0'])],
            'profile' => ['sometimes', ...self::PUBLIC_ID_RULES],
            'cursor' => ['sometimes', 'string', 'regex:/^[0-9]+$/', 'max:20'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.$maxPerPage],
        ], ['moderated', 'profile', 'cursor', 'per_page']);
        $perPage = isset($data['per_page'])
            ? (int) $data['per_page']
            : (int) config('contactur.references.default_per_page');

        $result = $this->references->listForAdmin(
            actor: $this->authenticatedUser($request),
            moderated: isset($data['moderated']) ? in_array($data['moderated'], ['true', '1'], true) : null,
            profilePublicId: isset($data['profile']) ? (string) $data['profile'] : null,
            cursor: isset($data['cursor']) ? (int) $data['cursor'] : null,
            perPage: $perPage,
        );

        return AdminReferenceResource::collection($result['items'])->additional([
            'meta' => [
                'next_cursor' => $result['next_cursor'],
                'per_page' => $perPage,
                'total' => $result['total'],
            ],
        ]);
    }

    public function moderate(Request $request, string $reference): ReferenceResource
    {
        $data = $this->validatePayload($request, [
            'action' => ['required', 'string', Rule::in(['hide', 'restore'])],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ], ['action', 'reason']);

        return new ReferenceResource($this->references->moderate(
            $this->authenticatedUser($request),
            $reference,
            (string) $data['action'],
            (string) $data['reason'],
            (string) $request->attributes->get('request_id'),
        ));
    }
}
