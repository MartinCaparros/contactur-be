<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** @property int $profile_id @property string $legal_name @property int|null $years_active */
#[Fillable(['profile_id', 'legal_name', 'years_active'])]
final class CompanyDetail extends Model
{
    protected $primaryKey = 'profile_id';

    public $incrementing = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['years_active' => 'integer'];
    }
}
