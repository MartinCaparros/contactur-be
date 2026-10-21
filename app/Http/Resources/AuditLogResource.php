<?php

namespace App\Http\Resources;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AuditLog */
final class AuditLogResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'actor_user_id' => $this->actor->public_id,
            'target_type' => $this->target_type,
            'target_id' => $this->target_id,
            'action' => $this->action,
            'reason' => $this->reason,
            'safe_changes' => $this->safe_changes,
            'request_id' => $this->request_id,
            'created_at' => $this->created_at->utc()->toISOString(),
        ];
    }
}
