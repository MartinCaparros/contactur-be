<?php

namespace Tests\Feature;

use App\Contracts\Clock;
use App\Enums\AccessGrantStatus;
use App\Enums\ProfileCategory;
use App\Enums\ProfileStatus;
use App\Models\AccessGrant;
use App\Models\AvailabilityDay;
use App\Models\City;
use App\Models\ContactRequest;
use App\Models\Profile;
use App\Models\ProfileContact;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FrozenClock;
use Tests\TestCase;

/**
 * T17 lote A — crear, listar, ver y cancelar solicitudes de contacto (R13, D13,
 * D21, D22, P19).
 *
 * Carbon queda congelado en el instante inicial (validez del JWT) y solo se mueve
 * el FrozenClock de la app: así se simulan 168 h sin invalidar el token. Las
 * sesiones se extienden para que no venzan al mover el reloj.
 */
final class ContactRequestCreationTest extends TestCase
{
    use RefreshDatabase;

    private const T0 = '2026-11-10T12:00:00Z';

    private FrozenClock $clock;

    private City $city;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->seed(CatalogSeeder::class);
        $this->freezeAt(self::T0);
        $this->city = City::query()->orderBy('id')->firstOrFail();
    }

    public function test_p19_only_an_enabled_tourism_company_sends_and_forbidden_targets_are_rejected(): void
    {
        $target = $this->receiver('Guía destino');

        foreach ([
            ProfileCategory::Guide,
            ProfileCategory::Instructor,
            ProfileCategory::Driver,
            ProfileCategory::TransportCompany,
        ] as $category) {
            [$token, $actor] = $this->actor('p19-'.$category->value.'@example.test', $category);
            // Con acceso vigente: la categoría no envía (no es un límite alcanzado).
            $this->send($token, $actor, $target)
                ->assertForbidden()
                ->assertJsonPath('error.code', 'capability_not_available')
                ->assertJsonMissingPath('error.fields.limit');
        }
        $this->assertSame(0, ContactRequest::query()->count());

        [$token, $company, $owner] = $this->actor('p19-company@example.test');
        $created = $this->send($token, $company, $target)->assertCreated()
            ->assertHeader('Idempotency-Replayed', 'false')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.sender.id', $company->public_id)
            ->assertJsonPath('data.recipient.id', $target->public_id)
            ->assertJsonPath('data.availability_status', 'unspecified')
            ->assertJsonPath('data.expires_at', '2026-11-17T12:00:00.000000Z')
            ->assertJsonPath('data.responded_at', null)
            ->assertJsonPath('data.cancelled_at', null);
        $this->assertSame(
            ['id', 'sender', 'recipient', 'service_description', 'requested_from', 'requested_to', 'requested_city', 'status', 'availability_status', 'expires_at', 'responded_at', 'cancelled_at', 'created_at'],
            array_keys((array) $created->json('data')),
        );

        // El propio perfil, una empresa y un receptor no elegible no existen como destino.
        $this->send($token, $company, $company)->assertNotFound()->assertJsonPath('error.code', 'not_found');
        $this->send($token, $company, $this->receiver('Otra empresa', ProfileCategory::TourismCompany))->assertNotFound();
        $this->send($token, $company, $this->receiver('Borrador', attributes: ['status' => ProfileStatus::Draft]))->assertNotFound();
        $this->send($token, $company, $this->receiver('Sin grant', grant: null))->assertNotFound();
        // Otro perfil receptor de la misma cuenta: visible en el directorio, pero no destino.
        $ownGuide = $this->receiver('Guía de la misma cuenta', attributes: ['owner_user_id' => $owner->id]);
        $this->send($token, $company, $ownGuide)
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['fields' => ['target_profile_id']]]);
        // Perfil actor ajeno → 404; sin descripción → 422.
        [, $foreign] = $this->actor('p19-foreign@example.test');
        $this->send($token, $foreign, $this->receiver('Guía ajena'))->assertNotFound();
        $this->send($token, $company, $this->receiver('Guía sin texto'), ['service_description' => ''])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['fields' => ['service_description']]]);
        // Empresa vencida: 403 con el denial real.
        [$expiredToken, $expired] = $this->actor('p19-expired@example.test', grant: [
            'expires_at' => CarbonImmutable::parse('2026-11-01T00:00:00Z'),
        ]);
        $this->send($expiredToken, $expired, $this->receiver('Guía para vencida'))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'subscription_expired');

        $this->assertSame(1, ContactRequest::query()->count());
    }

    public function test_sequential_idempotent_replay_returns_the_same_response_once_and_conflicts_on_other_payload(): void
    {
        [$token, $company] = $this->actor('idem-company@example.test');
        $target = $this->receiver('Guía idempotente');
        foreach (['2026-11-20', '2026-11-21'] as $date) {
            AvailabilityDay::query()->create(['profile_id' => $target->id, 'city_id' => $this->city->id, 'date' => $date]);
        }
        $payload = [
            'requested_from' => '2026-11-20',
            'requested_to' => '2026-11-21',
            'requested_city_id' => $this->city->id,
        ];
        $key = 'client-key-0001-abcdef';

        $first = $this->send($token, $company, $target, $payload, $key)->assertCreated()
            ->assertHeader('Idempotency-Replayed', 'false')
            ->assertJsonPath('data.availability_status', 'available')
            ->assertJsonPath('data.requested_city.id', $this->city->id);
        $replay = $this->send($token, $company, $target, $payload, $key)->assertCreated()
            ->assertHeader('Idempotency-Replayed', 'true');

        $this->assertSame($first->getContent(), $replay->getContent());
        $this->assertSame(1, ContactRequest::query()->count());
        $this->assertSame(1, $this->usage($company, '2026-11-01'));

        $this->send($token, $company, $target, [...$payload, 'service_description' => 'Otro texto'], $key)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'idempotency_conflict');
        $this->send($token, $company, $target, $payload, 'corta')
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['fields' => ['Idempotency-Key']]]);
        $this->assertSame(1, ContactRequest::query()->count());
        $this->assertSame(1, $this->usage($company, '2026-11-01'));

        // La key no saltea la autorización vigente: con acceso vencido no se reproduce.
        AccessGrant::query()->whereKey($company->id)->update(['expires_at' => '2026-11-10 11:00:00+00']);
        $this->send($token, $company, $target, $payload, $key)
            ->assertForbidden()
            ->assertJsonPath('error.code', 'subscription_expired');
    }

    public function test_monthly_quota_is_transactional_reported_by_access_and_uses_the_business_month(): void
    {
        // 02:30Z del 1/12 es todavía 30/11 23:30 en Buenos Aires.
        $this->freezeAt('2026-12-01T02:30:00Z');
        [$token, $company] = $this->actor('quota-company@example.test', grant: ['monthly_contact_limit' => 1]);
        $first = $this->receiver('Guía cuota uno');
        $second = $this->receiver('Guía cuota dos');
        $key = 'quota-key-000000001';

        $this->send($token, $company, $first, [], $key)->assertCreated();
        $this->assertSame(1, $this->usage($company, '2026-11-01'));
        $this->assertSame(0, DB::table('contact_usage')->where('month_start', '2026-12-01')->count());
        $this->withToken($token)->getJson('/api/v1/profiles/'.$company->public_id.'/access')->assertOk()
            ->assertJsonPath('data.contact_quota.month_start', '2026-11-01')
            ->assertJsonPath('data.contact_quota.used', 1)
            ->assertJsonPath('data.contact_quota.remaining', 0)
            ->assertJsonPath('data.capabilities.can_send_contact_requests', false);

        $this->send($token, $company, $second)
            ->assertForbidden()
            ->assertJsonPath('error.code', 'capability_limit_reached')
            ->assertJsonPath('error.fields.limit.0', '1');
        // Reproducir no consume cuota: la cuota agotada por la propia original no la bloquea.
        $this->send($token, $company, $first, [], $key)->assertCreated()->assertHeader('Idempotency-Replayed', 'true');
        $this->assertSame(1, ContactRequest::query()->count());
        $this->assertSame(1, $this->usage($company, '2026-11-01'));

        // 0 = bloqueado.
        [$blockedToken, $blocked] = $this->actor('quota-blocked@example.test', grant: ['monthly_contact_limit' => 0]);
        $this->send($blockedToken, $blocked, $first)
            ->assertForbidden()
            ->assertJsonPath('error.code', 'capability_limit_reached')
            ->assertJsonPath('error.fields.limit.0', '0');

        // 03:30Z = 1/12 00:30 en Buenos Aires: mes de negocio nuevo, cuota nueva.
        $this->clock->set(CarbonImmutable::parse('2026-12-01T03:30:00Z'));
        $this->send($token, $company, $second)->assertCreated();
        $this->assertSame(1, $this->usage($company, '2026-11-01'));
        $this->assertSame(1, $this->usage($company, '2026-12-01'));
        $this->withToken($token)->getJson('/api/v1/profiles/'.$company->public_id.'/access')->assertOk()
            ->assertJsonPath('data.contact_quota.month_start', '2026-12-01')
            ->assertJsonPath('data.contact_quota.used', 1);
    }

    public function test_a_duplicate_pending_is_rejected_by_the_service_and_by_a_partial_unique_index(): void
    {
        [$token, $company] = $this->actor('dup-company@example.test');
        $target = $this->receiver('Guía duplicada');

        $this->send($token, $company, $target)->assertCreated();
        $this->send($token, $company, $target)->assertStatus(409)->assertJsonPath('error.code', 'contact_request_pending');
        $this->assertSame(1, $this->usage($company, '2026-11-01'));

        $definition = (string) DB::table('pg_indexes')
            ->where('indexname', 'contact_requests_pending_pair_unique')
            ->value('indexdef');
        $this->assertStringContainsString('UNIQUE INDEX', $definition);
        $this->assertStringContainsString("WHERE ((status)::text = 'pending'::text)", $definition);

        $row = fn (string $status): array => [
            'public_id' => (string) Str::ulid(),
            'sender_profile_id' => $company->id,
            'recipient_profile_id' => $target->id,
            'service_description' => 'Inserción directa',
            'status' => $status,
            'availability_status' => 'unspecified',
            'expires_at' => '2026-11-17 12:00:00+00',
            'created_at' => '2026-11-10 12:00:00+00',
            'updated_at' => '2026-11-10 12:00:00+00',
        ];
        // Savepoint: el error no aborta la transacción de RefreshDatabase.
        try {
            DB::transaction(static fn () => DB::table('contact_requests')->insert($row('pending')));
            $this->fail('La base aceptó una segunda pending para el mismo par.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('contact_requests_pending_pair_unique', $exception->getMessage());
        }
        // El índice es parcial: otros estados del mismo par sí entran.
        DB::table('contact_requests')->insert($row('cancelled'));
        $this->assertSame(2, ContactRequest::query()->count());
    }

    public function test_resend_waits_for_the_cooldown_and_a_previous_acceptance_blocks_new_requests(): void
    {
        [$token, $company] = $this->actor('cooldown-company@example.test');
        $target = $this->receiver('Guía cooldown');

        $id = (string) $this->send($token, $company, $target)->assertCreated()->json('data.id');
        $this->clock->set(CarbonImmutable::parse('2026-11-10T13:00:00Z'));
        $this->cancel($token, $company, $id)->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancelled_at', '2026-11-10T13:00:00.000000Z');
        // Cancelar no devuelve cuota.
        $this->assertSame(1, $this->usage($company, '2026-11-01'));

        $this->clock->set(CarbonImmutable::parse('2026-11-17T12:59:59Z'));
        $this->send($token, $company, $target)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'contact_request_cooldown')
            ->assertJsonPath('error.fields.retry_after.0', '2026-11-17T13:00:00.000000Z');
        $this->clock->set(CarbonImmutable::parse('2026-11-17T13:00:00Z'));
        $this->send($token, $company, $target)->assertCreated();

        // Expiración por reloj: el cooldown corre desde expires_at y la pendiente
        // vencida se materializa al reenviar (libera el índice parcial).
        $lapsed = $this->receiver('Guía que no responde');
        $lapsedId = (string) $this->send($token, $company, $lapsed)->assertCreated()->json('data.id');
        $this->clock->set(CarbonImmutable::parse('2026-11-24T13:00:00Z'));
        $this->send($token, $company, $lapsed)->assertStatus(409)->assertJsonPath('error.code', 'contact_request_cooldown');
        $this->clock->set(CarbonImmutable::parse('2026-12-01T13:00:00Z'));
        $this->send($token, $company, $lapsed)->assertCreated();
        $this->assertSame('expired', DB::table('contact_requests')->where('public_id', $lapsedId)->value('status'));

        // Aceptación previa (insertada directo: aceptar es del lote B) → 409 siempre.
        $accepted = $this->receiver('Guía ya aceptada');
        DB::table('contact_requests')->insert([
            'public_id' => (string) Str::ulid(),
            'sender_profile_id' => $company->id,
            'recipient_profile_id' => $accepted->id,
            'service_description' => 'Aceptada antes',
            'status' => 'accepted',
            'availability_status' => 'unspecified',
            'expires_at' => '2026-10-08 12:00:00+00',
            'responded_at' => '2026-10-02 12:00:00+00',
            'created_at' => '2026-10-01 12:00:00+00',
            'updated_at' => '2026-10-02 12:00:00+00',
        ]);
        $this->send($token, $company, $accepted)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'contact_request_already_accepted');
    }

    public function test_effective_expiry_listing_filters_participants_and_cancellation_rules(): void
    {
        [$token, $company] = $this->actor('list-company@example.test');
        [$otherToken, $otherCompany] = $this->actor('list-other-company@example.test');
        [$guideToken, $guide] = $this->actor('list-guide@example.test', ProfileCategory::Guide);
        [$thirdToken, $third] = $this->actor('list-third@example.test', ProfileCategory::Guide);
        $driver = $this->receiver('Chofer listado', ProfileCategory::Driver);

        $toGuide = (string) $this->send($token, $company, $guide)->assertCreated()->json('data.id');
        $this->clock->set(CarbonImmutable::parse('2026-11-14T12:00:00Z'));
        $toDriver = (string) $this->send($token, $company, $driver)->assertCreated()->json('data.id');
        $fromOther = (string) $this->send($otherToken, $otherCompany, $guide)->assertCreated()->json('data.id');

        // 168 h después de la primera: vencida por reloj sin job; en la base sigue pending.
        $this->clock->set(CarbonImmutable::parse('2026-11-17T12:00:00Z'));
        $this->show($token, $company, $toGuide)->assertOk()->assertJsonPath('data.status', 'expired');
        $this->assertSame('pending', DB::table('contact_requests')->where('public_id', $toGuide)->value('status'));

        // Solo solicitudes donde el perfil actor participa, id desc.
        $this->assertSame([$fromOther, $toGuide], $this->listIds($guideToken, $guide, []));
        $this->assertSame([$toDriver, $toGuide], $this->listIds($token, $company, []));
        $this->assertSame([], $this->listIds($thirdToken, $third, []));
        $this->assertSame([$toGuide], $this->listIds($token, $company, ['status' => 'expired']));
        $this->assertSame([$toDriver], $this->listIds($token, $company, ['status' => 'pending']));
        $this->assertSame([$toDriver, $toGuide], $this->listIds($token, $company, ['direction' => 'sent']));
        $this->assertSame([], $this->listIds($token, $company, ['direction' => 'received']));
        $this->assertSame([$fromOther, $toGuide], $this->listIds($guideToken, $guide, ['direction' => 'received']));
        $this->assertSame([$fromOther], $this->listIds($guideToken, $guide, ['direction' => 'received', 'status' => 'pending']));
        $this->assertSame([], $this->listIds($guideToken, $guide, ['direction' => 'sent']));
        $this->list($token, $company, ['status' => 'unknown', 'cursor' => 'abc'])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['fields' => ['status', 'cursor']]]);

        // Participantes ven; terceros reciben 404 (por su perfil o por el ajeno).
        $this->show($guideToken, $guide, $toGuide)->assertOk()->assertJsonPath('data.sender.id', $company->public_id);
        $this->show($thirdToken, $third, $toGuide)->assertNotFound();
        $this->show($thirdToken, $company, $toGuide)->assertNotFound();
        $this->show($otherToken, $otherCompany, $toGuide)->assertNotFound();
        $this->cancel($thirdToken, $third, $fromOther)->assertNotFound();

        // Solo el emisor cancela, y solo una pendiente vigente.
        $this->cancel($guideToken, $guide, $fromOther)->assertForbidden()->assertJsonPath('error.code', 'forbidden');
        $this->cancel($token, $company, $toGuide)->assertStatus(409)->assertJsonPath('error.code', 'contact_request_not_pending');
        $this->cancel($otherToken, $otherCompany, $fromOther)->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->cancel($otherToken, $otherCompany, $fromOther)->assertStatus(409)->assertJsonPath('error.code', 'contact_request_not_pending');
        $this->assertSame([$fromOther], $this->listIds($guideToken, $guide, ['status' => 'cancelled']));
    }

    public function test_no_response_exposes_contact_email_phone_or_account_email(): void
    {
        [$token, $company, $companyOwner] = $this->actor('safe-company@example.test');
        [$guideToken, $guide, $guideOwner] = $this->actor('safe-guide-account@example.test', ProfileCategory::Guide);
        ProfileContact::query()->whereKey($guide->id)->update(['contact_email' => 'privado-guia@example.test', 'phone' => '+54 9 2901 555-0101']);
        ProfileContact::query()->whereKey($company->id)->update(['contact_email' => 'privado-empresa@example.test', 'phone' => '+54 9 2901 555-0202']);
        $secrets = [
            'privado-guia@example.test',
            '+54 9 2901 555-0101',
            'privado-empresa@example.test',
            '+54 9 2901 555-0202',
            $guideOwner->email,
            $companyOwner->email,
            'contact_email',
            '"phone"',
        ];

        $created = $this->send($token, $company, $guide)->assertCreated();
        $id = (string) $created->json('data.id');
        $responses = [
            $created,
            $this->list($token, $company, [])->assertOk(),
            $this->list($guideToken, $guide, [])->assertOk(),
            $this->show($token, $company, $id)->assertOk(),
            $this->show($guideToken, $guide, $id)->assertOk(),
            $this->cancel($token, $company, $id)->assertOk(),
        ];

        foreach ($responses as $response) {
            $raw = (string) $response->getContent();
            foreach ($secrets as $secret) {
                $this->assertStringNotContainsString($secret, $raw);
            }
        }
    }

    public function test_listing_runs_a_constant_number_of_queries_and_the_cursor_neither_skips_nor_repeats(): void
    {
        [$token, $company] = $this->actor('queries-company@example.test');
        $expected = [];
        foreach (range(1, 10) as $index) {
            $expected[] = (string) $this->send($token, $company, $this->receiver('Destino '.$index))->assertCreated()->json('data.id');
        }
        $expected = array_reverse($expected);

        $this->assertSame($this->countListQueries($token, $company, 1), $this->countListQueries($token, $company, 10));

        $walked = [];
        $cursor = null;
        do {
            $page = $this->list($token, $company, array_filter(['per_page' => 3, 'cursor' => $cursor]))->assertOk();
            $walked = [...$walked, ...array_map('strval', (array) $page->json('data.*.id'))];
            $cursor = $page->json('meta.next_cursor');
            $page->assertJsonPath('meta.per_page', 3);
        } while ($cursor !== null);
        $this->assertSame($expected, $walked);
        $this->list($token, $company, ['per_page' => 51])->assertUnprocessable();
    }

    private function freezeAt(string $instant): void
    {
        $now = CarbonImmutable::parse($instant);
        $this->travelTo($now);
        $this->clock = new FrozenClock($now);
        $this->app->instance(Clock::class, $this->clock);
    }

    /**
     * Cuenta registrada por API con un perfil propio publicado, completo y con grant.
     *
     * @param  array<string, mixed>|null  $grant
     * @return array{string, Profile, User}
     */
    private function actor(
        string $email,
        ProfileCategory $category = ProfileCategory::TourismCompany,
        ?array $grant = [],
    ): array {
        $token = (string) $this->postJson('/api/v1/auth/register', [
            'name' => 'Persona de Prueba',
            'email' => $email,
            'password' => 'Clave-segura-2026',
            'password_confirmation' => 'Clave-segura-2026',
        ])->assertCreated()->json('data.access_token');
        $owner = User::query()->where('email', $email)->firstOrFail();
        // El reloj de la app avanza días; la sesión no debe vencer por eso.
        DB::table('auth_sessions')->where('user_id', $owner->id)->update(['expires_at' => '2030-01-01 00:00:00+00']);

        $name = 'Actor '.Str::before($email, '@');

        return [$token, $this->receiver($name, $category, ['owner_user_id' => $owner->id], $grant), $owner];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>|null  $grant  null = sin grant
     */
    private function receiver(
        string $name,
        ProfileCategory $category = ProfileCategory::Guide,
        array $attributes = [],
        ?array $grant = [],
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

        return $profile;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return TestResponse<Response>
     */
    private function send(string $token, Profile $actor, Profile $target, array $overrides = [], ?string $key = null): TestResponse
    {
        $headers = $key === null ? [] : ['Idempotency-Key' => $key];

        return $this->withToken($token)->postJson('/api/v1/profiles/'.$actor->public_id.'/contact-requests', [
            'target_profile_id' => $target->public_id,
            'service_description' => 'Guía bilingüe para trekking',
            ...$overrides,
        ], $headers);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return TestResponse<Response>
     */
    private function list(string $token, Profile $actor, array $query): TestResponse
    {
        $suffix = $query === [] ? '' : '?'.http_build_query($query);

        return $this->withToken($token)->getJson('/api/v1/profiles/'.$actor->public_id.'/contact-requests'.$suffix);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<int, string>
     */
    private function listIds(string $token, Profile $actor, array $query): array
    {
        return array_map('strval', (array) $this->list($token, $actor, $query)->assertOk()->json('data.*.id'));
    }

    /** @return TestResponse<Response> */
    private function show(string $token, Profile $actor, string $id): TestResponse
    {
        return $this->withToken($token)->getJson('/api/v1/profiles/'.$actor->public_id.'/contact-requests/'.$id);
    }

    /** @return TestResponse<Response> */
    private function cancel(string $token, Profile $actor, string $id): TestResponse
    {
        return $this->withToken($token)->postJson('/api/v1/profiles/'.$actor->public_id.'/contact-requests/'.$id.'/cancel');
    }

    private function usage(Profile $profile, string $monthStart): int
    {
        return (int) DB::table('contact_usage')
            ->where('profile_id', $profile->id)
            ->where('month_start', $monthStart)
            ->value('used');
    }

    private function countListQueries(string $token, Profile $actor, int $perPage): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->list($token, $actor, ['per_page' => $perPage])->assertOk()->assertJsonCount($perPage, 'data');
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();
        DB::flushQueryLog();

        return $count;
    }
}
