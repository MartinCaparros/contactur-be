<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** @property int $profile_id @property string|null $training_summary */
#[Fillable(['profile_id', 'training_summary'])]
final class InstructorDetail extends Model
{
    protected $primaryKey = 'profile_id';

    public $incrementing = false;
}
