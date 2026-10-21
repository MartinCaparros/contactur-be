<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** @property int $profile_id @property string|null $education_summary */
#[Fillable(['profile_id', 'education_summary'])]
final class GuideDetail extends Model
{
    protected $primaryKey = 'profile_id';

    public $incrementing = false;
}
