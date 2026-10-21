<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** @property int $id @property int $profile_id @property string $vehicle_type @property int $quantity @property int $capacity */
#[Fillable(['profile_id', 'vehicle_type', 'quantity', 'capacity'])]
final class FleetVehicle extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['quantity' => 'integer', 'capacity' => 'integer'];
    }
}
