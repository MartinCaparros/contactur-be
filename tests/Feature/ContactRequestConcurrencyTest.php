<?php

namespace Tests\Feature;

use App\Contracts\Clock;
use App\Enums\AccessGrantStatus;
use App\Enums\ProfileCategory;
use App\Enums\ProfileStatus;
use App\Exceptions\ApiProblemException;
use App\Http\Resources\ContactRequestResource;
use App\Models\AccessGrant;
use App\Models\ContactRequest;
use App\Models\Profile;
use App\Models\User;
use App\Services\ContactRequestService;
use Carbon\CarbonImmutable;
use Closure;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\FrozenClock;
use Tests\TestCase;
use Throwable;

/**
 * P20, P21 y P22 con conexiones PostgreSQL reales y procesos paralelos.
 *
 * RefreshDatabase no sirve: su transacción por test es invisible para otras
 * conexiones. Acá los fixtures se confirman (DatabaseTruncation sobre
 * contactur_test, que TestCase exige) y cada operación corre en un proceso hijo
 * (pcntl_fork) con su propia conexión, llamando al servicio real.
 *
 * Barrera: el padre toma pg_advisory_lock exclusivo, cada hijo pide el mismo lock
 * en modo compartido y queda bloqueado; el padre verifica en pg_locks que todos
 * esperan y lo suelta: PostgreSQL concede los compartidos a la vez. El padre se
 * desconecta antes de cada fork para que ningún hijo herede (ni cierre) su socket.
 *
 * Cada caso se repite ROUNDS veces con fixtures nuevos. Al terminar se trunca la
 * base para que las clases con RefreshDatabase que siguen la encuentren vacía.
 */
final class ContactRequestConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    private const ROUNDS = 5;

    private const BARRIER_LOCK = 917301;

    private const TIMEOUT_SECONDS = 15;

    private string $workDir;

    private int $round = 0;

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('pcntl_fork')) {
            throw new RuntimeException('La extensión pcntl es obligatoria para las pruebas de concurrencia.');
        }

        $this->seed(CatalogSeeder::class);
        $this->freezeAt('2026-11-10T12:00:00Z');
        $this->workDir = sys_get_temp_dir().'/contactur-concurrency-'.getmypid().'-'.bin2hex(random_bytes(4));
        if (! mkdir($this->workDir, 0700) && ! is_dir($this->workDir)) {
            throw new RuntimeException('No se pudo crear '.$this->workDir);
        }
    }

    protected function tearDown(): void
    {
        $this->removeWorkDir();
        // Los datos confirmados no deben llegar a las clases con RefreshDatabase.
        DB::reconnect();
        $this->truncateTablesForAllConnections();

        parent::tearDown();
    }

    public function test_p20_parallel_sends_for_the_same_pair_create_one_pending_and_consume_one_unit(): void
    {
        for ($round = 1; $round <= self::ROUNDS; $round++) {
            [$owner, $company] = $this->participant(ProfileCategory::TourismCompany);
            [, $guide] = $this->participant(ProfileCategory::Guide);

            $results = $this->race([
                fn (): array => $this->create($owner, $company, $guide, null),
                fn (): array => $this->create($owner, $company, $guide, null),
            ]);

            $this->assertOutcomes($results, ['ok:201', 'error:409:contact_request_pending'], $round);
            $this->assertSame(1, $this->pendingCount($company, $guide), 'round '.$round);
            $this->assertSame(1, ContactRequest::query()->where('sender_profile_id', $company->id)->count(), 'round '.$round);
            $this->assertSame(1, $this->usage($company, '2026-11-01'), 'round '.$round);
        }
    }

    /**
     * Misma Idempotency-Key y mismo payload en paralelo: el lock de la fila del
     * perfil emisor serializa ambas transacciones; la segunda ve la key confirmada
     * y reproduce la respuesta (201, Idempotency-Replayed: true, mismo cuerpo).
     * Con otro payload la segunda recibe 409 idempotency_conflict.
     */
    public function test_p20_parallel_sends_with_the_same_idempotency_key_replay_or_conflict(): void
    {
        for ($round = 1; $round <= self::ROUNDS; $round++) {
            [$owner, $company] = $this->participant(ProfileCategory::TourismCompany);
            [, $guide] = $this->participant(ProfileCategory::Guide);
            $key = 'parallel-key-'.$round.'-'.bin2hex(random_bytes(6));

            $results = $this->race([
                fn (): array => $this->create($owner, $company, $guide, $key),
                fn (): array => $this->create($owner, $company, $guide, $key),
            ]);

            $this->assertOutcomes($results, ['ok:201', 'ok:201'], $round);
            $replayed = array_map(static fn (array $result): bool => (bool) $result['value']['replayed'], $results);
            sort($replayed);
            $this->assertSame([false, true], $replayed, 'round '.$round);
            $this->assertSame($results[0]['value']['body'], $results[1]['value']['body'], 'round '.$round);
            $this->assertSame(1, ContactRequest::query()->where('sender_profile_id', $company->id)->count(), 'round '.$round);
            $this->assertSame(1, DB::table('idempotency_keys')->where('profile_id', $company->id)->count(), 'round '.$round);
            $this->assertSame(1, $this->usage($company, '2026-11-01'), 'round '.$round);

            // Misma key, otro payload, en paralelo con la original.
            [, $otherGuide] = $this->participant(ProfileCategory::Guide);
            $otherKey = $key.'-b';
            $results = $this->race([
                fn (): array => $this->create($owner, $company, $otherGuide, $otherKey),
                fn (): array => $this->create($owner, $company, $otherGuide, $otherKey, 'Otro texto de servicio'),
            ]);

            $this->assertOutcomes($results, ['ok:201', 'error:409:idempotency_conflict'], $round);
            $this->assertSame(1, $this->pendingCount($company, $otherGuide), 'round '.$round);
            $this->assertSame(2, $this->usage($company, '2026-11-01'), 'round '.$round);
        }
    }

    /** 02:30Z del 1/12 sigue siendo 30/11 en Buenos Aires: la unidad cuenta para noviembre. */
    public function test_p21_the_last_monthly_unit_is_consumed_by_exactly_one_parallel_send(): void
    {
        $this->freezeAt('2026-12-01T02:30:00Z');

        for ($round = 1; $round <= self::ROUNDS; $round++) {
            [$owner, $company] = $this->participant(ProfileCategory::TourismCompany, ['monthly_contact_limit' => 1]);
            [, $first] = $this->participant(ProfileCategory::Guide);
            [, $second] = $this->participant(ProfileCategory::Driver);

            $results = $this->race([
                fn (): array => $this->create($owner, $company, $first, null),
                fn (): array => $this->create($owner, $company, $second, null),
            ]);

            $this->assertOutcomes($results, ['ok:201', 'error:403:capability_limit_reached'], $round);
            $this->assertSame(1, ContactRequest::query()->where('sender_profile_id', $company->id)->count(), 'round '.$round);
            $this->assertSame(1, $this->usage($company, '2026-11-01'), 'round '.$round);
            $this->assertSame(0, DB::table('contact_usage')->where('profile_id', $company->id)->where('month_start', '2026-12-01')->count());
        }
    }

    public function test_p22_parallel_accept_and_reject_produce_exactly_one_transition(): void
    {
        for ($round = 1; $round <= self::ROUNDS; $round++) {
            [$owner, $company] = $this->participant(ProfileCategory::TourismCompany);
            [$guideOwner, $guide] = $this->participant(ProfileCategory::Guide);
            $id = (string) $this->create($owner, $company, $guide, null)['id'];
            $service = fn (): ContactRequestService => $this->app->make(ContactRequestService::class);

            $results = $this->race([
                fn (): array => ['status' => (string) $service()->accept($guideOwner, $guide->public_id, $id)->getAttribute('effective_status')],
                fn (): array => ['status' => (string) $service()->reject($guideOwner, $guide->public_id, $id)->getAttribute('effective_status')],
            ]);

            $this->assertOutcomes($results, ['ok', 'error:409:contact_request_not_pending'], $round);
            $winner = array_values(array_filter($results, static fn (array $result): bool => $result['ok']))[0];
            $row = DB::table('contact_requests')->where('public_id', $id)->first(['status', 'responded_at']);
            $this->assertNotNull($row);
            $this->assertSame($winner['value']['status'], $row->status, 'round '.$round);
            $this->assertContains($row->status, ['accepted', 'rejected'], 'round '.$round);
            $this->assertNotNull($row->responded_at, 'round '.$round);
        }
    }

    /**
     * Corre cada operación en un proceso hijo con su propia conexión y las larga a
     * la vez. Devuelve, por índice, éxito con el valor o el error del servicio.
     *
     * @param  list<Closure(): array<string, mixed>>  $operations
     * @return list<array{ok: bool, status: int|null, code: string|null, value: array<string, mixed>, error: string|null}>
     */
    private function race(array $operations): array
    {
        $this->round++;
        $barrierFile = $this->workDir.'/barrier-'.$this->round;
        $this->assertSame(0, DB::transactionLevel(), 'Los fixtures deben estar confirmados antes del fork.');
        // Ningún hijo debe heredar el socket del padre: al destruirlo cerraría su sesión.
        foreach (array_keys(DB::getConnections()) as $name) {
            DB::disconnect($name);
        }

        $pids = [];
        foreach ($operations as $index => $operation) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('pcntl_fork falló.');
            }
            if ($pid === 0) {
                $this->runChild($this->resultFile($index), $barrierFile, $operation);
            }
            $pids[$index] = $pid;
        }

        $barrierError = null;
        try {
            DB::reconnect();
            DB::select('SELECT pg_advisory_lock(CAST(? AS bigint))', [self::BARRIER_LOCK]);
            touch($barrierFile);
            $barrierError = $this->waitForWaiters(count($operations));
        } finally {
            DB::select('SELECT pg_advisory_unlock(CAST(? AS bigint))', [self::BARRIER_LOCK]);
        }

        $exitCodes = [];
        foreach ($pids as $index => $pid) {
            pcntl_waitpid($pid, $status);
            $exitCodes[$index] = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : -1;
        }

        $results = [];
        foreach (array_keys($operations) as $index) {
            $file = $this->resultFile($index);
            $raw = is_file($file) ? (string) file_get_contents($file) : '';
            @unlink($file);
            /** @var array{ok: bool, status: int|null, code: string|null, value: array<string, mixed>, error: string|null}|null $decoded */
            $decoded = json_decode($raw, true);
            $this->assertIsArray($decoded, 'El hijo '.$index.' no dejó resultado (exit '.$exitCodes[$index].').');
            $this->assertNull($decoded['error'], 'Error inesperado en el hijo '.$index.': '.$decoded['error']);
            $this->assertSame(0, $exitCodes[$index], 'El hijo '.$index.' terminó con '.$exitCodes[$index].'.');
            $results[] = $decoded;
        }
        @unlink($barrierFile);
        $this->assertNull($barrierError, (string) $barrierError);

        return $results;
    }

    /** Espera a que todos los hijos estén bloqueados en el lock compartido. */
    private function waitForWaiters(int $expected): ?string
    {
        $deadline = microtime(true) + self::TIMEOUT_SECONDS;
        $waiting = 0;

        while (microtime(true) < $deadline) {
            $waiting = (int) DB::scalar(
                "SELECT count(*) FROM pg_locks
                 WHERE locktype = 'advisory' AND classid = 0 AND objid = ".self::BARRIER_LOCK." AND objsubid = 1
                   AND mode = 'ShareLock' AND NOT granted
                   AND database = (SELECT oid FROM pg_database WHERE datname = current_database())",
            );
            if ($waiting === $expected) {
                return null;
            }
            usleep(2000);
        }

        return 'Barrera: '.$waiting.' de '.$expected.' hijos llegaron al lock.';
    }

    /** @param Closure(): array<string, mixed> $operation */
    private function runChild(string $resultFile, string $barrierFile, Closure $operation): never
    {
        $result = ['ok' => false, 'status' => null, 'code' => null, 'value' => [], 'error' => null];

        try {
            DB::purge();
            DB::reconnect();
            $deadline = microtime(true) + self::TIMEOUT_SECONDS;
            while (! is_file($barrierFile)) {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('El padre no abrió la barrera.');
                }
                usleep(500);
                clearstatcache(true, $barrierFile);
            }
            DB::select('SELECT pg_advisory_lock_shared(CAST(? AS bigint))', [self::BARRIER_LOCK]);
            DB::select('SELECT pg_advisory_unlock_shared(CAST(? AS bigint))', [self::BARRIER_LOCK]);

            $result['value'] = $operation();
            $result['ok'] = true;
        } catch (ApiProblemException $exception) {
            $result['status'] = $exception->status;
            $result['code'] = $exception->errorCode;
        } catch (Throwable $exception) {
            $result['error'] = $exception::class.': '.$exception->getMessage();
        }

        file_put_contents($resultFile, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        try {
            DB::disconnect();
        } catch (Throwable) {
            // El proceso termina igual; el resultado ya quedó escrito.
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        exit(0);
    }

    /**
     * Resultados normalizados sin importar qué hijo ganó: 'ok', 'ok:201' o
     * 'error:409:code'.
     *
     * @param  list<array{ok: bool, status: int|null, code: string|null, value: array<string, mixed>, error: string|null}>  $results
     * @param  list<string>  $expected
     */
    private function assertOutcomes(array $results, array $expected, int $round): void
    {
        $actual = array_map(static function (array $result) use ($expected): string {
            if (! $result['ok']) {
                return 'error:'.$result['status'].':'.$result['code'];
            }

            return in_array('ok', $expected, true) ? 'ok' : 'ok:'.$result['value']['status'];
        }, $results);
        sort($actual);
        sort($expected);

        $this->assertSame($expected, $actual, 'round '.$round);
    }

    /** @return array<string, mixed> */
    private function create(User $owner, Profile $sender, Profile $target, ?string $key, string $description = 'Guía bilingüe para trekking'): array
    {
        $response = $this->app->make(ContactRequestService::class)->create(
            $owner,
            $sender->public_id,
            [
                'target_profile_id' => $target->public_id,
                'service_description' => $description,
                'requested_from' => null,
                'requested_to' => null,
                'requested_city_id' => null,
            ],
            $key,
            static fn (ContactRequest $created): array => [
                'data' => (new ContactRequestResource($created))->resolve(Request::create('/')),
            ],
        );
        $body = json_encode($response->body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return [
            'status' => $response->status,
            'replayed' => $response->replayed,
            'id' => (string) data_get($response->body, 'data.id'),
            'body' => $body,
        ];
    }

    /**
     * Perfil publicado y completo con grant activo, confirmado en la base.
     *
     * @param  array<string, mixed>  $grant
     * @return array{User, Profile}
     */
    private function participant(ProfileCategory $category, array $grant = []): array
    {
        $owner = User::factory()->create();
        $profile = Profile::factory()->create([
            'owner_user_id' => $owner->id,
            'category' => $category,
            'status' => ProfileStatus::Published,
            'onboarding_step' => 4,
            'completed_at' => CarbonImmutable::parse('2026-10-01T12:00:00Z'),
        ]);
        AccessGrant::query()->create(array_merge([
            'profile_id' => $profile->id,
            'status' => AccessGrantStatus::Active,
            'starts_at' => null,
            'expires_at' => null,
            'city_limit' => null,
            'monthly_contact_limit' => null,
            'gallery_enabled' => false,
            'version' => 1,
            'updated_by' => $owner->id,
            'reason' => 'Habilitación de prueba',
        ], $grant));

        return [$owner, $profile];
    }

    private function freezeAt(string $instant): void
    {
        $now = CarbonImmutable::parse($instant);
        $this->travelTo($now);
        $this->app->instance(Clock::class, new FrozenClock($now));
    }

    private function pendingCount(Profile $sender, Profile $recipient): int
    {
        return DB::table('contact_requests')
            ->where('sender_profile_id', $sender->id)
            ->where('recipient_profile_id', $recipient->id)
            ->where('status', 'pending')
            ->count();
    }

    private function usage(Profile $profile, string $monthStart): int
    {
        return (int) DB::table('contact_usage')
            ->where('profile_id', $profile->id)
            ->where('month_start', $monthStart)
            ->value('used');
    }

    private function resultFile(int $index): string
    {
        return $this->workDir.'/result-'.$this->round.'-'.$index.'.json';
    }

    private function removeWorkDir(): void
    {
        if (! isset($this->workDir) || ! is_dir($this->workDir)) {
            return;
        }
        foreach ((array) glob($this->workDir.'/*') as $file) {
            if (is_string($file)) {
                @unlink($file);
            }
        }
        @rmdir($this->workDir);
    }
}
