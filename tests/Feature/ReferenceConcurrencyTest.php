<?php

namespace Tests\Feature;

use App\Contracts\Clock;
use App\Enums\AccessGrantStatus;
use App\Enums\ContactRequestStatus;
use App\Enums\ProfileCategory;
use App\Enums\ProfileStatus;
use App\Exceptions\ApiProblemException;
use App\Models\AccessGrant;
use App\Models\ContactRequest;
use App\Models\Profile;
use App\Models\Reference;
use App\Models\User;
use App\Services\ReferenceService;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\FrozenClock;
use Tests\TestCase;
use Throwable;

/**
 * P25 con conexiones PostgreSQL reales y procesos paralelos: mismo arnés que
 * ContactRequestConcurrencyTest (T17). Fixtures confirmados con
 * DatabaseTruncation sobre contactur_test, un hijo por operación (pcntl_fork)
 * con su propia conexión llamando al servicio real, y barrera con
 * pg_advisory_lock exclusivo del padre / compartido de los hijos.
 *
 * Dos creaciones simultáneas del mismo autor para el mismo match: la fila de la
 * solicitud bloqueada serializa ambas y la segunda choca con el índice único
 * recién confirmado → exactamente un 201 y un 409 `reference_already_exists`
 * (nunca 500). Las dos partes a la vez: dos referencias, sin deadlock.
 */
final class ReferenceConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    private const ROUNDS = 5;

    private const BARRIER_LOCK = 917302;

    private const TIMEOUT_SECONDS = 15;

    private string $workDir;

    private int $round = 0;

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('pcntl_fork')) {
            throw new RuntimeException('La extensión pcntl es obligatoria para las pruebas de concurrencia.');
        }

        $now = CarbonImmutable::parse('2026-11-10T12:00:00Z');
        $this->travelTo($now);
        $this->app->instance(Clock::class, new FrozenClock($now));
        $this->workDir = sys_get_temp_dir().'/contactur-references-'.getmypid().'-'.bin2hex(random_bytes(4));
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

    public function test_p25_parallel_creates_by_the_same_author_for_the_same_match_yield_one_201_and_one_409(): void
    {
        for ($round = 1; $round <= self::ROUNDS; $round++) {
            [$companyOwner, $company] = $this->participant(ProfileCategory::TourismCompany);
            [, $guide] = $this->participant(ProfileCategory::Guide);
            $match = $this->acceptedRequest($company, $guide);

            $results = $this->race([
                fn (): array => $this->create($companyOwner, $company, $match, 'Primera versión del texto de la referencia.'),
                fn (): array => $this->create($companyOwner, $company, $match, 'Segunda versión del texto de la referencia.'),
            ]);

            $this->assertOutcomes($results, ['ok', 'error:409:reference_already_exists'], $round);
            $this->assertSame(1, Reference::query()->where('contact_request_id', $match->id)->count(), 'round '.$round);
            $winner = array_values(array_filter($results, static fn (array $result): bool => $result['ok']))[0];
            $this->assertSame(
                $winner['value']['body'],
                Reference::query()->where('contact_request_id', $match->id)->value('body'),
                'round '.$round,
            );
        }
    }

    public function test_p25_both_parties_creating_at_once_get_one_reference_each_without_deadlock(): void
    {
        for ($round = 1; $round <= self::ROUNDS; $round++) {
            [$companyOwner, $company] = $this->participant(ProfileCategory::TourismCompany);
            [$guideOwner, $guide] = $this->participant(ProfileCategory::Guide);
            $match = $this->acceptedRequest($company, $guide);

            $results = $this->race([
                fn (): array => $this->create($companyOwner, $company, $match, 'Referencia de la empresa hacia la guía.'),
                fn (): array => $this->create($guideOwner, $guide, $match, 'Referencia de la guía hacia la empresa.'),
            ]);

            $this->assertOutcomes($results, ['ok', 'ok'], $round);
            $pairs = Reference::query()->where('contact_request_id', $match->id)->orderBy('author_profile_id')
                ->get(['author_profile_id', 'subject_profile_id'])
                ->map(static fn (Reference $reference): array => [$reference->author_profile_id, $reference->subject_profile_id])
                ->all();
            $expected = [[$company->id, $guide->id], [$guide->id, $company->id]];
            sort($expected);
            $this->assertSame($expected, $pairs, 'round '.$round);
        }
    }

    /** @return array<string, mixed> */
    private function create(User $owner, Profile $author, ContactRequest $match, string $body): array
    {
        $reference = $this->app->make(ReferenceService::class)->create($owner, $author->public_id, $match->public_id, $body);

        return ['id' => $reference->public_id, 'body' => $reference->body];
    }

    private function acceptedRequest(Profile $company, Profile $receiver): ContactRequest
    {
        $now = CarbonImmutable::parse('2026-11-10T12:00:00Z');

        return ContactRequest::query()->create([
            'sender_profile_id' => $company->id,
            'recipient_profile_id' => $receiver->id,
            'service_description' => 'Guía bilingüe para trekking',
            'status' => ContactRequestStatus::Accepted,
            'availability_status' => ContactRequest::AVAILABILITY_UNSPECIFIED,
            'expires_at' => $now->addDays(7),
            'responded_at' => $now,
            'created_at' => $now->subHour(),
            'updated_at' => $now,
        ]);
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
     * @param  list<array{ok: bool, status: int|null, code: string|null, value: array<string, mixed>, error: string|null}>  $results
     * @param  list<string>  $expected
     */
    private function assertOutcomes(array $results, array $expected, int $round): void
    {
        $actual = array_map(
            static fn (array $result): string => $result['ok'] ? 'ok' : 'error:'.$result['status'].':'.$result['code'],
            $results,
        );
        sort($actual);
        sort($expected);

        $this->assertSame($expected, $actual, 'round '.$round);
    }

    /** @return array{User, Profile} */
    private function participant(ProfileCategory $category): array
    {
        $owner = User::factory()->create();
        $profile = Profile::factory()->create([
            'owner_user_id' => $owner->id,
            'category' => $category,
            'status' => ProfileStatus::Published,
            'onboarding_step' => 4,
            'completed_at' => CarbonImmutable::parse('2026-10-01T12:00:00Z'),
        ]);
        AccessGrant::query()->create([
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
        ]);

        return [$owner, $profile];
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
