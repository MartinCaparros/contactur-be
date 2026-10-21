<?php

namespace App\Services;

use App\Models\Profile;
use App\Models\User;
use App\Repositories\ProfileRepository;
use App\Repositories\UserRepository;

final class AccountService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly ProfileRepository $profiles,
        private readonly AccessService $access,
    ) {}

    public function current(User $user): User
    {
        $profiles = $this->profiles->ownedSummaries($user);
        $profiles->each(function (Profile $profile) use ($user): void {
            $profile->setRelation('accessEvaluation', $this->access->evaluate($user, $profile, null));
        });
        $user->setRelation('profiles', $profiles);

        return $user;
    }

    /** @param array<string, mixed> $attributes */
    public function update(User $user, array $attributes): User
    {
        $updated = $this->users->update($user, $attributes);

        return $this->current($updated);
    }
}
