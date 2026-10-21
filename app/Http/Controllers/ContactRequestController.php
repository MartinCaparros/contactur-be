<?php

namespace App\Http\Controllers;

use App\Enums\ContactRequestStatus;
use App\Exceptions\ApiProblemException;
use App\Http\Resources\ContactRequestResource;
use App\Http\Resources\MatchedContactsResource;
use App\Models\ContactRequest;
use App\Repositories\ContactRequestRepository;
use App\Services\ContactRequestService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

final class ContactRequestController extends ApiController
{
    private const PUBLIC_ID_RULES = ['string', 'size:26', 'regex:/^[0-9A-HJKMNP-TV-Z]+$/'];

    public function __construct(private readonly ContactRequestService $contactRequests) {}

    public function store(Request $request, string $profile): JsonResponse
    {
        $idempotencyKey = $this->idempotencyKey($request);
        $data = $this->validatePayload($request, [
            'target_profile_id' => ['required', ...self::PUBLIC_ID_RULES],
            'service_description' => ['required', 'string', 'min:1', 'max:5000'],
            'requested_from' => ['nullable', 'required_with:requested_to', 'string', 'date_format:Y-m-d'],
            'requested_to' => [
                'nullable',
                'required_with:requested_from',
                'string',
                'date_format:Y-m-d',
                'after_or_equal:requested_from',
            ],
            'requested_city_id' => ['nullable', 'integer', 'min:1', 'exists:cities,id'],
        ], ['target_profile_id', 'service_description', 'requested_from', 'requested_to', 'requested_city_id']);

        $payload = [
            'target_profile_id' => (string) $data['target_profile_id'],
            'service_description' => (string) $data['service_description'],
            'requested_from' => isset($data['requested_from']) ? (string) $data['requested_from'] : null,
            'requested_to' => isset($data['requested_to']) ? (string) $data['requested_to'] : null,
            'requested_city_id' => isset($data['requested_city_id']) ? (int) $data['requested_city_id'] : null,
        ];
        $this->assertRangeLength($payload['requested_from'], $payload['requested_to']);

        $result = $this->contactRequests->create(
            $this->authenticatedUser($request),
            $profile,
            $payload,
            $idempotencyKey,
            static fn (ContactRequest $created): array => [
                'data' => (new ContactRequestResource($created))->resolve($request),
            ],
        );

        return response()
            ->json($result->body, $result->status)
            ->header('Idempotency-Replayed', $result->replayed ? 'true' : 'false');
    }

    public function index(Request $request, string $profile): AnonymousResourceCollection
    {
        $maxPerPage = (int) config('contactur.contact_requests.max_per_page');
        $data = $this->validatePayload($request, [
            'direction' => ['sometimes', 'string', Rule::in([
                ContactRequestRepository::DIRECTION_SENT,
                ContactRequestRepository::DIRECTION_RECEIVED,
            ])],
            'status' => ['sometimes', 'string', Rule::enum(ContactRequestStatus::class)],
            'cursor' => ['sometimes', 'string', 'regex:/^[0-9]+$/', 'max:20'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.$maxPerPage],
        ], ['direction', 'status', 'cursor', 'per_page']);
        $perPage = isset($data['per_page'])
            ? (int) $data['per_page']
            : (int) config('contactur.contact_requests.default_per_page');

        $page = $this->contactRequests->list(
            $this->authenticatedUser($request),
            $profile,
            isset($data['direction']) ? (string) $data['direction'] : null,
            isset($data['status']) ? ContactRequestStatus::from((string) $data['status']) : null,
            isset($data['cursor']) ? (int) $data['cursor'] : null,
            $perPage,
        );

        return ContactRequestResource::collection($page['items'])->additional([
            'meta' => [
                'next_cursor' => $page['next_cursor'],
                'per_page' => $perPage,
            ],
        ]);
    }

    public function show(Request $request, string $profile, string $contactRequest): ContactRequestResource
    {
        return new ContactRequestResource($this->contactRequests->show(
            $this->authenticatedUser($request),
            $profile,
            $contactRequest,
        ));
    }

    public function cancel(Request $request, string $profile, string $contactRequest): ContactRequestResource
    {
        $this->validatePayload($request, [], []);

        return new ContactRequestResource($this->contactRequests->cancel(
            $this->authenticatedUser($request),
            $profile,
            $contactRequest,
        ));
    }

    /** El contrato no define body: cualquier campo (p. ej. un motivo) es 422. */
    public function accept(Request $request, string $profile, string $contactRequest): ContactRequestResource
    {
        $this->validatePayload($request, [], []);

        return new ContactRequestResource($this->contactRequests->accept(
            $this->authenticatedUser($request),
            $profile,
            $contactRequest,
        ));
    }

    public function reject(Request $request, string $profile, string $contactRequest): ContactRequestResource
    {
        $this->validatePayload($request, [], []);

        return new ContactRequestResource($this->contactRequests->reject(
            $this->authenticatedUser($request),
            $profile,
            $contactRequest,
        ));
    }

    /** Datos de contacto: nunca cacheables por intermediarios ni por el navegador. */
    public function contact(Request $request, string $profile, string $contactRequest): JsonResponse
    {
        $this->validatePayload($request, [], []);

        return (new MatchedContactsResource($this->contactRequests->contacts(
            $this->authenticatedUser($request),
            $profile,
            $contactRequest,
        )))->response($request)->header('Cache-Control', 'no-store, private');
    }

    /** Header opcional del contrato: 16–128 caracteres de ^[A-Za-z0-9._:-]+$. */
    private function idempotencyKey(Request $request): ?string
    {
        if (! $request->headers->has('Idempotency-Key')) {
            return null;
        }

        $key = (string) $request->headers->get('Idempotency-Key');
        $length = strlen($key);

        if ($length < 16 || $length > 128 || preg_match('/^[A-Za-z0-9._:-]+$/', $key) !== 1) {
            throw new ApiProblemException(422, 'validation_failed', __('Los datos enviados no son válidos.'), [
                'Idempotency-Key' => [__('La clave debe tener entre 16 y 128 caracteres de [A-Za-z0-9._:-].')],
            ]);
        }

        return $key;
    }

    /** Mismo máximo configurable que la búsqueda (SEARCH_MAX_RANGE_DAYS, ≤ 90). */
    private function assertRangeLength(?string $from, ?string $to): void
    {
        if ($from === null || $to === null) {
            return;
        }

        $maxDays = (int) config('contactur.search.max_range_days');
        if ((int) CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) + 1 > $maxDays) {
            throw new ApiProblemException(422, 'validation_failed', __('Los datos enviados no son válidos.'), [
                'requested_to' => [__('El rango no puede superar :days días.', ['days' => $maxDays])],
            ]);
        }
    }
}
