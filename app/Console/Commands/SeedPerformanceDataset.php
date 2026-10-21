<?php

namespace App\Console\Commands;

use App\Contracts\Clock;
use App\Enums\ProfileCategory;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * T24 (P30): dataset sintético de volumen para medir la búsqueda. Solo corre contra
 * una base descartable cuyo nombre termina en `_perf` (ver docs/operacion.md): nunca
 * sobre la base de desarrollo ni sobre producción. Inserta por lotes con SQL directo
 * porque el objetivo es el volumen, no recorrer los servicios (eso lo prueban los
 * tests); respeta igual todos los CHECK y FKs del esquema.
 *
 * Mezcla (con --profiles=10000): receptores de las cuatro categorías; ~85 % elegibles
 * (publicado, completo, grant activo) y el resto vencido, suspendido, inactivo,
 * borrador o con cuenta suspendida; ~30 % de los elegibles verificados con documento
 * aprobado y vigente; 1–3 ciudades, 1–2 idiomas y ~15 días disponibles en 60 días.
 * Crea además una empresa turística habilitada (`perf-company@example.test`) que es
 * el actor de `contactur:perf-search`.
 */
final class SeedPerformanceDataset extends Command
{
    private const CHUNK = 1000;

    /** @var string */
    protected $signature = 'contactur:perf-seed
        {--profiles=10000 : Perfiles receptores a crear}
        {--seed=2026 : Semilla para que el dataset sea reproducible}
        {--password=Perf-Contactur-2026 : Contraseña de la empresa actor (base descartable)}';

    /** @var string */
    protected $description = 'Crea el dataset sintético de volumen (P30) en una base descartable *_perf.';

    public function handle(Clock $clock): int
    {
        $database = (string) config('database.connections.'.config('database.default').'.database');
        if (! str_ends_with($database, '_perf')) {
            $this->components->error("La base actual es \"{$database}\": este comando solo corre sobre una base descartable *_perf.");

            return self::FAILURE;
        }
        $total = filter_var($this->option('profiles'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 200_000]]);
        if ($total === false) {
            $this->components->error('--profiles debe ser un entero entre 1 y 200000.');

            return self::FAILURE;
        }
        if (DB::table('users')->where('email', 'perf-company@example.test')->exists()) {
            $this->components->error('El dataset ya existe en esta base. Recreala (migrate:fresh --seed) antes de volver a sembrar.');

            return self::FAILURE;
        }

        mt_srand((int) $this->option('seed'));
        $now = $clock->now()->utc();
        $today = $clock->now()->setTimezone('America/Argentina/Buenos_Aires')->startOfDay();
        $started = hrtime(true);
        $password = Hash::make((string) $this->option('password'));
        $cities = DB::table('cities')->orderBy('id')->pluck('id')->all();
        $languages = DB::table('languages')->orderBy('id')->pluck('id')->all();
        $services = DB::table('services')->orderBy('id')->pluck('id')->all();
        $documentTypes = DB::table('document_types')->where('required', true)->get(['id', 'category', 'requires_expiration'])->keyBy('category');
        if ($cities === [] || $languages === [] || $services === [] || $documentTypes->isEmpty()) {
            $this->components->error('Faltan catálogos: corré primero `php artisan db:seed`.');

            return self::FAILURE;
        }

        $adminId = $this->insertUser('perf-admin@example.test', 'Admin Perf', $password, $now, 'admin');
        $companyOwner = $this->insertUser('perf-company@example.test', 'Empresa Perf', $password, $now);
        $companyId = $this->insertProfile($companyOwner, ProfileCategory::TourismCompany->value, 'Empresa Perf Actor', 0, 'published', $now, $cities[0]);
        DB::table('company_details')->insert(['profile_id' => $companyId, 'legal_name' => 'Empresa Perf SA', 'years_active' => 10, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('profile_cities')->insert(['profile_id' => $companyId, 'city_id' => $cities[0]]);
        $this->insertGrant($companyId, 'active', null, $adminId, $now);

        $categories = [ProfileCategory::Guide->value, ProfileCategory::Guide->value, ProfileCategory::Driver->value, ProfileCategory::Instructor->value, ProfileCategory::TransportCompany->value];
        $bar = $this->output->createProgressBar($total);
        $counts = ['eligible' => 0, 'verified' => 0, 'availability_days' => 0];

        for ($offset = 0; $offset < $total; $offset += self::CHUNK) {
            $size = min(self::CHUNK, $total - $offset);
            DB::transaction(function () use ($size, $offset, $password, $now, $today, $cities, $languages, $services, $documentTypes, $categories, $adminId, &$counts): void {
                $users = [];
                for ($index = 0; $index < $size; $index++) {
                    $number = $offset + $index + 1;
                    $roll = mt_rand(1, 100);
                    $users[] = [
                        'public_id' => (string) Str::ulid(),
                        'name' => "Persona Perf {$number}",
                        'email' => "perf-{$number}@example.test",
                        'email_verified_at' => $now,
                        'password' => $password,
                        'system_role' => 'user',
                        'status' => $roll <= 2 ? 'suspended' : 'active',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                DB::table('users')->insert($users);
                $userIds = DB::table('users')->whereIn('email', array_column($users, 'email'))->orderBy('id')->pluck('id', 'email');

                $profiles = [];
                $plans = [];
                foreach ($users as $index => $user) {
                    $number = $offset + $index + 1;
                    $category = $categories[$number % count($categories)];
                    $roll = mt_rand(1, 100);
                    // 85 % elegible; el resto cubre cada motivo de exclusión de la búsqueda.
                    $state = match (true) {
                        $user['status'] === 'suspended' => 'owner_suspended',
                        $roll <= 85 => 'eligible',
                        $roll <= 90 => 'expired',
                        $roll <= 93 => 'grant_suspended',
                        $roll <= 96 => 'draft',
                        default => 'inactive',
                    };
                    $cityCount = mt_rand(1, min(3, count($cities)));
                    $profileCities = array_slice($this->shuffled($cities), 0, $cityCount);
                    $publicId = (string) Str::ulid();
                    $profiles[] = [
                        'public_id' => $publicId,
                        'owner_user_id' => $userIds[$user['email']],
                        'category' => $category,
                        'display_name' => $this->displayName($category, $number),
                        'slug' => 'perf-'.$number.'-'.strtolower($publicId),
                        'description' => 'Perfil sintético de volumen para medir la búsqueda.',
                        'primary_city_id' => $profileCities[0],
                        'onboarding_step' => 4,
                        'completed_at' => $state === 'draft' ? null : $now,
                        'status' => $state === 'draft' ? 'draft' : 'published',
                        'availability_version' => 1,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                    $plans[$publicId] = ['state' => $state, 'cities' => $profileCities, 'category' => $category, 'owner' => $userIds[$user['email']]];
                }
                DB::table('profiles')->insert($profiles);
                $profileIds = DB::table('profiles')->whereIn('public_id', array_keys($plans))->pluck('id', 'public_id');

                $grants = $cityRows = $languageRows = $serviceRows = $contacts = $days = [];
                foreach ($plans as $publicId => $plan) {
                    $profileId = $profileIds[$publicId];
                    foreach ($plan['cities'] as $cityId) {
                        $cityRows[] = ['profile_id' => $profileId, 'city_id' => $cityId];
                    }
                    foreach (array_slice($this->shuffled($languages), 0, mt_rand(1, min(2, count($languages)))) as $languageId) {
                        $languageRows[] = ['profile_id' => $profileId, 'language_id' => $languageId];
                    }
                    $serviceRows[] = ['profile_id' => $profileId, 'service_id' => $services[array_rand($services)]];
                    $contacts[] = ['profile_id' => $profileId, 'contact_email' => "contacto-{$profileId}@example.test", 'phone' => '+54 9 2901 '.str_pad((string) $profileId, 6, '0', STR_PAD_LEFT), 'created_at' => $now, 'updated_at' => $now];
                    if ($plan['state'] !== 'inactive') {
                        $grants[] = $this->grantRow($profileId, match ($plan['state']) {
                            'grant_suspended' => 'suspended',
                            default => 'active',
                        }, $plan['state'] === 'expired' ? $now->subDays(mt_rand(1, 30)) : null, $adminId, $now);
                    }
                    if ($plan['state'] === 'eligible') {
                        $counts['eligible']++;
                    }
                    // ~15 días disponibles en los próximos 60, en una de sus ciudades.
                    foreach (array_slice($this->shuffled(range(1, 60)), 0, 15) as $dayOffset) {
                        $days[] = [
                            'profile_id' => $profileId,
                            'city_id' => $plan['cities'][array_rand($plan['cities'])],
                            'date' => $today->addDays($dayOffset)->toDateString(),
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                    if ($plan['state'] === 'eligible' && mt_rand(1, 100) <= 30) {
                        $this->insertApprovedDocument($profileId, $plan['owner'], $documentTypes[$plan['category']], $adminId, $now, $today);
                        $counts['verified']++;
                    }
                }
                foreach ([
                    'access_grants' => $grants,
                    'profile_cities' => $cityRows,
                    'profile_language' => $languageRows,
                    'profile_service' => $serviceRows,
                    'profile_contacts' => $contacts,
                    'availability_days' => $days,
                ] as $table => $rows) {
                    foreach (array_chunk($rows, self::CHUNK) as $chunk) {
                        DB::table($table)->insert($chunk);
                    }
                }
                $counts['availability_days'] += count($days);
            });
            $bar->advance($size);
        }
        $bar->finish();
        $this->newLine(2);
        DB::statement('ANALYZE');

        $this->table(['Dato', 'Valor'], [
            ['Perfiles receptores', (string) $total],
            ['Elegibles para la búsqueda', (string) $counts['eligible']],
            ['Verificados (documento aprobado)', (string) $counts['verified']],
            ['Filas de availability_days', (string) $counts['availability_days']],
            ['Actor', 'perf-company@example.test (empresa turística habilitada)'],
            ['Duración', number_format((hrtime(true) - $started) / 1e9, 1).' s'],
        ]);

        return self::SUCCESS;
    }

    private function insertUser(string $email, string $name, string $password, CarbonImmutable $now, string $role = 'user'): int
    {
        return (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'name' => $name,
            'email' => $email,
            'email_verified_at' => $now,
            'password' => $password,
            'system_role' => $role,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function insertProfile(int $owner, string $category, string $name, int $number, string $status, CarbonImmutable $now, int $cityId): int
    {
        $publicId = (string) Str::ulid();

        return (int) DB::table('profiles')->insertGetId([
            'public_id' => $publicId,
            'owner_user_id' => $owner,
            'category' => $category,
            'display_name' => $name,
            'slug' => 'perf-actor-'.$number.'-'.strtolower($publicId),
            'description' => 'Empresa actor del benchmark de búsqueda.',
            'primary_city_id' => $cityId,
            'onboarding_step' => 4,
            'completed_at' => $now,
            'status' => $status,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function insertGrant(int $profileId, string $status, ?CarbonImmutable $expiresAt, int $adminId, CarbonImmutable $now): void
    {
        DB::table('access_grants')->insert($this->grantRow($profileId, $status, $expiresAt, $adminId, $now));
    }

    /** @return array<string, mixed> */
    private function grantRow(int $profileId, string $status, ?CarbonImmutable $expiresAt, int $adminId, CarbonImmutable $now): array
    {
        return [
            'profile_id' => $profileId,
            'status' => $status,
            'starts_at' => null,
            'expires_at' => $expiresAt,
            'city_limit' => 3,
            'monthly_contact_limit' => 50,
            'gallery_enabled' => false,
            'version' => 1,
            'updated_by' => $adminId,
            'reason' => 'Dataset sintético de volumen (P30).',
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function insertApprovedDocument(int $profileId, int $ownerId, object $type, int $adminId, CarbonImmutable $now, CarbonImmutable $today): void
    {
        $fileId = DB::table('files')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'owner_profile_id' => $profileId,
            'uploader_user_id' => $ownerId,
            'kind' => 'document',
            'disk' => 'local',
            'private_path' => "perf/documents/{$profileId}.pdf",
            'original_filename' => "habilitacion-{$profileId}.pdf",
            'mime' => 'application/pdf',
            'size' => 1024,
            'checksum' => hash('sha256', 'perf-'.$profileId),
            'created_at' => $now,
        ]);
        DB::table('profile_documents')->insert([
            'public_id' => (string) Str::ulid(),
            'profile_id' => $profileId,
            'document_type_id' => $type->id,
            'file_id' => $fileId,
            'revision' => 1,
            'valid_until' => $type->requires_expiration ? $today->addYear()->toDateString() : null,
            'status' => 'approved',
            'reviewed_by' => $adminId,
            'reviewed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function displayName(string $category, int $number): string
    {
        $prefix = match ($category) {
            ProfileCategory::Guide->value => 'Guía',
            ProfileCategory::Driver->value => 'Conductor',
            ProfileCategory::Instructor->value => 'Instructora',
            default => 'Transportes',
        };
        $places = ['Fueguino', 'del Beagle', 'Andino', 'Austral', 'de la Patagonia', 'del Glaciar', 'Cordillerano', 'del Sur'];

        return "{$prefix} {$places[$number % count($places)]} {$number}";
    }

    /**
     * @template T
     *
     * @param  array<int, T>  $items
     * @return array<int, T>
     */
    private function shuffled(array $items): array
    {
        for ($index = count($items) - 1; $index > 0; $index--) {
            $swap = mt_rand(0, $index);
            [$items[$index], $items[$swap]] = [$items[$swap], $items[$index]];
        }

        return array_values($items);
    }
}
