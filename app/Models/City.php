<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** @property int $id @property string $name @property string $province_code @property string $country_code @property string $timezone */
#[Fillable(['name', 'province_code', 'country_code', 'timezone'])]
final class City extends Model {}
