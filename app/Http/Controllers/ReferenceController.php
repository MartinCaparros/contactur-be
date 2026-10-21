<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiProblemException;
use App\Http\Resources\ReferenceResource;
use App\Models\Reference;
use App\Repositories\ReferenceRepository;
use App\Services\ReferenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Referencias del titular (T20/R17). Solo validación HTTP: participación,
 * estado del match, acceso y moderación los decide ReferenceService.
 */
final class ReferenceController extends ApiController
{
    public function __construct(private readonly ReferenceService $references) {}

    /**
     * El texto llega recortado por TrimStrings (y se recorta otra vez por las
     * dudas): los límites 20–2000 se miden sobre el texto sin espacios de borde.
     */
    public function store(Request $request, string $profile, string $contactRequest): JsonResponse
    {
        $data = $this->validatePayload($request, [
            'body' => ['required', 'string', 'min:20', 'max:2000'],
        ], ['body']);
        $body = trim((string) $data['body']);

        if (mb_strlen($body) < 20) {
            throw new ApiProblemException(422, 'validation_failed', __('Los datos enviados no son válidos.'), [
                'body' => [__('La referencia debe tener al menos 20 caracteres.')],
            ]);
        }

        return (new ReferenceResource($this->references->create(
            $this->authenticatedUser($request),
            $profile,
            $contactRequest,
            $body,
        )))->response($request)->setStatusCode(201);
    }

    public function index(Request $request, string $profile): AnonymousResourceCollection
    {
        $maxPerPage = (int) config('contactur.references.max_per_page');
        $data = $this->validatePayload($request, [
            'direction' => ['sometimes', 'string', Rule::in([
                ReferenceRepository::DIRECTION_AUTHORED,
                ReferenceRepository::DIRECTION_RECEIVED,
            ])],
            'cursor' => ['sometimes', 'string', 'regex:/^[0-9]+$/', 'max:20'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.$maxPerPage],
        ], ['direction', 'cursor', 'per_page']);
        $perPage = isset($data['per_page'])
            ? (int) $data['per_page']
            : (int) config('contactur.references.default_per_page');

        $page = $this->references->list(
            $this->authenticatedUser($request),
            $profile,
            isset($data['direction']) ? (string) $data['direction'] : null,
            isset($data['cursor']) ? (int) $data['cursor'] : null,
            $perPage,
        );

        return ReferenceResource::collection($page['items'])->additional([
            'meta' => [
                'next_cursor' => $page['next_cursor'],
                'per_page' => $perPage,
            ],
        ]);
    }

    public function updateVisibility(Request $request, string $profile, string $reference): ReferenceResource
    {
        $data = $this->validatePayload($request, [
            'visibility' => ['required', 'string', Rule::in(Reference::VISIBILITIES)],
        ], ['visibility']);

        return new ReferenceResource($this->references->updateVisibility(
            $this->authenticatedUser($request),
            $profile,
            $reference,
            (string) $data['visibility'],
        ));
    }
}
