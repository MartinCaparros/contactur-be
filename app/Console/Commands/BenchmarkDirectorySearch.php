<?php

namespace App\Console\Commands;

use App\Contracts\Clock;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * T24 (P30): mide la búsqueda del directorio sobre el dataset de `contactur:perf-seed`.
 * Cada request recorre el stack HTTP completo de Laravel en proceso (middleware JWT,
 * validación del controller, servicio, repositorio, serialización JSON); no incluye
 * nginx ni php-fpm, que agregan un costo fijo y no dependen del volumen. Informa por
 * escenario p50/p95/máximo, consultas SQL por request (para detectar N+1) y el plan
 * `EXPLAIN (ANALYZE, BUFFERS)` de la consulta principal.
 */
final class BenchmarkDirectorySearch extends Command
{
    /** @var string */
    protected $signature = 'contactur:perf-search
        {--iterations=30 : Mediciones por escenario}
        {--warmup=3 : Requests descartados por escenario antes de medir}
        {--per-page=20 : Tamaño de página}
        {--email=perf-company@example.test : Empresa actor}
        {--password=Perf-Contactur-2026 : Contraseña de la empresa actor}
        {--json= : Archivo donde guardar el resultado completo en JSON}';

    /** @var string */
    protected $description = 'Mide p50/p95 y consultas por request de la búsqueda sobre el dataset *_perf (P30).';

    private int $queries = 0;

    /** @var list<array{sql: string, bindings: array<int, mixed>}> */
    private array $captured = [];

    public function handle(Kernel $kernel, Clock $clock): int
    {
        $database = (string) config('database.connections.'.config('database.default').'.database');
        if (! str_ends_with($database, '_perf')) {
            $this->components->error("La base actual es \"{$database}\": el benchmark solo corre sobre la base descartable *_perf.");

            return self::FAILURE;
        }
        $iterations = max(1, (int) $this->option('iterations'));
        $warmup = max(0, (int) $this->option('warmup'));
        $perPage = max(1, min(50, (int) $this->option('per-page')));

        DB::listen(function (QueryExecuted $query): void {
            $this->queries++;
            $this->captured[] = ['sql' => $query->sql, 'bindings' => $query->bindings];
        });

        $login = $this->send($kernel, 'POST', '/api/v1/auth/login', null, [
            'email' => (string) $this->option('email'),
            'password' => (string) $this->option('password'),
        ]);
        $token = (string) ($this->json($login)['data']['access_token'] ?? '');
        if ($login->getStatusCode() !== 200 || $token === '') {
            $this->components->error('No se pudo iniciar sesión con la empresa actor (¿corriste contactur:perf-seed?).');

            return self::FAILURE;
        }
        $me = $this->json($this->send($kernel, 'GET', '/api/v1/me', $token));
        $actor = (string) ($me['data']['profiles'][0]['id'] ?? '');
        $cityId = (int) DB::table('cities')->orderBy('id')->value('id');
        $today = $clock->now()->setTimezone('America/Argentina/Buenos_Aires')->startOfDay();
        $day = fn (int $offset): string => $today->addDays($offset)->toDateString();

        $scenarios = [
            'sin filtros' => [],
            'ciudad' => ['city_id' => $cityId],
            'rango 3 días (all) + ciudad' => ['city_id' => $cityId, 'date_from' => $day(10), 'date_to' => $day(12)],
            'rango 7 días (any) sin ciudad' => ['date_from' => $day(20), 'date_to' => $day(26), 'match_mode' => 'any'],
            'rango 30 días (all) sin ciudad' => ['date_from' => $day(1), 'date_to' => $day(30)],
            'nombre parcial' => ['name' => 'beagle'],
            'categoría + verificado' => ['type' => 'driver', 'verified' => 'true'],
        ];
        $first = $this->json($this->send($kernel, 'GET', $this->searchPath($actor, ['per_page' => $perPage]), $token));
        $cursor = $first['meta']['next_cursor'] ?? null;
        if (is_string($cursor) && $cursor !== '') {
            $scenarios['página 2 por cursor'] = ['cursor' => $cursor];
        }

        $results = [];
        $all = [];
        foreach ($scenarios as $label => $filters) {
            $path = $this->searchPath($actor, [...$filters, 'per_page' => $perPage]);
            for ($index = 0; $index < $warmup; $index++) {
                $this->send($kernel, 'GET', $path, $token);
            }
            $durations = [];
            $queryCounts = [];
            $rows = 0;
            for ($index = 0; $index < $iterations; $index++) {
                $this->queries = 0;
                $started = hrtime(true);
                $response = $this->send($kernel, 'GET', $path, $token);
                $durations[] = (hrtime(true) - $started) / 1e6;
                $queryCounts[] = $this->queryCount();
                if ($response->getStatusCode() !== 200) {
                    $this->components->error("{$label}: HTTP {$response->getStatusCode()} ".mb_substr((string) $response->getContent(), 0, 300));

                    return self::FAILURE;
                }
                $rows = count($this->json($response)['data'] ?? []);
            }
            sort($durations);
            $all = [...$all, ...$durations];
            $results[$label] = [
                'filters' => $filters,
                'rows' => $rows,
                'p50_ms' => round($this->percentile($durations, 50), 1),
                'p95_ms' => round($this->percentile($durations, 95), 1),
                'max_ms' => round(max($durations), 1),
                'queries_min' => min($queryCounts),
                'queries_max' => max($queryCounts),
            ];
        }
        sort($all);

        $this->table(
            ['Escenario', 'Filas', 'p50 ms', 'p95 ms', 'máx ms', 'Consultas/request'],
            array_map(static fn (string $label, array $row): array => [
                $label,
                (string) $row['rows'],
                (string) $row['p50_ms'],
                (string) $row['p95_ms'],
                (string) $row['max_ms'],
                $row['queries_min'] === $row['queries_max'] ? (string) $row['queries_min'] : "{$row['queries_min']}–{$row['queries_max']}",
            ], array_keys($results), $results),
        );
        $overall = ['requests' => count($all), 'p50_ms' => round($this->percentile($all, 50), 1), 'p95_ms' => round($this->percentile($all, 95), 1), 'max_ms' => round(max($all), 1)];
        $this->components->info("Global: {$overall['requests']} requests, p50 {$overall['p50_ms']} ms, p95 {$overall['p95_ms']} ms, máx {$overall['max_ms']} ms.");

        $plans = [];
        foreach (['rango 3 días (all) + ciudad', 'sin filtros', 'rango 30 días (all) sin ciudad'] as $label) {
            $this->captured = [];
            $this->send($kernel, 'GET', $this->searchPath($actor, [...$scenarios[$label], 'per_page' => $perPage]), $token);
            $main = collect($this->captured)->first(static fn (array $query): bool => str_contains($query['sql'], 'sort_hash'));
            if ($main === null) {
                continue;
            }
            $plan = collect(DB::select('EXPLAIN (ANALYZE, BUFFERS) '.$main['sql'], $main['bindings']))
                ->map(static fn (object $row): string => (string) array_values((array) $row)[0])
                ->all();
            $plans[$label] = $plan;
            $this->newLine();
            $this->line("<options=bold>EXPLAIN ANALYZE — {$label}</>");
            foreach ($plan as $line) {
                $this->line($line);
            }
        }

        $counts = [];
        foreach (['users', 'profiles', 'access_grants', 'availability_days', 'profile_cities', 'profile_documents'] as $table) {
            $counts[$table] = DB::table($table)->count();
        }
        $this->newLine();
        $this->table(['Tabla', 'Filas'], array_map(static fn (string $table, int $count): array => [$table, (string) $count], array_keys($counts), $counts));

        $output = $this->option('json');
        if (is_string($output) && $output !== '') {
            file_put_contents($output, json_encode([
                'generated_at' => $clock->now()->toIso8601String(),
                'database' => $database,
                'iterations' => $iterations,
                'warmup' => $warmup,
                'per_page' => $perPage,
                'scenarios' => $results,
                'overall' => $overall,
                'row_counts' => $counts,
                'plans' => $plans,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL);
            $this->components->info("Resultado guardado en {$output}.");
        }

        return self::SUCCESS;
    }

    /** Consultas registradas por DB::listen desde el último reinicio del contador. */
    private function queryCount(): int
    {
        return $this->queries;
    }

    /** @param array<string, mixed> $query */
    private function searchPath(string $actor, array $query): string
    {
        return '/api/v1/profiles/'.$actor.'/search?'.http_build_query($query);
    }

    /** @param array<string, mixed>|null $body */
    private function send(Kernel $kernel, string $method, string $uri, ?string $token, ?array $body = null): Response
    {
        $server = ['HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '127.0.0.1'];
        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }
        if ($body !== null) {
            $server['CONTENT_TYPE'] = 'application/json';
        }
        $request = Request::create($uri, $method, [], [], [], $server, $body === null ? null : (string) json_encode($body));
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return $response;
    }

    /** @return array<string, mixed> */
    private function json(Response $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param list<float> $sorted */
    private function percentile(array $sorted, int $percentile): float
    {
        if ($sorted === []) {
            return 0.0;
        }
        $index = (int) ceil($percentile / 100 * count($sorted)) - 1;

        return $sorted[max(0, min(count($sorted) - 1, $index))];
    }
}
