<?php

namespace Tests\Feature;

use App\Contracts\Clock;
use App\Enums\AccessGrantStatus;
use App\Enums\ProfileCategory;
use App\Enums\ProfileStatus;
use App\Models\AccessGrant;
use App\Models\AvailabilityDay;
use App\Models\City;
use App\Models\DocumentType;
use App\Models\File;
use App\Models\Language;
use App\Models\Profile;
use App\Models\ProfileContact;
use App\Models\ProfileDocument;
use App\Models\Qualification;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FrozenClock;
use Tests\TestCase;

/**
 * T14 — búsqueda, ficha y disponibilidad pública (R11/R12, P16/P17/P18).
 *
 * El reloj queda en 2026-11-10 02:30 UTC = 2026-11-09 23:30 en Buenos Aires:
 * el seed de orden y "hoy" usan la fecha de negocio (2026-11-09), no la UTC.
 */
final class DirectorySearchTest extends TestCase
{
    use RefreshDatabase;

    private const SEED_DAY_1 = '2026-11-09';

    private const D1 = '2026-11-20';

    private const D2 = '2026-11-21';

    private const D3 = '2026-11-22';

    private FrozenClock $clock;

    private City $ushuaia;

    private City $calafate;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->seed(CatalogSeeder::class);
        $now = CarbonImmutable::parse('2026-11-10T02:30:00Z');
        $this->travelTo($now);
        $this->clock = new FrozenClock($now);
        $this->app->instance(Clock::class, $this->clock);
        [$this->ushuaia, $this->calafate] = City::query()->orderBy('id')->limit(2)->get()->all();
    }

    public function test_p16_range_all_excludes_partial_any_counts_it_and_other_city_days_do_not_count(): void
    {
        [$token, $actor] = $this->actor('p16-company@example.test');
        $partial = $this->receiver('Guía dos de tres');
        $full = $this->receiver('Guía tres de tres');
        $this->available($partial, $this->ushuaia, self::D1, self::D2);
        // El tercer día está habilitado, pero en otra ciudad: no cuenta para Ushuaia.
        $this->available($partial, $this->calafate, self::D3);
        $this->available($full, $this->ushuaia, self::D1, self::D2, self::D3);
        $range = ['city_id' => $this->ushuaia->id, 'date_from' => self::D1, 'date_to' => self::D3];

        $all = $this->search($token, $actor, $range)->assertOk();
        $this->assertSame([$full->public_id], $this->ids($all));
        $all->assertJsonPath('data.0.matched_days', 3)
            ->assertJsonPath('data.0.requested_days', 3)
            ->assertJsonPath('data.0.availability_status', 'available');

        $any = $this->search($token, $actor, [...$range, 'match_mode' => 'any'])->assertOk();
        $this->assertEqualsCanonicalizing([$full->public_id, $partial->public_id], $this->ids($any));
        $partialRow = $this->row($any, $partial);
        $this->assertSame(2, $partialRow['matched_days']);
        $this->assertSame(3, $partialRow['requested_days']);
        $this->assertSame('partial', $partialRow['availability_status']);

        // Sin ciudad (D11): `all` exige todos los días en una misma ciudad, así que
        // 2 en Ushuaia + 1 en El Calafate siguen sin cubrir el rango.
        $withoutCity = ['date_from' => self::D1, 'date_to' => self::D3];
        $this->assertSame([$full->public_id], $this->ids($this->search($token, $actor, $withoutCity)->assertOk()));
        $anyWithoutCity = $this->search($token, $actor, [...$withoutCity, 'match_mode' => 'any'])->assertOk();
        $this->assertSame(2, $this->row($anyWithoutCity, $partial)['matched_days']);

        // Un día que el perfil solo tiene en otra ciudad no aparece ni en `any`.
        $this->assertSame([$full->public_id], $this->ids($this->search($token, $actor, [
            'city_id' => $this->ushuaia->id,
            'date_from' => self::D3,
            'date_to' => self::D3,
            'match_mode' => 'any',
        ])->assertOk()));

        // Sin rango no se afirma disponibilidad.
        $this->search($token, $actor, [])->assertOk()
            ->assertJsonPath('data.0.matched_days', 0)
            ->assertJsonPath('data.0.requested_days', 0)
            ->assertJsonPath('data.0.availability_status', 'unspecified');
    }

    public function test_p17_ineligible_profiles_are_excluded_in_sql_and_pages_never_skip_or_repeat(): void
    {
        [$token, $actor] = $this->actor('p17-company@example.test');
        $eligible = [
            $this->receiver('Elegible guía'),
            $this->receiver('Elegible chofer', ProfileCategory::Driver),
            $this->receiver('Elegible instructor', ProfileCategory::Instructor),
            $this->receiver('Elegible transporte', ProfileCategory::TransportCompany),
            $this->receiver('Elegible con vencimiento futuro', grant: ['expires_at' => CarbonImmutable::parse('2026-11-10T03:00:00Z')]),
        ];
        $this->verify($eligible[2]);

        $this->receiver('Vencido justo ahora', grant: ['expires_at' => CarbonImmutable::parse('2026-11-10T02:30:00Z')]);
        $this->receiver('Vencido ayer', grant: ['expires_at' => CarbonImmutable::parse('2026-11-09T02:30:00Z')]);
        $this->receiver('Grant inactivo', grant: ['status' => AccessGrantStatus::Inactive]);
        $this->receiver('Grant suspendido', grant: ['status' => AccessGrantStatus::Suspended]);
        $this->receiver('Grant futuro', grant: ['starts_at' => CarbonImmutable::parse('2026-11-11T00:00:00Z')]);
        $this->receiver('Sin grant', grant: null);
        $this->receiver('Perfil suspendido', attributes: ['status' => ProfileStatus::Suspended]);
        $this->receiver('Borrador completo', attributes: ['status' => ProfileStatus::Draft]);
        $this->receiver('Publicado sin completar', attributes: ['completed_at' => null]);
        $suspendedOwner = $this->receiver('Cuenta suspendida');
        User::query()->whereKey($suspendedOwner->owner_user_id)->update(['status' => User::STATUS_SUSPENDED]);
        $this->receiver('Empresa no buscable', ProfileCategory::TourismCompany);

        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });
        $seen = $this->walk($token, $actor, [], 1);

        // Con per_page=1 cada página trae exactamente una fila elegible: si el
        // filtrado ocurriera en PHP después del LIMIT habría páginas vacías.
        $this->assertCount(count($eligible), $seen);
        $this->assertSame(array_unique($seen), $seen);
        $this->assertEqualsCanonicalizing(
            array_map(static fn (Profile $profile): string => $profile->public_id, $eligible),
            $seen,
        );
        $this->assertSame($eligible[2]->public_id, $seen[0], 'El verificado va primero.');

        $searchSql = array_values(array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'sort_hash')));
        $this->assertCount(count($eligible), $searchSql);
        foreach ([
            'limit 2',
            'access_grants.expires_at',
            'access_grants.starts_at',
            'owners.status',
            '"profiles"."completed_at" is not null',
            '"profiles"."status" = ?',
            '"profiles"."category" in',
        ] as $fragment) {
            $this->assertStringContainsString($fragment, $searchSql[0]);
        }
        $this->assertStringNotContainsString('offset', $searchSql[1]);
    }

    public function test_p18_contact_data_never_appears_in_search_directory_or_public_availability(): void
    {
        [$token, $actor] = $this->actor('p18-company@example.test');
        $target = $this->receiver('Guía con contacto privado');
        User::query()->whereKey($target->owner_user_id)->update(['email' => 'cuenta-privada-guia@example.test']);
        ProfileContact::query()->where('profile_id', $target->id)->update([
            'contact_email' => 'contacto-privado-guia@example.test',
            'phone' => '+5492901555123',
        ]);
        $this->verify($target);
        $document = ProfileDocument::query()->where('profile_id', $target->id)->with('file')->sole();
        $this->available($target, $this->ushuaia, self::D1, self::D2);
        $secrets = [
            'cuenta-privada-guia@example.test',
            'contacto-privado-guia@example.test',
            '+5492901555123',
            '5492901555123',
            $document->file->private_path,
            $document->file->checksum,
            (string) $document->registration_number,
            '"contact_email"',
            '"phone"',
            '"email"',
            '"slug"',
            '"owner_user_id"',
        ];
        $base = '/api/v1/profiles/'.$actor->public_id;

        $responses = [
            $this->search($token, $actor, []),
            $this->search($token, $actor, ['city_id' => $this->ushuaia->id, 'date_from' => self::D1, 'date_to' => self::D2]),
            $this->withToken($token)->getJson($base.'/directory/'.$target->public_id),
            $this->withToken($token)->getJson($base.'/directory/'.$target->public_id.'/availability?month=2026-11'),
        ];

        foreach ($responses as $response) {
            $response->assertOk();
            $body = (string) $response->getContent();
            $this->assertStringContainsString($target->public_id, $body);
            foreach ($secrets as $secret) {
                $this->assertStringNotContainsString($secret, $body);
            }
        }
    }

    public function test_actor_without_can_search_gets_403_with_the_real_denial_code(): void
    {
        $target = $this->receiver('Guía destino');
        $cases = [
            ['receiver-no-grant@example.test', ProfileCategory::Guide, null, 'subscription_required'],
            ['company-no-grant@example.test', ProfileCategory::TourismCompany, null, 'subscription_required'],
            ['company-inactive@example.test', ProfileCategory::TourismCompany, ['status' => AccessGrantStatus::Inactive], 'subscription_required'],
            ['company-expired@example.test', ProfileCategory::TourismCompany, ['expires_at' => CarbonImmutable::parse('2026-11-01T00:00:00Z')], 'subscription_expired'],
            ['company-suspended-grant@example.test', ProfileCategory::TourismCompany, ['status' => AccessGrantStatus::Suspended], 'profile_suspended'],
        ];

        foreach ($cases as [$email, $category, $grant, $code]) {
            [$token, $actor] = $this->actor($email, $category, $grant);
            $base = '/api/v1/profiles/'.$actor->public_id;

            foreach ([
                $base.'/search',
                $base.'/directory/'.$target->public_id,
                $base.'/directory/'.$target->public_id.'/availability?month=2026-11',
            ] as $url) {
                $this->withToken($token)->getJson($url)
                    ->assertForbidden()
                    ->assertJsonPath('error.code', $code);
            }
        }

        // Perfil del actor suspendido por admin: misma precedencia que AccessService.
        [$token, $actor] = $this->actor('company-profile-suspended@example.test');
        $actor->forceFill(['status' => ProfileStatus::Suspended])->save();
        $this->search($token, $actor, [])->assertForbidden()->assertJsonPath('error.code', 'profile_suspended');

        // AccessService otorga can_search a cualquier categoría con acceso vigente
        // (acceso.md: "Buscar/fichas de directorio"): un receptor activo busca y
        // nunca se ve a sí mismo.
        [$token, $receiverActor] = $this->actor('receiver-active@example.test', ProfileCategory::Guide);
        $this->assertSame([$target->public_id], $this->ids($this->search($token, $receiverActor, [])->assertOk()));
    }

    public function test_foreign_actor_profile_and_ineligible_or_own_target_are_not_found(): void
    {
        [$token, $actor] = $this->actor('owner-company@example.test');
        [, $foreignActor] = $this->actor('foreign-company@example.test');
        $target = $this->receiver('Guía visible');
        $foreignBase = '/api/v1/profiles/'.$foreignActor->public_id;

        foreach ([
            $foreignBase.'/search',
            $foreignBase.'/directory/'.$target->public_id,
            $foreignBase.'/directory/'.$target->public_id.'/availability?month=2026-11',
        ] as $url) {
            $this->withToken($token)->getJson($url)->assertNotFound()->assertJsonPath('error.code', 'not_found');
        }

        [, $ownGuide] = $this->actor('own-guide@example.test', ProfileCategory::Guide);
        $hidden = [
            $this->receiver('Guía vencida', grant: ['expires_at' => CarbonImmutable::parse('2026-11-01T00:00:00Z')]),
            $this->receiver('Guía incompleta', attributes: ['completed_at' => null]),
            $this->receiver('Empresa destino', ProfileCategory::TourismCompany),
            $actor,
        ];
        $base = '/api/v1/profiles/'.$actor->public_id;

        foreach ([...array_map(static fn (Profile $profile): string => $profile->public_id, $hidden), (string) Str::ulid()] as $targetId) {
            $this->withToken($token)->getJson($base.'/directory/'.$targetId)
                ->assertNotFound()->assertJsonPath('error.code', 'not_found');
            $this->withToken($token)->getJson($base.'/directory/'.$targetId.'/availability?month=2026-11')
                ->assertNotFound()->assertJsonPath('error.code', 'not_found');
        }

        // Un perfil ajeno elegible sí es visible, aunque sea de otra cuenta receptora.
        $this->withToken($token)->getJson($base.'/directory/'.$ownGuide->public_id)->assertOk();
        $this->withToken($token)->getJson($base.'/directory/'.$target->public_id)->assertOk();
    }

    public function test_filters_by_type_name_with_escaped_wildcards_city_verified_and_combinations(): void
    {
        [$token, $actor] = $this->actor('filters-company@example.test');
        $percent = $this->receiver('Ana 100% Patagonia', cities: [$this->ushuaia]);
        $thousand = $this->receiver('Ana 1000 Patagonia', cities: [$this->calafate]);
        $underscore = $this->receiver('Bruno_Sur', ProfileCategory::Driver, cities: [$this->ushuaia]);
        $letter = $this->receiver('BrunoXSur', ProfileCategory::Driver, cities: [$this->calafate]);
        $bang = $this->receiver('Hola!Mundo', ProfileCategory::Instructor);
        $this->verify($percent);
        $this->verify($underscore);

        $this->assertSame([$percent->public_id], $this->ids($this->search($token, $actor, ['name' => '100%'])));
        $this->assertSame([$underscore->public_id], $this->ids($this->search($token, $actor, ['name' => 'o_s'])));
        $this->assertSame([$bang->public_id], $this->ids($this->search($token, $actor, ['name' => 'a!m'])));
        $this->assertEqualsCanonicalizing(
            [$percent->public_id, $thousand->public_id],
            $this->ids($this->search($token, $actor, ['name' => '  ANA  '])),
        );
        $this->assertEqualsCanonicalizing(
            [$underscore->public_id, $letter->public_id],
            $this->ids($this->search($token, $actor, ['type' => 'driver'])),
        );
        $this->assertEqualsCanonicalizing(
            [$percent->public_id, $underscore->public_id],
            $this->ids($this->search($token, $actor, ['city_id' => $this->ushuaia->id])),
        );
        $this->assertEqualsCanonicalizing(
            [$percent->public_id, $underscore->public_id],
            $this->ids($this->search($token, $actor, ['verified' => 'true'])),
        );
        $this->assertEqualsCanonicalizing(
            [$thousand->public_id, $letter->public_id, $bang->public_id],
            $this->ids($this->search($token, $actor, ['verified' => 'false'])),
        );
        $combined = $this->search($token, $actor, ['type' => 'driver', 'city_id' => $this->ushuaia->id, 'verified' => '1']);
        $this->assertSame([$underscore->public_id], $this->ids($combined));
        $combined->assertJsonPath('data.0.safe_profile.verified', true)
            ->assertJsonPath('data.0.safe_profile.category', 'driver');
        $this->assertSame([], $this->ids($this->search($token, $actor, ['type' => 'instructor', 'verified' => 'true'])));

        $this->search($token, $actor, ['type' => 'tourism_company'])->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed');
        $this->search($token, $actor, ['verified' => 'yes'])->assertUnprocessable();
        $this->search($token, $actor, ['city_id' => 999999])->assertUnprocessable();
        $this->search($token, $actor, ['per_page' => 51])->assertUnprocessable();
        $this->search($token, $actor, ['category' => 'guide'])->assertUnprocessable();
    }

    public function test_tampered_or_foreign_cursors_are_rejected(): void
    {
        [$token, $actor] = $this->actor('cursor-company@example.test');
        [$otherToken, $otherActor] = $this->actor('cursor-other@example.test');
        foreach (['Uno', 'Dos', 'Tres'] as $name) {
            $this->receiver('Guía '.$name);
        }
        $cursor = (string) $this->search($token, $actor, ['per_page' => 1])->assertOk()->json('meta.next_cursor');
        $this->assertNotSame('', $cursor);
        [$body, $signature] = explode('.', $cursor);
        $payload = json_decode((string) base64_decode(strtr($body, '-_', '+/')), true);
        $this->assertIsArray($payload);
        $payload['k'][2] = '00000000000000000000000000';
        $forgedBody = rtrim(strtr(base64_encode((string) json_encode($payload)), '+/', '-_'), '=');

        foreach ([
            $forgedBody.'.'.$signature,
            $body.'.'.strrev($signature),
            $body,
            'no-es-un-cursor',
            $body.'.'.$signature.'.extra',
        ] as $invalid) {
            $this->search($token, $actor, ['per_page' => 1, 'cursor' => $invalid])
                ->assertUnprocessable()
                ->assertJsonPath('error.code', 'validation_failed')
                ->assertJsonStructure(['error' => ['fields' => ['cursor']]]);
        }

        // Firma válida, pero emitido para otros filtros u otro actor: 409.
        foreach ([['name' => 'guía'], ['verified' => 'false'], ['date_from' => self::D1, 'date_to' => self::D1]] as $otherFilters) {
            $this->search($token, $actor, [...$otherFilters, 'per_page' => 1, 'cursor' => $cursor])
                ->assertStatus(409)
                ->assertJsonPath('error.code', 'search_cursor_mismatch');
        }
        $this->search($otherToken, $otherActor, ['per_page' => 1, 'cursor' => $cursor])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'search_cursor_mismatch');

        // Normalización: mayúsculas/espacios del nombre no cambian los filtros
        // efectivos, y per_page no forma parte de ellos.
        $named = (string) $this->search($token, $actor, ['name' => 'guía', 'per_page' => 1])->json('meta.next_cursor');
        $this->search($token, $actor, ['name' => '  GUÍA ', 'per_page' => 2, 'cursor' => $named])->assertOk();
    }

    public function test_walking_every_page_with_a_small_page_size_matches_the_full_list(): void
    {
        [$token, $actor] = $this->actor('walk-company@example.test');
        for ($index = 1; $index <= 7; $index++) {
            $profile = $this->receiver('Guía recorrido '.$index);
            if ($index % 3 === 0) {
                $this->verify($profile);
            }
        }

        $full = $this->ids($this->search($token, $actor, ['per_page' => 50])->assertOk()->assertJsonPath('meta.next_cursor', null));
        $this->assertCount(7, $full);

        foreach ([1, 2, 3] as $perPage) {
            $this->assertSame($full, $this->walk($token, $actor, [], $perPage));
        }
    }

    public function test_order_is_stable_within_a_day_follows_the_daily_hash_and_a_cursor_keeps_its_seed(): void
    {
        [$token, $actor] = $this->actor('order-company@example.test');
        $profiles = [];
        $verified = [];
        for ($index = 1; $index <= 8; $index++) {
            $profile = $this->receiver('Guía orden '.$index);
            $profiles[] = $profile;
            if ($index <= 2) {
                $this->verify($profile);
                $verified[] = $profile->public_id;
            }
        }

        $first = $this->search($token, $actor, ['per_page' => 50])->assertOk()
            ->assertJsonPath('meta.search_seed', self::SEED_DAY_1);
        $dayOne = $this->ids($first);
        $this->assertSame($dayOne, $this->ids($this->search($token, $actor, ['per_page' => 50])));
        $this->assertSame($this->expectedOrder($profiles, $verified, self::SEED_DAY_1), $dayOne);
        $this->assertEqualsCanonicalizing($verified, array_slice($dayOne, 0, 2));

        // Cruce de medianoche de negocio con una sesión abierta: la página 2 usa
        // el seed del cursor, no el del día nuevo.
        $pageOne = $this->search($token, $actor, ['per_page' => 3])->assertOk();
        $this->clock->set(CarbonImmutable::parse('2026-11-11T02:30:00Z'));
        $rest = $this->walk($token, $actor, [], 3, (string) $pageOne->json('meta.next_cursor'));
        $this->assertSame($dayOne, [...$this->ids($pageOne), ...$rest]);

        $fresh = $this->search($token, $actor, ['per_page' => 50])->assertOk()
            ->assertJsonPath('meta.search_seed', '2026-11-10');
        $this->assertSame($this->expectedOrder($profiles, $verified, '2026-11-10'), $this->ids($fresh));
        $this->assertEqualsCanonicalizing($verified, array_slice($this->ids($fresh), 0, 2));
    }

    public function test_search_keeps_a_constant_query_count_between_page_sizes(): void
    {
        [$token, $actor] = $this->actor('nplus1-company@example.test');
        $languages = Language::query()->orderBy('id')->limit(2)->pluck('id')->all();
        $service = (int) Service::query()->orderBy('id')->value('id');

        for ($index = 1; $index <= 10; $index++) {
            $profile = $this->receiver('Guía consultas '.$index, cities: [$this->ushuaia, $this->calafate]);
            $profile->forceFill(['primary_city_id' => $this->ushuaia->id])->save();
            $profile->languages()->sync($languages);
            $profile->services()->sync([$service]);
            $this->available($profile, $this->ushuaia, self::D1, self::D2);
            if ($index % 2 === 0) {
                $this->verify($profile);
            }
        }

        $query = ['city_id' => $this->ushuaia->id, 'date_from' => self::D1, 'date_to' => self::D2];
        $this->countQueries($token, $actor, [...$query, 'per_page' => 1]);
        $single = $this->countQueries($token, $actor, [...$query, 'per_page' => 1]);
        $full = $this->countQueries($token, $actor, [...$query, 'per_page' => 10]);

        $this->assertGreaterThan(5, $single);
        $this->assertSame($single, $full);
    }

    public function test_public_availability_returns_only_target_days_and_respects_month_range_and_city(): void
    {
        [$token, $actor] = $this->actor('availability-company@example.test');
        $target = $this->receiver('Guía calendario público');
        $other = $this->receiver('Otra guía');
        $this->available($target, $this->ushuaia, self::D1, self::D3);
        $this->available($target, $this->calafate, self::D2);
        $this->available($target, $this->ushuaia, '2026-12-01');
        $this->available($other, $this->ushuaia, '2026-11-25');
        $target->forceFill(['availability_version' => 4])->save();
        $url = '/api/v1/profiles/'.$actor->public_id.'/directory/'.$target->public_id.'/availability';

        $month = $this->withToken($token)->getJson($url.'?month=2026-11')->assertOk()
            ->assertJsonPath('data.profile_id', $target->public_id)
            ->assertJsonPath('data.version', 4);
        $this->assertSame(
            [[self::D1, $this->ushuaia->id], [self::D2, $this->calafate->id], [self::D3, $this->ushuaia->id]],
            $this->days($month),
        );
        $this->assertSame(['date', 'city'], array_keys((array) $month->json('data.available_days.0')));
        $this->assertSame(['profile_id', 'version', 'available_days'], array_keys((array) $month->json('data')));

        $this->assertSame(
            [[self::D2, $this->calafate->id]],
            $this->days($this->withToken($token)->getJson($url.'?month=2026-11&city_id='.$this->calafate->id)->assertOk()),
        );
        $this->assertSame(
            [[self::D1, $this->ushuaia->id], [self::D2, $this->calafate->id]],
            $this->days($this->withToken($token)->getJson($url.'?date_from='.self::D1.'&date_to='.self::D2)->assertOk()),
        );
        $this->assertSame(
            [[self::D3, $this->ushuaia->id], ['2026-12-01', $this->ushuaia->id]],
            $this->days($this->withToken($token)->getJson($url.'?date_from='.self::D2.'&date_to=2026-12-31&city_id='.$this->ushuaia->id)->assertOk()),
        );
        $this->assertSame([], $this->days($this->withToken($token)->getJson($url.'?month=2027-01')->assertOk()));
    }

    public function test_invalid_ranges_are_rejected_with_422_on_search_and_public_availability(): void
    {
        [$token, $actor] = $this->actor('ranges-company@example.test');
        $target = $this->receiver('Guía rangos');
        $url = '/api/v1/profiles/'.$actor->public_id.'/directory/'.$target->public_id.'/availability';

        foreach ([
            '',
            '?month=2026-13',
            '?month=2026-11&date_from='.self::D1.'&date_to='.self::D2,
            '?date_from='.self::D1,
            '?date_to='.self::D1,
            '?date_from='.self::D2.'&date_to='.self::D1,
            '?date_from=2026-11-01&date_to=2027-01-30',
            '?date_from=2026-02-30&date_to=2026-03-01',
            '?month=2026-11&city_id=999999',
            '?month=2026-11&extra=1',
        ] as $query) {
            $this->withToken($token)->getJson($url.$query)
                ->assertUnprocessable()
                ->assertJsonPath('error.code', 'validation_failed');
        }
        // 90 días inclusivos es el máximo configurado; 91 ya no.
        $this->withToken($token)->getJson($url.'?date_from=2026-11-01&date_to=2027-01-29')->assertOk();
        $this->withToken($token)->getJson($url.'?date_from=2026-11-01&date_to=2027-01-30')
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['fields' => ['date_to']]]);

        foreach ([
            ['date_from' => self::D1],
            ['date_to' => self::D1],
            ['date_from' => self::D2, 'date_to' => self::D1],
            ['date_from' => '2026-11-01', 'date_to' => '2027-01-30'],
            ['date_from' => '20-11-2026', 'date_to' => self::D1],
            ['date_from' => self::D1, 'date_to' => self::D1, 'match_mode' => 'some'],
        ] as $query) {
            $this->search($token, $actor, $query)
                ->assertUnprocessable()
                ->assertJsonPath('error.code', 'validation_failed');
        }
        $this->search($token, $actor, ['date_from' => '2026-11-01', 'date_to' => '2027-01-29'])->assertOk();
    }

    public function test_directory_profile_returns_safe_fields_document_statuses_and_no_references(): void
    {
        [$token, $actor] = $this->actor('ficha-company@example.test');
        $guide = $this->receiver('Guía ficha completa', cities: [$this->ushuaia, $this->calafate]);
        $guide->forceFill(['description' => 'Guía bilingüe de montaña', 'primary_city_id' => $this->ushuaia->id])->save();
        $guide->languages()->sync(Language::query()->orderBy('id')->limit(2)->pluck('id')->all());
        $guide->services()->sync([(int) Service::query()->where('code', 'guiding')->value('id')]);
        $guide->guideDetails()->update(['education_summary' => 'Tecnicatura en turismo']);
        Qualification::query()->create([
            'profile_id' => $guide->id,
            'title' => 'Guía de trekking',
            'institution' => 'Instituto de prueba',
            'completed_on' => '2020-03-01',
            'expires_on' => null,
        ]);
        $this->verify($guide);
        $url = '/api/v1/profiles/'.$actor->public_id.'/directory/';

        $response = $this->withToken($token)->getJson($url.$guide->public_id)->assertOk()
            ->assertJsonPath('data.profile.id', $guide->public_id)
            ->assertJsonPath('data.profile.category', 'guide')
            ->assertJsonPath('data.profile.description', 'Guía bilingüe de montaña')
            ->assertJsonPath('data.profile.primary_city.id', $this->ushuaia->id)
            ->assertJsonCount(2, 'data.profile.cities')
            ->assertJsonCount(2, 'data.profile.languages')
            ->assertJsonPath('data.profile.services.0.code', 'guiding')
            ->assertJsonPath('data.profile.verified', true)
            ->assertJsonPath('data.profile.avatar', null)
            ->assertJsonPath('data.profile.gallery', [])
            ->assertJsonPath('data.profile.category_details', ['education_summary' => 'Tecnicatura en turismo'])
            ->assertJsonPath('data.qualifications', [[
                'title' => 'Guía de trekking',
                'institution' => 'Instituto de prueba',
                'completed_on' => '2020-03-01',
                'expires_on' => null,
            ]])
            ->assertJsonPath('data.fleet_vehicles', [])
            ->assertJsonPath('data.specialties', [])
            ->assertJsonPath('data.visible_references', [])
            ->assertJsonPath('data.document_statuses', [[
                'document_type' => ['code' => 'guide_license', 'label' => 'Habilitación profesional de guía'],
                'status' => 'approved',
                'valid_until' => '2027-12-31',
                'is_currently_valid' => true,
            ]]);
        $this->assertSame(
            ['profile', 'document_statuses', 'qualifications', 'fleet_vehicles', 'specialties', 'visible_references'],
            array_keys((array) $response->json('data')),
        );
        $this->assertSame(
            ['id', 'category', 'display_name', 'description', 'primary_city', 'cities', 'languages', 'services', 'avatar', 'gallery', 'verified', 'category_details'],
            array_keys((array) $response->json('data.profile')),
        );

        $missing = $this->receiver('Chofer sin documentos', ProfileCategory::Driver);
        $this->withToken($token)->getJson($url.$missing->public_id)->assertOk()
            ->assertJsonPath('data.profile.verified', false)
            ->assertJsonPath('data.profile.category_details', [
                'license_type' => null,
                'vehicle_type' => null,
                'vehicle_capacity' => null,
            ])
            ->assertJsonPath('data.document_statuses.0.status', 'missing')
            ->assertJsonPath('data.document_statuses.0.is_currently_valid', false);

        $expired = $this->receiver('Instructor vencido', ProfileCategory::Instructor);
        $this->verify($expired, '2026-11-01');
        $this->withToken($token)->getJson($url.$expired->public_id)->assertOk()
            ->assertJsonPath('data.profile.verified', false)
            ->assertJsonPath('data.document_statuses.0.status', 'expired')
            ->assertJsonPath('data.document_statuses.0.valid_until', '2026-11-01')
            ->assertJsonPath('data.document_statuses.0.is_currently_valid', false);

        $pending = $this->receiver('Transporte pendiente', ProfileCategory::TransportCompany);
        $this->verify($pending, status: ProfileDocument::STATUS_PENDING);
        $this->withToken($token)->getJson($url.$pending->public_id)->assertOk()
            ->assertJsonPath('data.profile.verified', false)
            ->assertJsonPath('data.profile.category_details.legal_name', 'Transporte pendiente')
            ->assertJsonPath('data.document_statuses.0.status', 'pending');
    }

    /**
     * Cuenta registrada por API (token real) con un perfil propio publicado.
     *
     * @param  array<string, mixed>|null  $grant  null = sin grant
     * @return array{string, Profile}
     */
    private function actor(
        string $email,
        ProfileCategory $category = ProfileCategory::TourismCompany,
        ?array $grant = [],
    ): array {
        $data = $this->postJson('/api/v1/auth/register', [
            'name' => 'Persona de Prueba',
            'email' => $email,
            'password' => 'Clave-segura-2026',
            'password_confirmation' => 'Clave-segura-2026',
        ])->assertCreated()->json('data');
        $owner = User::query()->where('email', $email)->firstOrFail();
        $profile = $this->receiver('Actor '.$email, $category, ['owner_user_id' => $owner->id], $grant);

        return [(string) $data['access_token'], $profile];
    }

    /**
     * Perfil publicado, completo y (por defecto) con grant activo sin fechas.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>|null  $grant  null = sin grant
     * @param  array<int, City>  $cities
     */
    private function receiver(
        string $name,
        ProfileCategory $category = ProfileCategory::Guide,
        array $attributes = [],
        ?array $grant = [],
        array $cities = [],
    ): Profile {
        $profile = Profile::factory()->create(array_merge([
            'category' => $category,
            'display_name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(8)),
            'status' => ProfileStatus::Published,
            'onboarding_step' => 4,
            'completed_at' => CarbonImmutable::parse('2026-10-01T12:00:00Z'),
        ], $attributes));

        if ($grant !== null) {
            AccessGrant::query()->create(array_merge([
                'profile_id' => $profile->id,
                'status' => AccessGrantStatus::Active,
                'starts_at' => null,
                'expires_at' => null,
                'city_limit' => null,
                'monthly_contact_limit' => null,
                'gallery_enabled' => false,
                'version' => 1,
                'updated_by' => $profile->owner_user_id,
                'reason' => 'Habilitación de prueba',
            ], $grant));
        }

        if ($cities !== []) {
            $profile->cities()->sync(array_map(static fn (City $city): int => $city->id, $cities));
        }

        return $profile;
    }

    /** Documento requerido de la categoría con archivo íntegro del owner. */
    private function verify(
        Profile $profile,
        ?string $validUntil = '2027-12-31',
        string $status = ProfileDocument::STATUS_APPROVED,
    ): void {
        $type = DocumentType::query()
            ->where('category', $profile->category->value)
            ->where('required', true)
            ->firstOrFail();
        $file = File::factory()->create([
            'owner_profile_id' => $profile->id,
            'uploader_user_id' => $profile->owner_user_id,
            'private_path' => 'documents/'.Str::ulid().'.pdf',
        ]);
        ProfileDocument::factory()->create([
            'profile_id' => $profile->id,
            'document_type_id' => $type->id,
            'file_id' => $file->id,
            'status' => $status,
            'valid_until' => $validUntil,
            // El check de revisión exige revisor y fecha fuera de pending; alcanza
            // cualquier usuario existente como revisor ficticio.
            'reviewed_by' => $status === ProfileDocument::STATUS_PENDING ? null : $profile->owner_user_id,
            'reviewed_at' => $status === ProfileDocument::STATUS_PENDING ? null : CarbonImmutable::parse('2026-10-02T12:00:00Z'),
        ]);
    }

    private function available(Profile $profile, City $city, string ...$dates): void
    {
        foreach ($dates as $date) {
            AvailabilityDay::query()->create(['profile_id' => $profile->id, 'city_id' => $city->id, 'date' => $date]);
        }
    }

    /**
     * @param  array<string, mixed>  $query
     * @return TestResponse<Response>
     */
    private function search(string $token, Profile $actor, array $query): TestResponse
    {
        $suffix = $query === [] ? '' : '?'.http_build_query($query);

        return $this->withToken($token)->getJson('/api/v1/profiles/'.$actor->public_id.'/search'.$suffix);
    }

    /**
     * Recorre todas las páginas desde $cursor (o la primera) y devuelve los ids.
     *
     * @param  array<string, mixed>  $query
     * @return array<int, string>
     */
    private function walk(string $token, Profile $actor, array $query, int $perPage, ?string $cursor = null): array
    {
        $seen = [];
        $pages = 0;

        do {
            $response = $this->search($token, $actor, array_filter([
                ...$query,
                'per_page' => $perPage,
                'cursor' => $cursor,
            ], static fn (mixed $value): bool => $value !== null))->assertOk();
            $ids = $this->ids($response);
            $cursor = $response->json('meta.next_cursor');

            if ($cursor !== null) {
                $this->assertCount($perPage, $ids, 'Una página intermedia nunca viene corta.');
            }

            $seen = [...$seen, ...$ids];
            $this->assertLessThan(50, ++$pages);
        } while ($cursor !== null);

        return $seen;
    }

    /**
     * @param  TestResponse<Response>  $response
     * @return array<int, string>
     */
    private function ids(TestResponse $response): array
    {
        /** @var array<int, array{safe_profile: array{id: string}}> $data */
        $data = $response->json('data');

        return array_map(static fn (array $row): string => $row['safe_profile']['id'], $data);
    }

    /**
     * @param  TestResponse<Response>  $response
     * @return array<string, mixed>
     */
    private function row(TestResponse $response, Profile $profile): array
    {
        /** @var array<int, array<string, mixed>> $data */
        $data = $response->json('data');

        foreach ($data as $row) {
            if ($row['safe_profile']['id'] === $profile->public_id) {
                return $row;
            }
        }

        $this->fail('El perfil no está en la respuesta.');
    }

    /**
     * @param  TestResponse<Response>  $response
     * @return array<int, array{string, int}>
     */
    private function days(TestResponse $response): array
    {
        /** @var array<int, array{date: string, city: array{id: int}}> $days */
        $days = $response->json('data.available_days');

        return array_map(static fn (array $day): array => [$day['date'], $day['city']['id']], $days);
    }

    /**
     * Orden esperado de D16 calculado en PHP: verificados primero, luego
     * md5(public_id:seed) y por último public_id.
     *
     * @param  array<int, Profile>  $profiles
     * @param  array<int, string>  $verified
     * @return array<int, string>
     */
    private function expectedOrder(array $profiles, array $verified, string $seed): array
    {
        $rows = array_map(static fn (Profile $profile): array => [
            in_array($profile->public_id, $verified, true) ? 0 : 1,
            md5($profile->public_id.':'.$seed),
            $profile->public_id,
        ], $profiles);
        usort($rows, static fn (array $a, array $b): int => ($a[0] <=> $b[0]) ?: (strcmp($a[1], $b[1]) ?: strcmp($a[2], $b[2])));

        return array_map(static fn (array $row): string => $row[2], $rows);
    }

    /** @param array<string, mixed> $query */
    private function countQueries(string $token, Profile $actor, array $query): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->search($token, $actor, $query)->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();
        DB::flushQueryLog();

        return $count;
    }
}
