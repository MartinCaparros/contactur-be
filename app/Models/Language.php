<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 */
#[Fillable(['code', 'name'])]
final class Language extends Model {}
