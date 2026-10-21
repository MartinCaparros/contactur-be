<?php

namespace App\Models;

use App\Enums\ProfileCategory;
use App\Enums\ProfileStatus;
use Carbon\CarbonImmutable;
use Database\Factories\ProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $public_id
 * @property int $owner_user_id
 * @property ProfileCategory $category
 * @property string $display_name
 * @property string $slug
 * @property string|null $description
 * @property int|null $primary_city_id
 * @property int $onboarding_step
 * @property CarbonImmutable|null $completed_at
 * @property ProfileStatus $status
 * @property-read ProfileContact|null $contact
 * @property-read CompanyDetail|null $companyDetails
 * @property-read GuideDetail|null $guideDetails
 * @property-read DriverDetail|null $driverDetails
 * @property-read InstructorDetail|null $instructorDetails
 * @property-read TransportDetail|null $transportDetails
 * @property-read City|null $primaryCity
 * @property-read Collection<int, City> $cities
 * @property-read Collection<int, Language> $languages
 * @property-read Collection<int, Service> $services
 * @property-read Collection<int, Specialty> $specialties
 * @property-read Collection<int, Qualification> $qualifications
 * @property-read Collection<int, FleetVehicle> $fleetVehicles
 */
#[Fillable([
    'public_id',
    'owner_user_id',
    'category',
    'display_name',
    'slug',
    'description',
    'primary_city_id',
    'avatar_file_id',
    'onboarding_step',
    'completed_at',
    'status',
    'availability_version',
])]
#[Hidden(['id', 'owner_user_id', 'primary_city_id', 'avatar_file_id'])]
final class Profile extends Model
{
    /** @use HasFactory<ProfileFactory> */
    use HasFactory, HasUlids;

    public function newUniqueId(): string
    {
        return (string) Str::ulid();
    }

    /** @return array<int, string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /** @return HasOne<AccessGrant, $this> */
    public function accessGrant(): HasOne
    {
        return $this->hasOne(AccessGrant::class);
    }

    /** @return HasOne<ProfileContact, $this> */
    public function contact(): HasOne
    {
        return $this->hasOne(ProfileContact::class);
    }

    /** @return HasOne<CompanyDetail, $this> */
    public function companyDetails(): HasOne
    {
        return $this->hasOne(CompanyDetail::class);
    }

    /** @return HasOne<GuideDetail, $this> */
    public function guideDetails(): HasOne
    {
        return $this->hasOne(GuideDetail::class);
    }

    /** @return HasOne<DriverDetail, $this> */
    public function driverDetails(): HasOne
    {
        return $this->hasOne(DriverDetail::class);
    }

    /** @return HasOne<InstructorDetail, $this> */
    public function instructorDetails(): HasOne
    {
        return $this->hasOne(InstructorDetail::class);
    }

    /** @return HasOne<TransportDetail, $this> */
    public function transportDetails(): HasOne
    {
        return $this->hasOne(TransportDetail::class);
    }

    /** @return BelongsTo<City, $this> */
    public function primaryCity(): BelongsTo
    {
        return $this->belongsTo(City::class, 'primary_city_id');
    }

    /** @return BelongsToMany<City, $this> */
    public function cities(): BelongsToMany
    {
        return $this->belongsToMany(City::class, 'profile_cities')->orderBy('cities.id');
    }

    /** @return BelongsToMany<Language, $this> */
    public function languages(): BelongsToMany
    {
        return $this->belongsToMany(Language::class, 'profile_language')->orderBy('languages.id');
    }

    /** @return BelongsToMany<Service, $this> */
    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'profile_service')->orderBy('services.id');
    }

    /** @return BelongsToMany<Specialty, $this> */
    public function specialties(): BelongsToMany
    {
        return $this->belongsToMany(Specialty::class, 'profile_specialty')->orderBy('specialties.id');
    }

    /** @return HasMany<Qualification, $this> */
    public function qualifications(): HasMany
    {
        return $this->hasMany(Qualification::class)->orderBy('id');
    }

    /** @return HasMany<FleetVehicle, $this> */
    public function fleetVehicles(): HasMany
    {
        return $this->hasMany(FleetVehicle::class)->orderBy('id');
    }

    /** @return BelongsTo<File, $this> */
    public function avatarFile(): BelongsTo
    {
        return $this->belongsTo(File::class, 'avatar_file_id');
    }

    /** @return HasMany<ProfileGallery, $this> */
    public function gallery(): HasMany
    {
        return $this->hasMany(ProfileGallery::class)->orderBy('position');
    }

    /** @return HasMany<ProfileDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(ProfileDocument::class)->orderBy('id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'owner_user_id' => 'integer',
            'category' => ProfileCategory::class,
            'primary_city_id' => 'integer',
            'onboarding_step' => 'integer',
            'completed_at' => 'immutable_datetime',
            'status' => ProfileStatus::class,
            'availability_version' => 'integer',
            'verified' => 'boolean',
        ];
    }
}
