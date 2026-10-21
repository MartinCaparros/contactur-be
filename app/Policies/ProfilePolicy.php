<?php

namespace App\Policies;

use App\Models\Profile;
use App\Models\User;

final class ProfilePolicy
{
    public function view(User $actor, Profile $profile): bool
    {
        return $profile->owner_user_id === $actor->id;
    }

    public function update(User $actor, Profile $profile): bool
    {
        return $this->view($actor, $profile);
    }
}
