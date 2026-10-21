<?php

namespace App\Http\Controllers;

use App\Http\Resources\DocumentResource;
use App\Models\DocumentType;
use App\Models\ProfileDocument;
use App\Services\DocumentService;
use App\Support\UploadInspector;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DocumentController extends ApiController
{
    public function __construct(
        private readonly DocumentService $documents,
        private readonly UploadInspector $uploads,
    ) {}

    public function index(Request $request, string $profile): AnonymousResourceCollection
    {
        $data = $this->validatePayload($request, [
            'status' => ['sometimes', 'string', Rule::in([
                ProfileDocument::STATUS_PENDING,
                ProfileDocument::STATUS_APPROVED,
                ProfileDocument::STATUS_REJECTED,
            ])],
            'cursor' => ['sometimes', 'string', 'regex:/^[0-9]+$/', 'max:20'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ], ['status', 'cursor', 'per_page']);
        $perPage = isset($data['per_page']) ? (int) $data['per_page'] : 20;
        $result = $this->documents->list(
            actor: $this->authenticatedUser($request),
            profilePublicId: $profile,
            status: isset($data['status']) ? (string) $data['status'] : null,
            cursor: isset($data['cursor']) ? (int) $data['cursor'] : null,
            perPage: $perPage,
        );

        return DocumentResource::collection($result['items'])->additional([
            'meta' => [
                'next_cursor' => $result['next_cursor'],
                'per_page' => $perPage,
                'total' => $result['total'],
            ],
        ]);
    }

    public function store(Request $request, string $profile): JsonResponse
    {
        $actor = $this->authenticatedUser($request);
        $ownedProfile = $this->documents->ownedProfile($actor, $profile);
        $rawTypeId = filter_var($request->input('document_type_id'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $type = is_int($rawTypeId) ? $this->documents->findType($ownedProfile, $rawTypeId) : null;
        $data = $this->validatePayload($request, [
            'document_type_id' => [
                'required',
                'integer',
                'min:1',
                Rule::exists('document_types', 'id')->where('category', $ownedProfile->category->value),
            ],
            'file' => ['required', 'file', 'max:'.$this->maxKilobytes()],
            'registration_number' => ['sometimes', 'nullable', 'string', 'max:160'],
            'valid_until' => [
                $type?->requires_expiration === true ? 'required' : 'sometimes',
                'nullable',
                'date_format:Y-m-d',
                'after_or_equal:today',
            ],
            'replaces_document_id' => ['sometimes', 'nullable', 'string', 'ulid'],
        ], ['document_type_id', 'file', 'registration_number', 'valid_until', 'replaces_document_id']);

        $file = $data['file'];
        assert($file instanceof UploadedFile);
        $type = $type ?? $this->documents->findType($ownedProfile, (int) $data['document_type_id']);
        assert($type instanceof DocumentType);
        $upload = $this->uploads->inspect($file, UploadInspector::DOCUMENT);
        $document = $this->documents->upload(
            actor: $actor,
            profile: $ownedProfile,
            type: $type,
            upload: $upload,
            registrationNumber: isset($data['registration_number']) ? (string) $data['registration_number'] : null,
            validUntil: isset($data['valid_until'])
                ? CarbonImmutable::createFromFormat('!Y-m-d', (string) $data['valid_until'], 'UTC')
                : null,
            replacesDocumentId: isset($data['replaces_document_id']) ? (string) $data['replaces_document_id'] : null,
            requestId: (string) $request->attributes->get('request_id'),
        );

        return (new DocumentResource($document))->response()->setStatusCode(201);
    }

    public function download(Request $request, string $profile, string $document): StreamedResponse
    {
        $this->validatePayload($request, [], []);
        $download = $this->documents->download($this->authenticatedUser($request), $profile, $document);
        $fallback = preg_replace('/[^A-Za-z0-9._-]+/', '_', Str::ascii($download->filename)) ?: 'document';
        $disposition = HeaderUtils::makeDisposition('attachment', $download->filename, $fallback);

        return response()->stream(function () use ($download): void {
            try {
                fpassthru($download->stream);
            } finally {
                fclose($download->stream);
            }
        }, 200, [
            'Content-Type' => $download->mime,
            'Content-Length' => (string) $download->size,
            'Content-Disposition' => $disposition,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroy(Request $request, string $profile, string $document): JsonResponse
    {
        $this->validatePayload($request, [], []);
        $this->documents->delete(
            $this->authenticatedUser($request),
            $profile,
            $document,
            (string) $request->attributes->get('request_id'),
        );

        return response()->json(status: 204);
    }

    private function maxKilobytes(): int
    {
        return (int) ceil(max(1, (int) config('contactur.uploads.documents_max_bytes')) / 1024);
    }
}
