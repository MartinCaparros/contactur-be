<?php

namespace App\Http\Resources;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class AuditLogsResource extends JsonResource
{
    /**
     * @param  array{items: Collection<int, AuditLog>, next_cursor: string|null, total: int, per_page: int}  $resource
     */
    public function __construct(array $resource)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'data' => $this->resource['items']
                ->map(fn (AuditLog $log): array => (new AuditLogResource($log))->resolve($request))
                ->all(),
            'meta' => [
                'next_cursor' => $this->resource['next_cursor'],
                'per_page' => $this->resource['per_page'],
                'total' => $this->resource['total'],
            ],
        ];
    }

    public static $wrap = null;
}
