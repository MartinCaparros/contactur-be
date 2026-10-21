<?php

namespace App\Http\Resources;

use App\Data\AccessEvaluation;
use App\Models\Profile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;

/** @mixin Profile */
final class OwnedProfileSummaryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $evaluation = $this->resource->getRelation('accessEvaluation');
        if (! $evaluation instanceof AccessEvaluation) {
            throw new LogicException('Owned profile summary requires an access evaluation.');
        }

        return [
            'id' => $this->public_id,
            'category' => $this->category->value,
            'display_name' => $this->display_name,
            'status' => $this->status->value,
            'completed_at' => $this->completed_at?->utc()->toISOString(),
            'access_state' => $evaluation->effectiveState,
            'capabilities' => $evaluation->capabilities,
        ];
    }
}
