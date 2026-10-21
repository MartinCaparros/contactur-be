<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $profile_id
 * @property string $title
 * @property string $institution
 * @property CarbonImmutable $completed_on
 * @property CarbonImmutable|null $expires_on
 */
#[Fillable(['profile_id', 'title', 'institution', 'completed_on', 'expires_on'])]
final class Qualification extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'completed_on' => 'immutable_date',
            'expires_on' => 'immutable_date',
        ];
    }
}
