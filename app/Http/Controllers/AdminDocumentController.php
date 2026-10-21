<?php

namespace App\Http\Controllers;

use App\Enums\ProfileCategory;
use App\Http\Resources\AdminDocumentResource;
use App\Http\Resources\DocumentResource;
use App\Models\ProfileDocument;
use App\Services\DocumentService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

final class AdminDocumentController extends ApiController
{
    public function __construct(private readonly DocumentService $documents) {}

    /**
     * `status` admite solo los tres valores revisables del contrato: superseded y
     * retired son estados internos de revisión y no se exponen como filtro. Sin
     * `status` la cola sigue devolviendo únicamente `pending`.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $this->validatePayload($request, [
            'status' => ['sometimes', 'string', Rule::in([
                ProfileDocument::STATUS_PENDING,
                ProfileDocument::STATUS_APPROVED,
                ProfileDocument::STATUS_REJECTED,
            ])],
            'category' => ['sometimes', 'string', Rule::enum(ProfileCategory::class)],
            'document_type_id' => ['sometimes', 'integer', 'min:1'],
            'cursor' => ['sometimes', 'string', 'regex:/^[0-9]+$/', 'max:20'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ], ['status', 'category', 'document_type_id', 'cursor', 'per_page']);
        $perPage = isset($data['per_page']) ? (int) $data['per_page'] : 20;
        $result = $this->documents->adminQueue(
            actor: $this->authenticatedUser($request),
            status: isset($data['status']) ? (string) $data['status'] : null,
            category: isset($data['category']) ? (string) $data['category'] : null,
            documentTypeId: isset($data['document_type_id']) ? (int) $data['document_type_id'] : null,
            cursor: isset($data['cursor']) ? (int) $data['cursor'] : null,
            perPage: $perPage,
        );

        return AdminDocumentResource::collection($result['items'])->additional([
            'meta' => [
                'next_cursor' => $result['next_cursor'],
                'per_page' => $perPage,
                'total' => $result['total'],
            ],
        ]);
    }

    public function review(Request $request, string $document): DocumentResource
    {
        $data = $this->validatePayload($request, [
            'action' => ['required', 'string', Rule::in(['approve', 'reject'])],
            'rejection_reason' => [
                'required_if:action,reject',
                'prohibited_if:action,approve',
                'nullable',
                'string',
                'min:3',
                'max:1000',
            ],
        ], ['action', 'rejection_reason']);

        return new DocumentResource($this->documents->review(
            $this->authenticatedUser($request),
            $document,
            (string) $data['action'],
            array_key_exists('rejection_reason', $data) && $data['rejection_reason'] !== null
                ? (string) $data['rejection_reason']
                : null,
            (string) $request->attributes->get('request_id'),
        ));
    }
}
