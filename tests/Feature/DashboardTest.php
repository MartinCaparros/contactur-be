<?php

namespace Tests\Feature;

use App\Enums\ContactRequestStatus;
use App\Enums\DashboardMetricKey;
use App\Enums\NotificationType;
use App\Enums\ProfileCategory;
use App\Enums\ProfileStatus;
use App\Models\AccessGrant;
use App\Models\City;
use App\Models\ContactRequest;
use App\Models\Profile;
use App\Models\ProfileNotification;
use App\Models\Reference;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use stdClass;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\ReferenceFixtures;
use Tests\TestCase;

/**
 * T21 lote A — GET /profiles/{profile}/dashboard: KPIs del perfil actor (R05/P27),
 * estado efectivo de solicitudes, visibilidad de notificaciones y referencias,
 * días futuros en fecha de negocio, permisos D21 y consultas acotadas.
 *
 * T0 = 01:30 UTC del 11/11 = 22:30 del 10/11 en Buenos Aires: "hoy" de negocio es
 * el 10/11 aunque en UTC ya sea 11/11.
 */
final class DashboardTest extends TestCase
{
    use ReferenceFixtures;
    use RefreshDatabase;

    private const T0 = '2026-11-11T01:30:00Z';

    private const PAST = '2026-11-01 12:00:00+00';

    private const FUTURE = '2026-11-20 12:00:00+00';

    private const CAPABILITY_KEYS = [
        'can_search',
        'can_send_contact_requests',
        'can_publish_availability',
        'can_receive_contact_requests',
        'can_use_gallery',
        'can_view_history',
        'can_reject_pending_requests',
    ];

    private const ACCESS_KEYS = [
        'profile_id',
        'effective_state',
        'allowed',
        'denial_code',
        'grant',
        'capabilities',
        'contact_quota',
        'evaluated_at',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Mail::fake();
        $this->seed(CatalogSeeder::class);
        $this->freezeReferenceClock(self::T0);
    }

    public function test_metrics_are_scoped_to_the_actor_profile_by_effective_status(): void
    {
        [$token, $guide, $owner] = $this->actor('dash-guide@example.test', ProfileCategory::Guide);
        $company = $this->siblingProfile($guide, 'Empresa Hermana', ProfileCategory::TourismCompany);
        [$x1, $x2, $x3, $x4, $x5, $x6] = array_map(
            fn (int $i): Profile => $this->enabledProfile('Empresa Externa '.$i, ProfileCategory::TourismCompany),
            range(1, 6),
        );
        $otherGuide = $this->enabledProfile('Guía Externa', ProfileCategory::Guide);
        $otherGuide2 = $this->enabledProfile('Guía Externa Dos', ProfileCategory::Guide);

        // Solicitudes recibidas por la guía (estado persistido → efectivo).
        $this->request($x1, $guide, ContactRequestStatus::Pending, self::FUTURE);              // pending
        $this->request($x6, $guide, ContactRequestStatus::Pending, '2026-11-11 01:30:01+00');  // pending (vence en 1 s)
        $this->request($x2, $guide, ContactRequestStatus::Pending, self::PAST);                // expired por reloj
        $this->request($x3, $guide, ContactRequestStatus::Pending, '2026-11-11 01:30:00+00');  // expired: expires_at == now
        $this->request($x3, $guide, ContactRequestStatus::Expired, self::PAST);                // expired materializada
        $accepted = $this->request($x4, $guide, ContactRequestStatus::Accepted, self::PAST);   // accepted
        $accepted2 = $this->request($x2, $guide, ContactRequestStatus::Accepted, self::PAST);  // accepted
        $rejected = $this->request($x5, $guide, ContactRequestStatus::Rejected, self::PAST);   // rejected
        $this->request($x1, $guide, ContactRequestStatus::Cancelled, self::PAST);              // no se informa
        // El perfil hermano (misma cuenta) envía: no debe filtrarse a la guía.
        $this->request($company, $otherGuide, ContactRequestStatus::Pending, self::FUTURE);
        $this->request($company, $otherGuide2, ContactRequestStatus::Accepted, self::PAST);
        $this->request($company, $otherGuide2, ContactRequestStatus::Pending, self::PAST);
        // Ajena por completo.
        $this->request($x5, $otherGuide, ContactRequestStatus::Rejected, self::PAST);

        // Notificaciones: la bienvenida de cuenta (registro) ya está sin leer.
        $this->assertSame(1, ProfileNotification::query()->where('user_id', $owner->id)->count());
        $this->notification($owner, $guide, false);
        $this->notification($owner, $guide, false);
        $this->notification($owner, $guide, true);
        $this->notification($owner, $company, false);
        $this->notification($owner, null, true);
        $this->notification(User::query()->findOrFail($otherGuide->owner_user_id), $otherGuide, false);

        // Referencias recibidas por la guía: solo visibles y no moderadas.
        $this->reference($accepted, $x4, $guide, Reference::VISIBILITY_VISIBLE);
        $this->reference($accepted2, $x2, $guide, Reference::VISIBILITY_VISIBLE);
        $this->reference($rejected, $x5, $guide, Reference::VISIBILITY_HIDDEN);
        $this->reference($accepted2, $x6, $guide, Reference::VISIBILITY_VISIBLE, moderatedBy: $owner);
        $this->reference($accepted, $guide, $x4, Reference::VISIBILITY_VISIBLE);   // emitida: no cuenta
        $this->reference($accepted, $x4, $company, Reference::VISIBILITY_VISIBLE); // del hermano: no cuenta

        // Calendario: solo fechas posteriores al "hoy" de Buenos Aires (10/11).
        $cityId = (int) City::query()->value('id');
        foreach (['2026-11-09', '2026-11-10', '2026-11-11', '2026-11-20', '2026-12-05'] as $date) {
            $this->availabilityDay($guide, $cityId, $date);
        }
        $this->availabilityDay($otherGuide, $cityId, '2026-11-25');

        $this->assertSame([
            ['key' => 'pending_contact_requests', 'value' => 2],
            ['key' => 'accepted_contact_requests', 'value' => 2],
            ['key' => 'rejected_contact_requests', 'value' => 1],
            ['key' => 'expired_contact_requests', 'value' => 3],
            ['key' => 'unread_notifications', 'value' => 3],
            ['key' => 'visible_references', 'value' => 2],
            ['key' => 'available_future_days', 'value' => 3],
        ], $this->dashboard($token, $guide)->assertOk()->json('data.metrics'));

        // Mismo token, perfil hermano: solo lo suyo; tourism_company omite el calendario.
        $this->assertSame([
            ['key' => 'pending_contact_requests', 'value' => 1],
            ['key' => 'accepted_contact_requests', 'value' => 1],
            ['key' => 'rejected_contact_requests', 'value' => 0],
            ['key' => 'expired_contact_requests', 'value' => 1],
            ['key' => 'unread_notifications', 'value' => 2],
            ['key' => 'visible_references', 'value' => 1],
        ], $this->dashboard($token, $company)->assertOk()->json('data.metrics'));

        // Leer la bienvenida desde un perfil la descuenta en ambos (se lee una sola vez).
        $welcome = (string) ProfileNotification::query()
            ->where('user_id', $owner->id)
            ->where('type', NotificationType::Welcome->value)
            ->value('public_id');
        $this->withToken($token)
            ->postJson('/api/v1/profiles/'.$company->public_id.'/notifications/'.$welcome.'/read')
            ->assertOk();
        $this->assertSame(2, $this->metric($this->dashboard($token, $guide), DashboardMetricKey::UnreadNotifications));
        $this->assertSame(1, $this->metric($this->dashboard($token, $company), DashboardMetricKey::UnreadNotifications));
    }

    public function test_response_shape_matches_the_contract_and_reuses_the_access_serializer(): void
    {
        [$token, $guide] = $this->actor('shape-guide@example.test', ProfileCategory::Driver);
        [$companyToken, $company] = $this->actor('shape-company@example.test', ProfileCategory::TourismCompany);
        $keys = array_map(static fn (DashboardMetricKey $key): string => $key->value, DashboardMetricKey::cases());

        foreach ([[$token, $guide, $keys], [$companyToken, $company, array_slice($keys, 0, 6)]] as [$actorToken, $profile, $expectedKeys]) {
            $response = $this->dashboard($actorToken, $profile)->assertOk();
            $payload = json_decode((string) $response->getContent(), false, 512, JSON_THROW_ON_ERROR);
            $this->assertInstanceOf(stdClass::class, $payload);
            $this->assertSame(['data'], array_keys((array) $payload));
            $this->assertSame(['profile_id', 'metrics', 'capabilities', 'access'], array_keys((array) $payload->data));
            $this->assertSame($profile->public_id, $payload->data->profile_id);
            $this->assertIsArray($payload->data->metrics);

            $seen = [];
            foreach ($payload->data->metrics as $metric) {
                $this->assertInstanceOf(stdClass::class, $metric);
                $this->assertSame(['key', 'value'], array_keys((array) $metric));
                $this->assertContains($metric->key, $keys);
                $this->assertIsInt($metric->value);
                $this->assertGreaterThanOrEqual(0, $metric->value);
                $seen[] = $metric->key;
            }
            $this->assertSame($expectedKeys, $seen);

            $this->assertInstanceOf(stdClass::class, $payload->data->capabilities);
            $this->assertSame(self::CAPABILITY_KEYS, array_keys((array) $payload->data->capabilities));
            $this->assertSame(self::ACCESS_KEYS, array_keys((array) $payload->data->access));

            // Idéntico a GET /access (mismo instante con el reloj congelado).
            $access = $this->withToken($actorToken)->getJson('/api/v1/profiles/'.$profile->public_id.'/access')->assertOk();
            $this->assertSame($access->json('data'), $response->json('data.access'));
            $this->assertSame($access->json('data.capabilities'), $response->json('data.capabilities'));
        }

        // La empresa trae contact_quota y can_send_contact_requests; el receptor no.
        $this->dashboard($companyToken, $company)
            ->assertJsonPath('data.capabilities.can_send_contact_requests', true)
            ->assertJsonPath('data.access.contact_quota.used', 0);
        $this->dashboard($token, $guide)
            ->assertJsonPath('data.capabilities.can_publish_availability', true)
            ->assertJsonPath('data.access.contact_quota', null);
    }

    public function test_expired_or_suspended_profiles_still_read_their_dashboard(): void
    {
        [$token, $guide] = $this->actor('expired-guide@example.test', ProfileCategory::Instructor);
        $this->request($this->enabledProfile('Empresa Vencida', ProfileCategory::TourismCompany), $guide, ContactRequestStatus::Pending, self::FUTURE);
        AccessGrant::query()->where('profile_id', $guide->id)->update(['expires_at' => self::PAST]);

        $expired = $this->dashboard($token, $guide)->assertOk()
            ->assertJsonPath('data.access.effective_state', 'expired')
            ->assertJsonPath('data.access.allowed', false)
            ->assertJsonPath('data.access.denial_code', 'subscription_expired')
            ->assertJsonPath('data.capabilities.can_view_history', true)
            ->assertJsonPath('data.capabilities.can_publish_availability', false);
        $this->assertSame(1, $this->metric($expired, DashboardMetricKey::PendingContactRequests));
        $this->assertSame(0, $this->metric($expired, DashboardMetricKey::AvailableFutureDays));

        Profile::query()->whereKey($guide->id)->update(['status' => ProfileStatus::Suspended->value]);
        $this->dashboard($token, $guide)->assertOk()
            ->assertJsonPath('data.access.effective_state', 'suspended')
            ->assertJsonPath('data.access.denial_code', 'profile_suspended')
            ->assertJsonPath('data.capabilities.can_reject_pending_requests', false)
            ->assertJsonPath('data.metrics.0.value', 1);
    }

    public function test_a_suspended_account_is_blocked_exactly_like_the_notification_center(): void
    {
        [$token, $guide, $owner] = $this->actor('suspended-account-dash@example.test', ProfileCategory::Guide);
        $owner->forceFill(['status' => User::STATUS_SUSPENDED])->save();

        $this->dashboard($token, $guide)->assertForbidden()->assertJsonPath('error.code', 'account_suspended');
        $this->withToken($token)
            ->getJson('/api/v1/profiles/'.$guide->public_id.'/notifications')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'account_suspended');
    }

    public function test_only_the_owner_can_read_the_dashboard(): void
    {
        [$token, $guide] = $this->actor('owner-dash@example.test', ProfileCategory::Guide);
        [$otherToken] = $this->actor('stranger-dash@example.test', ProfileCategory::TourismCompany);

        $this->dashboard($otherToken, $guide)->assertNotFound()->assertJsonPath('error.code', 'not_found');
        $this->withToken($token)
            ->getJson('/api/v1/profiles/'.Str::ulid().'/dashboard')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
        $this->withToken($token)->getJson('/api/v1/profiles/no-es-un-ulid/dashboard')->assertNotFound();
        $this->withoutToken()
            ->getJson('/api/v1/profiles/'.$guide->public_id.'/dashboard')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'unauthorized');
    }

    public function test_query_count_is_bounded_and_independent_of_volume(): void
    {
        [$token, $guide, $owner] = $this->actor('queries-guide@example.test', ProfileCategory::Guide);
        [$companyToken, $company] = $this->actor('queries-company@example.test', ProfileCategory::TourismCompany);

        $empty = $this->countQueries(fn () => $this->dashboard($token, $guide)->assertOk());

        $cityId = (int) City::query()->value('id');
        foreach (range(1, 8) as $i) {
            $sender = $this->enabledProfile('Empresa Volumen '.$i, ProfileCategory::TourismCompany);
            $requestId = $this->request($sender, $guide, ContactRequestStatus::Accepted, self::PAST);
            $this->request($sender, $guide, ContactRequestStatus::Pending, $i % 2 === 0 ? self::FUTURE : self::PAST);
            $this->reference($requestId, $sender, $guide, Reference::VISIBILITY_VISIBLE);
            $this->notification($owner, $guide, false);
            $this->availabilityDay($guide, $cityId, '2026-12-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT));
            $this->request($company, $this->enabledProfile('Guía Volumen '.$i, ProfileCategory::Guide), ContactRequestStatus::Pending, self::FUTURE);
        }

        $loaded = $this->countQueries(fn () => $this->dashboard($token, $guide)->assertOk()
            ->assertJsonPath('data.metrics.0.value', 4)
            ->assertJsonPath('data.metrics.1.value', 8)
            ->assertJsonPath('data.metrics.3.value', 4)
            ->assertJsonPath('data.metrics.4.value', 9)
            ->assertJsonPath('data.metrics.5.value', 8)
            ->assertJsonPath('data.metrics.6.value', 8));
        $this->assertSame($empty, $loaded);
        // Sesión JWT + usuario, perfil, grant, 1 COUNT … FILTER de solicitudes y
        // 1 COUNT por notificaciones, referencias y calendario.
        $this->assertSame(8, $loaded);

        // Empresa: sin calendario, pero la evaluación de acceso lee la cuota del mes
        // (chequeo de tabla + fila de contact_usage, código existente de AccessService).
        $companyQueries = $this->countQueries(fn () => $this->dashboard($companyToken, $company)->assertOk()
            ->assertJsonPath('data.metrics.0.value', 8));
        $this->assertSame(9, $companyQueries);
    }

    /** @return TestResponse<Response> */
    private function dashboard(string $token, Profile $profile): TestResponse
    {
        return $this->withToken($token)->getJson('/api/v1/profiles/'.$profile->public_id.'/dashboard');
    }

    /** @param TestResponse<Response> $response */
    private function metric(TestResponse $response, DashboardMetricKey $key): ?int
    {
        foreach ((array) $response->assertOk()->json('data.metrics') as $metric) {
            if ($metric['key'] === $key->value) {
                return (int) $metric['value'];
            }
        }

        return null;
    }

    /** Fila directa: el dashboard solo lee, así que no hace falta pasar por la API. */
    private function request(Profile $sender, Profile $recipient, ContactRequestStatus $status, string $expiresAt): int
    {
        return (int) DB::table('contact_requests')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'sender_profile_id' => $sender->id,
            'recipient_profile_id' => $recipient->id,
            'service_description' => 'Guía bilingüe para trekking',
            'status' => $status->value,
            'availability_status' => ContactRequest::AVAILABILITY_UNSPECIFIED,
            'expires_at' => $expiresAt,
            'cancelled_at' => $status === ContactRequestStatus::Cancelled ? self::PAST : null,
            'responded_at' => in_array($status, [ContactRequestStatus::Accepted, ContactRequestStatus::Rejected], true) ? self::PAST : null,
            'created_at' => '2026-10-25 12:00:00+00',
            'updated_at' => '2026-10-25 12:00:00+00',
        ]);
    }

    private function notification(User $user, ?Profile $profile, bool $read): void
    {
        DB::table('profile_notifications')->insert([
            'public_id' => (string) Str::ulid(),
            'user_id' => $user->id,
            'profile_id' => $profile?->id,
            'type' => NotificationType::AdministrativeChange->value,
            'safe_data' => '{"change":"access_updated"}',
            'dedupe_key' => 'dashboard-test:'.Str::ulid(),
            'email_status' => ProfileNotification::EMAIL_NOT_APPLICABLE,
            'read_at' => $read ? '2026-11-05 12:00:00+00' : null,
            'created_at' => '2026-11-05 12:00:00+00',
        ]);
    }

    private function reference(int $requestId, Profile $author, Profile $subject, string $visibility, ?User $moderatedBy = null): void
    {
        DB::table('references')->insert([
            'public_id' => (string) Str::ulid(),
            'contact_request_id' => $requestId,
            'author_profile_id' => $author->id,
            'subject_profile_id' => $subject->id,
            'body' => 'La coordinación de la propuesta fue clara y puntual.',
            'visibility' => $visibility,
            'moderated_at' => $moderatedBy !== null ? '2026-11-06 12:00:00+00' : null,
            'moderated_by' => $moderatedBy?->id,
            'moderation_reason' => $moderatedBy !== null ? 'Contenido contrario a las reglas' : null,
            'created_at' => '2026-11-05 12:00:00+00',
            'updated_at' => '2026-11-05 12:00:00+00',
        ]);
    }

    private function availabilityDay(Profile $profile, int $cityId, string $date): void
    {
        DB::table('availability_days')->insert([
            'profile_id' => $profile->id,
            'city_id' => $cityId,
            'date' => $date,
            'created_at' => '2026-11-01 12:00:00+00',
            'updated_at' => '2026-11-01 12:00:00+00',
        ]);
    }

    private function countQueries(callable $request): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $request();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();
        DB::flushQueryLog();

        return $count;
    }
}
