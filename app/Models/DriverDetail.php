<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** @property int $profile_id @property string|null $license_type @property string|null $vehicle_type @property int|null $vehicle_capacity */
#[Fillable(['profile_id', 'license_type', 'vehicle_type', 'vehicle_capacity'])]
final class DriverDetail extends Model
{
    protected $primaryKey = 'profile_id';

    public $incrementing = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['vehicle_capacity' => 'integer'];
    }
}
