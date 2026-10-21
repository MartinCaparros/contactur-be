<?php

namespace App\Repositories;

use App\Data\DirectorySearchFilters;
use App\Enums\AccessEffectiveState;
use App\Enums\ProfileCategory;
use App\Enums\ProfileStatus;
use App\Models\AvailabilityDay;
use App\Models\CompanyDetail;
use App\Models\DocumentType;
use App\Models\DriverDetail;
use App\Models\GuideDetail;
use App\Models\InstructorDetail;
use App\Models\Profile;
use App\Models\ProfileContact;
use App\Models\ProfileDocument;
use App\Models\TermsAcceptance;
use App\Models\TransportDetail;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ProfileRepository
{
    private const RESPONSE_RELATIONS = [
        'contact',
        'primaryCity',
        'cities',
        'languages',
        'services',
        'specialties',
        'companyDetails',
        'guideDetails',
        'driverDetails',
        'instructorDetails',
        'transportDetails',
        'qualifications',
        'fleetVehicles',
        'avatarFile',
        'gallery.file',
        'documents.documentType',
        'documents.file',
    ];

    /**
     * El backoffice no serializa documentos, así que se omiten sus relaciones y se
     * agregan grant y owner (con profiles_count) para evitar N+1 en AdminProfile.
     */
    private const ADMIN_RELATIONS = [
        'contact',
        'primaryCity',
        'cities',
        'languages',
        'services',
        'specialties',
        'companyDetails',
        'guideDetails',
        'driverDetails',
        'instructorDetails',
        'transportDetails',
        'qualifications',
        'fleetVehicles',
        'avatarFile',
        'gallery.file',
        'accessGrant',
    ];

    /**
     * Relaciones de SafeProfile para búsqueda y ficha. Nunca incluye `contact`
     * (R12/P18): el contacto no se carga en el directorio. companyDetails queda
     * afuera porque los destinos del directorio son siempre receptores.
     */
    private const DIRECTORY_CARD_RELATIONS = [
        'primaryCity',
        'cities',
        'languages',
        'services',
        'guideDetails',
        'driverDetails',
        'instructorDetails',
        'transportDetails',
        'avatarFile',
        'gallery.file',
    ];

    /**
     * Espejo SQL de AccessService::effectiveState con el owner como actor: misma
     * precedencia cuenta → perfil → grant. Solo se usa para filtrar; el estado que
     * viaja en la respuesta siempre sale de la evaluación real del servicio.
     */
    private const EFFECTIVE_STATE_SQL = <<<'SQL'
        (CASE
            WHEN owners.status = 'suspended' THEN 'suspended'
            WHEN profiles.status = 'suspended' THEN 'suspended'
            WHEN access_grants.profile_id IS NULL OR access_grants.status = 'inactive' THEN 'inactive'
            WHEN access_grants.status = 'suspended' THEN 'suspended'
            WHEN access_grants.starts_at IS NOT NULL AND access_grants.starts_at > ? THEN 'inactive'
            WHEN access_grants.expires_at IS NOT NULL AND access_grants.expires_at <= ? THEN 'expired'
            ELSE 'active'
        END)
        SQL;

    /** Carácter de escape explícito para LIKE; neutraliza %, _ y el propio escape. */
    private const LIKE_ESCAPE = '!';

    public function findByPublicId(string $publicId): ?Profile
    {
        return Profile::query()->where('public_id', $publicId)->first();
    }

    public function lockById(int $id): Profile
    {
        return Profile::query()->lockForUpdate()->findOrFail($id);
    }

    public function create(User $owner, ProfileCategory $category, string $displayName): Profile
    {
        $publicId = (string) Str::ulid();
        $profile = Profile::query()->create([
            'public_id' => $publicId,
            'owner_user_id' => $owner->id,
            'category' => $category,
            'display_name' => trim($displayName),
            'slug' => Str::slug($displayName).'-'.Str::lower(substr($publicId, -8)),
            'status' => ProfileStatus::Draft,
            'onboarding_step' => 1,
        ]);

        ProfileContact::query()->create(['profile_id' => $profile->id]);

        match ($category) {
            ProfileCategory::TourismCompany => CompanyDetail::query()->create([
                'profile_id' => $profile->id,
                'legal_name' => trim($displayName),
            ]),
            ProfileCategory::Guide => GuideDetail::query()->create(['profile_id' => $profile->id]),
            ProfileCategory::Driver => DriverDetail::query()->create(['profile_id' => $profile->id]),
            ProfileCategory::Instructor => InstructorDetail::query()->create(['profile_id' => $profile->id]),
            ProfileCategory::TransportCompany => TransportDetail::query()->create([
                'profile_id' => $profile->id,
                'legal_name' => trim($displayName),
            ]),
        };

        return $profile;
    }

    public function loadForOwnerResponse(Profile $profile, CarbonImmutable $today): Profile
    {
        $profile->load(self::RESPONSE_RELATIONS);
        $profile->setAttribute('verified', $this->missingRequiredDocuments(
            $profile,
            [ProfileDocument::STATUS_APPROVED],
            $today,
        ) === []);
        $profile->syncOriginalAttribute('verified');

        return $profile;
    }

    /** @return Collection<int, Profile> */
    public function ownedSummaries(User $owner): Collection
    {
        return Profile::query()
            ->where('owner_user_id', $owner->id)
            ->with('accessGrant')
            ->orderBy('id')
            ->get();
    }

    /** @param array<string, mixed> $attributes */
    public function updateBase(Profile $profile, array $attributes): void
    {
        $profile->fill($attributes);
        $profile->save();
    }

    /** @param array<string, mixed> $attributes */
    public function updateContact(Profile $profile, array $attributes): void
    {
        ProfileContact::query()->updateOrCreate(['profile_id' => $profile->id], $attributes);
    }

    /** @param array<string, mixed> $attributes */
    public function updateCategoryDetails(Profile $profile, array $attributes): void
    {
        $model = match ($profile->category) {
            ProfileCategory::TourismCompany => CompanyDetail::query(),
            ProfileCategory::Guide => GuideDetail::query(),
            ProfileCategory::Driver => DriverDetail::query(),
            ProfileCategory::Instructor => InstructorDetail::query(),
            ProfileCategory::TransportCompany => TransportDetail::query(),
        };
        $model->updateOrCreate(['profile_id' => $profile->id], $attributes);
    }

    /** @param array<int, int> $ids */
    public function syncLanguages(Profile $profile, array $ids): void
    {
        $profile->languages()->sync($ids);
    }

    /** @param array<int, int> $ids */
    public function syncServices(Profile $profile, array $ids): void
    {
        $profile->services()->sync($ids);
    }

    /** @param array<int, int> $ids */
    public function syncSpecialties(Profile $profile, array $ids): void
    {
        $profile->specialties()->sync($ids);
    }

    /** @param array<int, array<string, mixed>> $items */
    public function replaceQualifications(Profile $profile, array $items): void
    {
        $profile->qualifications()->delete();
        $profile->qualifications()->createMany($items);
    }

    /** @param array<int, array<string, mixed>> $items */
    public function replaceFleetVehicles(Profile $profile, array $items): void
    {
        $profile->fleetVehicles()->delete();
        $profile->fleetVehicles()->createMany($items);
    }

    /** @param array<int, int> $cityIds */
    public function replaceCities(Profile $profile, array $cityIds): void
    {
        $profile->cities()->sync($cityIds);
    }

    /** @return array<int, int> */
    public function cityIds(Profile $profile): array
    {
        return $profile->cities()->pluck('cities.id')->map(static fn (mixed $id): int => (int) $id)->all();
    }

    public function updatePrimaryCity(Profile $profile, ?int $cityId): void
    {
        $profile->forceFill(['primary_city_id' => $cityId])->save();
    }

    /**
     * Bloquea (borra) la disponibilidad estrictamente futura de las ciudades
     * quitadas. "Futura" usa la fecha local de negocio, igual que el calendario:
     * hoy y el pasado no son editables (D12) y se conservan como historial.
     *
     * Si borra filas incrementa availability_version una sola vez, para que un
     * PUT de calendario con la versión previa reciba 409 en vez de reescribir
     * sobre un estado que ya no vio. El caller debe tener el perfil bloqueado.
     *
     * Devuelve las fechas borradas (Y-m-d) para que el service emita la
     * invalidación con los meses afectados; el lock del perfil que tiene el
     * caller garantiza que la lectura previa y el DELETE ven las mismas filas.
     *
     * @param  array<int, int>  $cityIds
     * @return array<int, string> fechas borradas
     */
    public function blockFutureAvailability(Profile $profile, array $cityIds, CarbonImmutable $now): array
    {
        if ($cityIds === []) {
            return [];
        }

        $query = AvailabilityDay::query()
            ->where('profile_id', $profile->id)
            ->whereIn('city_id', $cityIds)
            ->where('date', '>', $this->businessDate($now));
        $dates = (clone $query)
            ->pluck('date')
            ->map(static fn (mixed $date): string => substr((string) $date, 0, 10))
            ->all();

        if ($dates === []) {
            return [];
        }

        $query->delete();
        $this->incrementAvailabilityVersion($profile);

        return array_values($dates);
    }

    /** @param array<int, int> $cityIds */
    public function hasFutureAvailability(Profile $profile, array $cityIds, CarbonImmutable $now): bool
    {
        return $cityIds !== [] && AvailabilityDay::query()
            ->where('profile_id', $profile->id)
            ->whereIn('city_id', $cityIds)
            ->where('date', '>', $this->businessDate($now))
            ->exists();
    }

    /**
     * Incremento atómico en SQL (sin tocar updated_at del perfil) y sincronizado
     * en el modelo recibido para que la respuesta refleje la versión nueva.
     */
    public function incrementAvailabilityVersion(Profile $profile): int
    {
        DB::table('profiles')->where('id', $profile->id)->increment('availability_version');
        $version = (int) DB::table('profiles')->where('id', $profile->id)->value('availability_version');
        $profile->setAttribute('availability_version', $version);
        $profile->syncOriginalAttribute('availability_version');

        return $version;
    }

    private function businessDate(CarbonImmutable $now): string
    {
        return $now->setTimezone(AvailabilityDay::BUSINESS_TIMEZONE)->toDateString();
    }

    public function hasCity(Profile $profile, int $cityId): bool
    {
        return $profile->cities()->whereKey($cityId)->exists();
    }

    public function createTermsAcceptance(
        User $actor,
        Profile $profile,
        string $version,
        CarbonImmutable $acceptedAt,
    ): TermsAcceptance {
        return TermsAcceptance::query()->create([
            'user_id' => $actor->id,
            'profile_id' => $profile->id,
            'version' => $version,
            'accepted_at' => $acceptedAt,
        ]);
    }

    public function termsAcceptance(User $actor, Profile $profile, string $version): ?TermsAcceptance
    {
        return TermsAcceptance::query()
            ->where('user_id', $actor->id)
            ->where('profile_id', $profile->id)
            ->where('version', $version)
            ->first();
    }

    /**
     * @param  array<int, string>  $acceptedStatuses
     * @return array<string, string>
     */
    public function missingRequiredDocuments(
        Profile $profile,
        array $acceptedStatuses,
        CarbonImmutable $today,
    ): array {
        return DocumentType::query()
            ->where('category', $profile->category->value)
            ->where('required', true)
            ->whereNotExists(function ($query) use ($profile, $acceptedStatuses, $today): void {
                $query->selectRaw('1')
                    ->from('profile_documents as candidate_documents')
                    ->join('files as candidate_files', 'candidate_files.id', '=', 'candidate_documents.file_id')
                    ->whereColumn('candidate_documents.document_type_id', 'document_types.id')
                    ->where('candidate_documents.profile_id', $profile->id)
                    ->where('candidate_files.owner_profile_id', $profile->id)
                    ->where('candidate_files.uploader_user_id', $profile->owner_user_id)
                    ->where('candidate_files.kind', 'document')
                    ->where('candidate_files.size', '>', 0)
                    ->whereNotNull('candidate_files.checksum')
                    ->whereNull('candidate_files.storage_deleted_at')
                    ->whereIn('candidate_documents.status', $acceptedStatuses)
                    ->whereNotIn('candidate_documents.status', [
                        ProfileDocument::STATUS_SUPERSEDED,
                        ProfileDocument::STATUS_RETIRED,
                    ])
                    ->whereRaw('(candidate_documents.valid_until IS NULL OR candidate_documents.valid_until >= ?)', [$today->toDateString()])
                    ->whereRaw('(NOT document_types.requires_expiration OR candidate_documents.valid_until IS NOT NULL)');
            })
            ->orderBy('id')
            ->pluck('label', 'code')
            ->all();
    }

    public function markCompleted(Profile $profile, CarbonImmutable $completedAt): void
    {
        $attributes = ['onboarding_step' => 4];

        if ($profile->completed_at === null) {
            $attributes['completed_at'] = $completedAt;
            $attributes['status'] = ProfileStatus::Published;
        }

        $profile->forceFill($attributes)->save();
    }

    public function advanceOnboarding(Profile $profile, int $step): void
    {
        if ($profile->onboarding_step < $step) {
            $profile->forceFill(['onboarding_step' => $step])->save();
        }
    }

    /**
     * profiles.status solo se escribe desde el flujo de onboarding del titular o
     * desde el backoffice; forceFill mantiene la escritura fuera del payload HTTP.
     */
    public function updateStatus(Profile $profile, ProfileStatus $status): void
    {
        $profile->forceFill(['status' => $status])->save();
    }

    public function loadForAdminResponse(Profile $profile, CarbonImmutable $today): Profile
    {
        $profile->load([
            ...self::ADMIN_RELATIONS,
            'owner' => static fn ($query) => $query->withCount('profiles'),
        ]);
        $profile->setAttribute('verified', $this->missingRequiredDocuments(
            $profile,
            [ProfileDocument::STATUS_APPROVED],
            $today,
        ) === []);
        $profile->syncOriginalAttribute('verified');

        return $profile;
    }

    /**
     * @return array{items: Collection<int, Profile>, next_cursor: string|null, total: int}
     */
    public function listForAdmin(
        ?string $search,
        ?string $category,
        ?string $status,
        ?string $accessState,
        ?int $cursor,
        int $perPage,
        CarbonImmutable $now,
    ): array {
        $base = Profile::query()
            ->select('profiles.*')
            ->join('users as owners', 'owners.id', '=', 'profiles.owner_user_id')
            ->leftJoin('access_grants', 'access_grants.profile_id', '=', 'profiles.id')
            ->when($category !== null, fn ($query) => $query->where('profiles.category', $category))
            ->when($status !== null, fn ($query) => $query->where('profiles.status', $status))
            ->when($search !== null, function ($query) use ($search): void {
                $pattern = '%'.$this->escapeLike((string) $search).'%';
                $query->where(function ($inner) use ($pattern): void {
                    $inner
                        ->whereRaw("LOWER(profiles.display_name) LIKE ? ESCAPE '".self::LIKE_ESCAPE."'", [$pattern])
                        ->orWhereRaw("LOWER(profiles.slug) LIKE ? ESCAPE '".self::LIKE_ESCAPE."'", [$pattern]);
                });
            })
            ->when($accessState !== null, fn ($query) => $query->whereRaw(
                self::EFFECTIVE_STATE_SQL.' = ?',
                [$now, $now, $accessState],
            ));
        $total = (clone $base)->count();
        $items = $base
            ->when($cursor !== null, fn ($query) => $query->where('profiles.id', '<', $cursor))
            ->with([
                ...self::ADMIN_RELATIONS,
                'owner' => static fn ($query) => $query->withCount('profiles'),
            ])
            ->orderByDesc('profiles.id')
            ->limit($perPage + 1)
            ->get();
        $hasMore = $items->count() > $perPage;
        /** @var Collection<int, Profile> $page */
        $page = $items->take($perPage)->values();
        $verified = $this->verifiedFlags(
            $page->map(static fn (Profile $profile): int => $profile->id)->all(),
            [ProfileDocument::STATUS_APPROVED],
            $now,
        );

        foreach ($page as $profile) {
            $profile->setAttribute('verified', $verified[$profile->id] ?? true);
            $profile->syncOriginalAttribute('verified');
        }

        return [
            'items' => $page,
            'next_cursor' => $hasMore ? (string) $page->last()?->id : null,
            'total' => $total,
        ];
    }

    /**
     * Versión por lote de missingRequiredDocuments: una sola consulta cuenta los
     * tipos requeridos sin respaldo aprobado y vigente para cada perfil del lote.
     *
     * @param  array<int, int>  $profileIds
     * @param  array<int, string>  $acceptedStatuses
     * @return array<int, bool>
     */
    public function verifiedFlags(array $profileIds, array $acceptedStatuses, CarbonImmutable $today): array
    {
        if ($profileIds === []) {
            return [];
        }

        $missing = DB::table('profiles')
            ->join('document_types', function ($join): void {
                $join->on('document_types.category', '=', 'profiles.category')
                    ->where('document_types.required', true);
            })
            ->whereIn('profiles.id', $profileIds)
            ->whereNotExists(function (QueryBuilder $query) use ($acceptedStatuses, $today): void {
                $this->constrainValidRequiredDocument($query, $acceptedStatuses, $today);
            })
            ->groupBy('profiles.id')
            ->selectRaw('profiles.id as profile_id, COUNT(*) as missing_count')
            ->get();
        $missingByProfile = [];

        foreach ($missing as $row) {
            $missingByProfile[(int) $row->profile_id] = (int) $row->missing_count;
        }

        $flags = [];
        foreach ($profileIds as $profileId) {
            $flags[$profileId] = ($missingByProfile[$profileId] ?? 0) === 0;
        }

        return $flags;
    }

    /**
     * Contador agregado por categoría. "Fecha" acá es `profiles.created_at`, la
     * fecha de creación del perfil.
     *
     * @return array<string, int>
     */
    public function countByCategory(?CarbonImmutable $createdFrom, ?CarbonImmutable $createdUntil): array
    {
        $counts = [];
        $rows = DB::table('profiles')
            ->when($createdFrom !== null, fn ($query) => $query->where('profiles.created_at', '>=', $createdFrom))
            ->when($createdUntil !== null, fn ($query) => $query->where('profiles.created_at', '<', $createdUntil))
            ->groupBy('profiles.category')
            ->selectRaw('profiles.category AS category, COUNT(*) AS total')
            ->get();

        foreach ($rows as $row) {
            $counts[(string) $row->category] = (int) $row->total;
        }

        return $counts;
    }

    /**
     * Contador agregado por estado efectivo de acceso, con la misma precedencia
     * cuenta → perfil → grant que AccessService::effectiveState: se reutiliza
     * EFFECTIVE_STATE_SQL, que ya es ese espejo y es lo que usa listForAdmin.
     *
     * El estado se evalúa siempre a $now, no al final del rango: el rango solo
     * decide qué perfiles se cuentan, y "fecha" acá es `profiles.created_at`.
     * Contar un estado histórico exigiría versionar el grant, que no existe.
     *
     * La evaluación queda en una subconsulta en lugar de repetir el CASE en el
     * GROUP BY para no duplicar los bindings de $now ni depender del orden en que
     * el query builder los agrupa.
     *
     * @return array<string, int>
     */
    public function countByEffectiveAccessState(
        CarbonImmutable $now,
        ?CarbonImmutable $createdFrom,
        ?CarbonImmutable $createdUntil,
    ): array {
        $evaluated = DB::table('profiles')
            ->join('users as owners', 'owners.id', '=', 'profiles.owner_user_id')
            ->leftJoin('access_grants', 'access_grants.profile_id', '=', 'profiles.id')
            ->when($createdFrom !== null, fn ($query) => $query->where('profiles.created_at', '>=', $createdFrom))
            ->when($createdUntil !== null, fn ($query) => $query->where('profiles.created_at', '<', $createdUntil))
            ->selectRaw(self::EFFECTIVE_STATE_SQL.' AS effective_state', [$now, $now]);
        $counts = [];
        $rows = DB::query()
            ->fromSub($evaluated, 'evaluated')
            ->groupBy('effective_state')
            ->selectRaw('effective_state, COUNT(*) AS total')
            ->get();

        foreach ($rows as $row) {
            $counts[(string) $row->effective_state] = (int) $row->total;
        }

        return $counts;
    }

    /**
     * Página de búsqueda del directorio (R11/D16), resuelta por completo en SQL:
     * visibilidad, filtros, disponibilidad, badge, orden y keyset. Devuelve hasta
     * $perPage + 1 filas; la extra solo indica que existe otra página.
     *
     * Cada fila trae además `sort_rank` (0 verificado, 1 no), `sort_hash`
     * (md5 de public_id y seed, en collation "C"), `sort_id` (public_id) y
     * `matched_days`. El orden es (sort_rank, sort_hash, sort_id) y `$after` es
     * esa misma tupla de la última fila vista: keyset sin OFFSET.
     *
     * Con rango, `matched_days` es el máximo de días del rango que el perfil tiene
     * habilitados en una misma ciudad (D11); con city_id, solo esa ciudad cuenta.
     *
     * @param  array{int, string, string}|null  $after
     * @return Collection<int, Profile>
     */
    public function searchDirectory(
        Profile $actor,
        DirectorySearchFilters $filters,
        string $seed,
        ?array $after,
        int $perPage,
        CarbonImmutable $now,
    ): Collection {
        $missingRequired = DB::table('document_types')
            ->selectRaw('1')
            ->whereColumn('document_types.category', 'profiles.category')
            ->where('document_types.required', true)
            ->whereNotExists(function (QueryBuilder $query) use ($now): void {
                $this->constrainValidRequiredDocument($query, [ProfileDocument::STATUS_APPROVED], $now);
            });
        $candidates = Profile::query()
            ->select('profiles.*')
            ->selectRaw(
                '(CASE WHEN EXISTS ('.$missingRequired->toSql().') THEN 1 ELSE 0 END) AS sort_rank',
                $missingRequired->getBindings(),
            )
            ->selectRaw(
                '(md5(CAST(profiles.public_id AS text) || CAST(? AS text)) COLLATE "C") AS sort_hash',
                [':'.$seed],
            )
            ->selectRaw('(CAST(profiles.public_id AS text) COLLATE "C") AS sort_id');
        $this->applyDirectoryVisibility($candidates, $actor, $now);

        if ($filters->dateFrom !== null && $filters->dateTo !== null) {
            $perCity = DB::table('availability_days')
                ->whereBetween('availability_days.date', [$filters->dateFrom, $filters->dateTo])
                ->when($filters->cityId !== null, fn ($query) => $query->where('availability_days.city_id', $filters->cityId))
                ->groupBy('availability_days.profile_id', 'availability_days.city_id')
                ->selectRaw('availability_days.profile_id, COUNT(*) AS day_count');
            $matched = DB::query()
                ->fromSub($perCity, 'per_city')
                ->groupBy('per_city.profile_id')
                ->selectRaw('per_city.profile_id, MAX(per_city.day_count) AS matched_days');
            $candidates
                ->addSelect('matched.matched_days')
                ->joinSub($matched, 'matched', 'matched.profile_id', '=', 'profiles.id')
                ->where('matched.matched_days', '>=', $filters->matchMode === DirectorySearchFilters::MATCH_ALL
                    ? $filters->requestedDays()
                    : 1);
        } else {
            // Sin fechas, ciudad significa "opera en esa ciudad" (profile_cities).
            $candidates
                ->selectRaw('0 AS matched_days')
                ->when($filters->cityId !== null, fn ($query) => $query->whereExists(
                    fn (QueryBuilder $cities) => $cities->selectRaw('1')
                        ->from('profile_cities')
                        ->whereColumn('profile_cities.profile_id', 'profiles.id')
                        ->where('profile_cities.city_id', $filters->cityId),
                ));
        }

        $candidates
            ->when($filters->type !== null, fn ($query) => $query->where('profiles.category', $filters->type))
            ->when($filters->name !== null, fn ($query) => $query->whereRaw(
                "LOWER(profiles.display_name) LIKE ? ESCAPE '".self::LIKE_ESCAPE."'",
                ['%'.$this->escapeLike((string) $filters->name).'%'],
            ));

        return Profile::query()
            ->fromSub($candidates, 'profiles')
            ->select('profiles.*')
            ->when($filters->verified !== null, fn ($query) => $query->where('profiles.sort_rank', $filters->verified ? 0 : 1))
            ->when($after !== null, fn ($query) => $query->whereRaw(
                '(profiles.sort_rank, profiles.sort_hash, profiles.sort_id) > (?, ?, ?)',
                $after,
            ))
            ->orderBy('profiles.sort_rank')
            ->orderBy('profiles.sort_hash')
            ->orderBy('profiles.sort_id')
            ->limit($perPage + 1)
            ->with(self::DIRECTORY_CARD_RELATIONS)
            ->get();
    }

    /** Mismo criterio de visibilidad que la búsqueda: un target no elegible no existe. */
    public function findDirectoryTarget(Profile $actor, string $publicId, CarbonImmutable $now): ?Profile
    {
        $query = Profile::query()
            ->select('profiles.*')
            ->where('profiles.public_id', $publicId);
        $this->applyDirectoryVisibility($query, $actor, $now);

        return $query->first();
    }

    /**
     * Carga la ficha de directorio sin contacto: `contact` no se carga nunca y los
     * documentos se limitan a la revisión vigente (pending/approved/rejected) con
     * su archivo solo para evaluar vigencia; nada de eso se serializa.
     */
    public function loadForDirectory(Profile $profile, CarbonImmutable $today): Profile
    {
        $profile->load([
            ...self::DIRECTORY_CARD_RELATIONS,
            'specialties',
            'qualifications',
            'fleetVehicles',
            'documents' => static fn ($query) => $query
                ->whereIn('status', [
                    ProfileDocument::STATUS_PENDING,
                    ProfileDocument::STATUS_APPROVED,
                    ProfileDocument::STATUS_REJECTED,
                ])
                ->with('file'),
        ]);
        $profile->setAttribute('verified', $this->missingRequiredDocuments(
            $profile,
            [ProfileDocument::STATUS_APPROVED],
            $today,
        ) === []);
        $profile->syncOriginalAttribute('verified');

        return $profile;
    }

    /**
     * Visibilidad de directorio (R11/D19) en SQL: categoría receptora, publicado
     * y completo, cuenta dueña activa y estado efectivo `active` a $now con la
     * misma precedencia cuenta → perfil → grant que AccessService. El actor nunca
     * se ve a sí mismo.
     *
     * @param  Builder<Profile>  $query
     */
    private function applyDirectoryVisibility(Builder $query, Profile $actor, CarbonImmutable $now): void
    {
        $query
            ->join('users as owners', 'owners.id', '=', 'profiles.owner_user_id')
            ->leftJoin('access_grants', 'access_grants.profile_id', '=', 'profiles.id')
            ->whereIn('profiles.category', array_map(
                static fn (ProfileCategory $category): string => $category->value,
                array_filter(ProfileCategory::cases(), static fn (ProfileCategory $category): bool => $category->isReceiver()),
            ))
            ->where('profiles.status', ProfileStatus::Published->value)
            ->whereNotNull('profiles.completed_at')
            ->where('owners.status', User::STATUS_ACTIVE)
            ->where('profiles.id', '<>', $actor->id)
            ->whereRaw(self::EFFECTIVE_STATE_SQL.' = ?', [$now, $now, AccessEffectiveState::Active->value]);
    }

    /**
     * Documento que respalda un tipo requerido: aprobado (o el estado aceptado),
     * con archivo íntegro del owner y vigente. Correlacionado con `profiles` y
     * `document_types`; lo comparten el badge por lote y la búsqueda.
     *
     * @param  array<int, string>  $acceptedStatuses
     */
    private function constrainValidRequiredDocument(
        QueryBuilder $query,
        array $acceptedStatuses,
        CarbonImmutable $today,
    ): void {
        $query->selectRaw('1')
            ->from('profile_documents as candidate_documents')
            ->join('files as candidate_files', 'candidate_files.id', '=', 'candidate_documents.file_id')
            ->whereColumn('candidate_documents.document_type_id', 'document_types.id')
            ->whereColumn('candidate_documents.profile_id', 'profiles.id')
            ->whereColumn('candidate_files.owner_profile_id', 'profiles.id')
            ->whereColumn('candidate_files.uploader_user_id', 'profiles.owner_user_id')
            ->where('candidate_files.kind', 'document')
            ->where('candidate_files.size', '>', 0)
            ->whereNotNull('candidate_files.checksum')
            ->whereNull('candidate_files.storage_deleted_at')
            ->whereIn('candidate_documents.status', $acceptedStatuses)
            ->whereNotIn('candidate_documents.status', [
                ProfileDocument::STATUS_SUPERSEDED,
                ProfileDocument::STATUS_RETIRED,
            ])
            ->whereRaw('(candidate_documents.valid_until IS NULL OR candidate_documents.valid_until >= ?)', [$today->toDateString()])
            ->whereRaw('(NOT document_types.requires_expiration OR candidate_documents.valid_until IS NOT NULL)');
    }

    private function escapeLike(string $term): string
    {
        return str_replace(
            [self::LIKE_ESCAPE, '%', '_'],
            [self::LIKE_ESCAPE.self::LIKE_ESCAPE, self::LIKE_ESCAPE.'%', self::LIKE_ESCAPE.'_'],
            mb_strtolower(trim($term)),
        );
    }
}
