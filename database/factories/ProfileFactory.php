<?php

namespace Database\Factories;

use App\Enums\ProfileCategory;
use App\Enums\ProfileStatus;
use App\Models\CompanyDetail;
use App\Models\DriverDetail;
use App\Models\GuideDetail;
use App\Models\InstructorDetail;
use App\Models\Profile;
use App\Models\ProfileContact;
use App\Models\TransportDetail;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Profile> */
final class ProfileFactory extends Factory
{
    public function definition(): array
    {
        $publicId = (string) Str::ulid();
        $displayName = fake()->company();

        return [
            'public_id' => $publicId,
            'owner_user_id' => User::factory(),
            'category' => ProfileCategory::Guide,
            'display_name' => $displayName,
            'slug' => Str::slug($displayName).'-'.Str::lower(substr($publicId, -8)),
            'description' => null,
            'primary_city_id' => null,
            'onboarding_step' => 1,
            'completed_at' => null,
            'status' => ProfileStatus::Draft,
            'availability_version' => 0,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Profile $profile): void {
            ProfileContact::query()->firstOrCreate(['profile_id' => $profile->id]);

            match ($profile->category) {
                ProfileCategory::TourismCompany => CompanyDetail::query()->firstOrCreate(
                    ['profile_id' => $profile->id],
                    ['legal_name' => $profile->display_name],
                ),
                ProfileCategory::Guide => GuideDetail::query()->firstOrCreate(['profile_id' => $profile->id]),
                ProfileCategory::Driver => DriverDetail::query()->firstOrCreate(['profile_id' => $profile->id]),
                ProfileCategory::Instructor => InstructorDetail::query()->firstOrCreate(['profile_id' => $profile->id]),
                ProfileCategory::TransportCompany => TransportDetail::query()->firstOrCreate(
                    ['profile_id' => $profile->id],
                    ['legal_name' => $profile->display_name],
                ),
            };
        });
    }

    public function tourismCompany(): static
    {
        return $this->state(['category' => ProfileCategory::TourismCompany]);
    }

    public function guide(): static
    {
        return $this->state(['category' => ProfileCategory::Guide]);
    }

    public function driver(): static
    {
        return $this->state(['category' => ProfileCategory::Driver]);
    }

    public function instructor(): static
    {
        return $this->state(['category' => ProfileCategory::Instructor]);
    }

    public function transportCompany(): static
    {
        return $this->state(['category' => ProfileCategory::TransportCompany]);
    }
}
