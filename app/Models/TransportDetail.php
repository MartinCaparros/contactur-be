<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** @property int $profile_id @property string $legal_name @property string|null $fleet_description */
#[Fillable(['profile_id', 'legal_name', 'fleet_description'])]
final class TransportDetail extends Model
{
    protected $primaryKey = 'profile_id';

    public $incrementing = false;
}
