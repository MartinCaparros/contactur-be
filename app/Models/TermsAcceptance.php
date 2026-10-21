<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $user_id
 * @property int $profile_id
 * @property string $version
 * @property CarbonImmutable $accepted_at
 */
#[Fillable(['user_id', 'profile_id', 'version', 'accepted_at'])]
final class TermsAcceptance extends Model
{
    public const UPDATED_AT = null;

    public const CREATED_AT = null;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['accepted_at' => 'immutable_datetime'];
    }
}
