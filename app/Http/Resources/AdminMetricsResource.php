<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * El contrato declara los cuatro contadores como `type: object`. Un array PHP vacío
 * se serializa como `[]`, que sería un array JSON y no un objeto, así que los mapas
 * se castean a stdClass: con datos el JSON es idéntico y sin datos sale `{}`.
 */
final class AdminMetricsResource extends JsonResource
{
    /**
     * @param  array{
     *     users: array<string, int>,
     *     profiles_by_category: array<string, int>,
     *     profiles_by_access_state: array<string, int>,
     *     documents_pending: int,
     *     contact_requests_by_status: array<string, int>,
     *     support_inquiries_pending: int
     * }  $resource
     */
    public function __construct(array $resource)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'users' => (object) $this->resource['users'],
            'profiles_by_category' => (object) $this->resource['profiles_by_category'],
            'profiles_by_access_state' => (object) $this->resource['profiles_by_access_state'],
            'documents_pending' => $this->resource['documents_pending'],
            'contact_requests_by_status' => (object) $this->resource['contact_requests_by_status'],
            'support_inquiries_pending' => $this->resource['support_inquiries_pending'],
        ];
    }
}
