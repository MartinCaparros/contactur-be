<?php

namespace App\Repositories;

use App\Models\AccessGrant;
use App\Models\Profile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class AccessGrantRepository
{
    public function findForProfile(Profile $profile): ?AccessGrant
    {
        if ($profile->relationLoaded('accessGrant')) {
            $grant = $profile->getRelation('accessGrant');

            return $grant instanceof AccessGrant ? $grant : null;
        }

        return AccessGrant::query()->where('profile_id', $profile->id)->first();
    }

    public function lockForProfile(Profile $profile): ?AccessGrant
    {
        return AccessGrant::query()
            ->where('profile_id', $profile->id)
            ->lockForUpdate()
            ->first();
    }

    /** @param array<string, mixed> $attributes */
    public function create(Profile $profile, array $attributes): AccessGrant
    {
        return AccessGrant::query()->create(['profile_id' => $profile->id, ...$attributes]);
    }

    /** @param array<string, mixed> $attributes */
    public function update(AccessGrant $grant, array $attributes): AccessGrant
    {
        $grant->fill($attributes);
        $grant->save();

        return $grant->refresh();
    }

    public function contactUsage(Profile $profile, CarbonImmutable $monthStart): int
    {
        if (! Schema::hasTable('contact_usage')) {
            return 0;
        }

        return (int) (DB::table('contact_usage')
            ->where('profile_id', $profile->id)
            ->whereDate('month_start', $monthStart->toDateString())
            ->value('used') ?? 0);
    }
}
