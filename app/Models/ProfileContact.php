<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** @property int $profile_id @property string|null $contact_email @property string|null $phone */
#[Fillable(['profile_id', 'contact_email', 'phone'])]
final class ProfileContact extends Model
{
    protected $primaryKey = 'profile_id';

    public $incrementing = false;
}
