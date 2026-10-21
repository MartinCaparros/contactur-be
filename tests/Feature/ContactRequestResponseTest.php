<?php

namespace Tests\Feature;

use App\Contracts\Clock;
use App\Enums\AccessGrantStatus;
use App\Enums\ProfileCategory;
use App\Enums\ProfileStatus;
use App\Models\AccessGrant;
use App\Models\AvailabilityDay;
use App\Models\City;
use App\Models\Profile;
use App\Models\ProfileContact;
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
 * T17 lote B — aceptar, rechazar, vencer y revelar contacto (R14, R15, R16, D21,
 * P22, P23, P24). Las carreras con conexiones reales están en
 * ContactRequestConcurrencyTest.
 *
 * Igual que ContactRequestCreationTest: Carbon queda en T0 (validez del JWT) y
 * solo se mueve el FrozenClock de la app.
 */
final class ContactRequestResponseTest extends TestCase
{
    use RefreshDatabase;

    private const T0 = '2026-11-10T12:00:00Z';

    private const GUIDE_EMAIL = 'contacto-guia@example.test';

    private const GUIDE_PHONE = '+54 9 2901 555-0303';

    private const COMPANY_EMAIL = 'contacto-empresa@example.test';

    private const COMPANY_PHONE = '+54 9 2901 555-0404';

    private FrozenClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->seed(CatalogSeeder::class);
        $now = CarbonImmutable::parse(self::T0);
        $this->travelTo($now);
        $this->clock = new FrozenClock($now);
        $this->app->instance(Clock::class, $this->clock);
    }

    public function test_p22_the_recipient_accepts_with_both_accesses_active_and_the_calendar_does_not_change(): void
    {
        [$token, $company] = $this->actor('accept-company@example.test');
        [$guideToken, $guide] = $this->actor('accept-guide@example.test', ProfileCategory::Guide);
        $city = City::query()->orderBy('id')->firstOrFail();
        foreach (['2026-11-20', '2026-11-21'] as $date) {
            AvailabilityDay::query()->create(['profile_id' => $guide->id, 'city_id' => $city->id, 'date' => $date]);
        }
        Profile::query()->whereKey($guide->id)->update(['availability_version' => 7]);
        $calendarBefore = $this->calendar($guide);
        $id = $this->createRequest($token, $company, $guide, [
            'requested_from' => '2026-11-20',
            'requested_to' => '2026-11-21',
            'requested_city_id' => $city->id,
        ]);

        $this->clock->set(CarbonImmutable::parse('2026-11-11T09:30:00Z'));
        $this->respond($guideToken, $guide, $id, 'accept')->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.status', 'accepted')
            ->assertJsonPath('data.responded_at', '2026-11-11T09:30:00.000000Z')
            ->assertJsonPath('data.availability_status', 'available');
        $this->assertSame('accepted', DB::table('contact_requests')->where('public_id', $id)->value('status'));

        // Aceptar no reserva ni bloquea días ni cambia la versión del calendario.
        $this->assertSame($calendarBefore, $this->calendar($guide));

        // Una sola transición: después todo es 409.
        $this->respond($guideToken, $guide, $id, 'accept')->assertStatus(409)->assertJsonPath('error.code', 'contact_request_not_pending');
        $this->respond($guideToken, $guide, $id, 'reject')->assertStatus(409)->assertJsonPath('error.code', 'contact_request_not_pending');
        $this->respond($token, $company, $id, 'cancel')->assertStatus(409)->assertJsonPath('error.code', 'contact_request_not_pending');

        // Sin body en el contrato: un motivo no se acepta y no cambia nada.
        $withReason = $this->ownedReceiver($guide, 'Guía con motivo');
        $other = $this->createRequest($token, $company, $withReason);
        $this->withToken($guideToken)
            ->postJson('/api/v1/profiles/'.$withReason->public_id.'/contact-requests/'.$other.'/reject', ['reason' => 'No'])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['fields' => ['reason']]]);
        $this->assertSame('pending', DB::table('contact_requests')->where('public_id', $other)->value('status'));
    }

    public function test_p22_sender_and_third_parties_cannot_respond(): void
    {
        [$token, $company] = $this->actor('who-company@example.test');
        [$guideToken, $guide] = $this->actor('who-guide@example.test', ProfileCategory::Guide);
        [$thirdToken, $third] = $this->actor('who-third@example.test', ProfileCategory::Guide);
        [$otherToken, $otherCompany] = $this->actor('who-other-company@example.test');
        $id = $this->createRequest($token, $company, $guide);

        foreach (['accept', 'reject'] as $action) {
            // Emisor: participante equivocado → 403, como cancelar por el destino.
            $this->respond($token, $company, $id, $action)->assertForbidden()->assertJsonPath('error.code', 'forbidden');
            // Terceros: la solicitud no existe para ellos, ni con un perfil ajeno.
            $this->respond($thirdToken, $third, $id, $action)->assertNotFound();
            $this->respond($otherToken, $otherCompany, $id, $action)->assertNotFound();
            $this->respond($thirdToken, $guide, $id, $action)->assertNotFound();
            $this->respond($guideToken, $company, $id, $action)->assertNotFound();
        }

        $this->assertSame('pending', DB::table('contact_requests')->where('public_id', $id)->value('status'));
        $this->assertNull(DB::table('contact_requests')->where('public_id', $id)->value('responded_at'));
    }

    public function test_p22_accepting_at_the_exact_expires_at_fails_although_the_job_did_not_run(): void
    {
        [$token, $company] = $this->actor('edge-company@example.test');
        [$guideToken, $guide] = $this->actor('edge-guide@example.test', ProfileCategory::Guide);
        $second = $this->ownedReceiver($guide, 'Segundo perfil');
        $atEdge = $this->createRequest($token, $company, $guide);
        $justBefore = $this->createRequest($token, $company, $second);

        // Un microsegundo antes de expires_at todavía está pendiente.
        $this->clock->set(CarbonImmutable::parse('2026-11-17T11:59:59.999999Z'));
        $this->respond($guideToken, $second, $justBefore, 'accept')->assertOk();

        $this->clock->set(CarbonImmutable::parse('2026-11-17T12:00:00Z'));
        $this->respond($guideToken, $guide, $atEdge, 'accept')->assertStatus(409)->assertJsonPath('error.code', 'contact_request_not_pending');
        $this->respond($guideToken, $guide, $atEdge, 'reject')->assertStatus(409)->assertJsonPath('error.code', 'contact_request_not_pending');
        // Nadie materializó la expiración: la base sigue en pending y el detalle dice expired.
        $this->assertSame('pending', DB::table('contact_requests')->where('public_id', $atEdge)->value('status'));
        $this->withToken($guideToken)->getJson('/api/v1/profiles/'.$guide->public_id.'/contact-requests/'.$atEdge)
            ->assertOk()
            ->assertJsonPath('data.status', 'expired');
    }

    public function test_accepting_requires_active_access_of_both_parties_and_rejecting_allows_an_expired_recipient(): void
    {
        [$token, $company, $companyOwner] = $this->actor('both-company@example.test');
        [$guideToken, $guide] = $this->actor('both-guide@example.test', ProfileCategory::Guide);

        // Emisor vencido, perfil suspendido, grant suspendido o cuenta suspendida → no se acepta.
        $senderStates = [
            'grant vencido' => fn () => AccessGrant::query()->whereKey($company->id)->update(['expires_at' => '2026-11-10 11:00:00+00']),
            'perfil suspendido' => fn () => Profile::query()->whereKey($company->id)->update(['status' => ProfileStatus::Suspended->value]),
            'grant suspendido' => fn () => AccessGrant::query()->whereKey($company->id)->update(['status' => AccessGrantStatus::Suspended->value]),
            'cuenta suspendida' => fn () => User::query()->whereKey($companyOwner->id)->update(['status' => User::STATUS_SUSPENDED]),
        ];
        foreach ($senderStates as $label => $degrade) {
            $id = $this->createRequest($token, $company, $guide);
            $degrade();
            $this->respond($guideToken, $guide, $id, 'accept')
                ->assertForbidden()
                ->assertJsonPath('error.code', 'counterpart_access_unavailable');
            $this->assertSame('pending', DB::table('contact_requests')->where('public_id', $id)->value('status'), $label);
            // Rechazar no depende del acceso del emisor.
            $this->respond($guideToken, $guide, $id, 'reject')->assertOk()->assertJsonPath('data.status', 'rejected');
            $this->restore($company, $companyOwner);
            $this->clock->set($this->clock->now()->addHours(169));
        }

        // Destino vencido: no acepta (su 403 real) pero sí rechaza (D21).
        $id = $this->createRequest($token, $company, $guide);
        AccessGrant::query()->whereKey($guide->id)->update(['expires_at' => $this->clock->now()->subMinute()->utc()->format('Y-m-d H:i:s.uP')]);
        $this->respond($guideToken, $guide, $id, 'accept')->assertForbidden()->assertJsonPath('error.code', 'subscription_expired');
        $this->respond($guideToken, $guide, $id, 'reject')->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.responded_at', $this->clock->now()->utc()->toISOString());

        // Destino con perfil suspendido: ni acepta ni rechaza.
        AccessGrant::query()->whereKey($guide->id)->update(['expires_at' => null]);
        $this->clock->set($this->clock->now()->addHours(169));
        $id = $this->createRequest($token, $company, $guide);
        Profile::query()->whereKey($guide->id)->update(['status' => ProfileStatus::Suspended->value]);
        $this->respond($guideToken, $guide, $id, 'accept')->assertForbidden()->assertJsonPath('error.code', 'profile_suspended');
        $this->respond($guideToken, $guide, $id, 'reject')->assertForbidden()->assertJsonPath('error.code', 'profile_suspended');
        $this->assertSame('pending', DB::table('contact_requests')->where('public_id', $id)->value('status'));
    }

    public function test_p23_the_expiration_job_is_idempotent_and_only_touches_due_pending_requests(): void
    {
        [$token, $company] = $this->actor('job-company@example.test');
        [$guideToken, $guide] = $this->actor('job-guide@example.test', ProfileCategory::Guide);
        $dueA = $this->createRequest($token, $company, $this->receiver('Vence A'));
        $dueB = $this->createRequest($token, $company, $this->receiver('Vence B'));
        $accepted = $this->createRequest($token, $company, $guide);
        $this->respond($guideToken, $guide, $accepted, 'accept')->assertOk();
        $cancelled = $this->createRequest($token, $company, $this->receiver('Cancelada'));
        $this->respond($token, $company, $cancelled, 'cancel')->assertOk();
        $this->clock->set(CarbonImmutable::parse('2026-11-12T12:00:00Z'));
        $notDue = $this->createRequest($token, $company, $this->receiver('Todavía vigente'));

        // expires_at exacto de las primeras: vencen; la creada después no.
        $this->clock->set(CarbonImmutable::parse('2026-11-17T12:00:00Z'));
        $this->artisan('contactur:expire-contact-requests', ['--batch' => 1])
            ->expectsOutputToContain('Solicitudes marcadas como expired: 2.')
            ->assertSuccessful();
        $snapshot = DB::table('contact_requests')->orderBy('id')->get(['public_id', 'status', 'updated_at', 'responded_at'])->toArray();
        $statuses = DB::table('contact_requests')->pluck('status', 'public_id')->all();
        $this->assertSame('expired', $statuses[$dueA]);
        $this->assertSame('expired', $statuses[$dueB]);
        $this->assertSame('accepted', $statuses[$accepted]);
        $this->assertSame('cancelled', $statuses[$cancelled]);
        $this->assertSame('pending', $statuses[$notDue]);

        // Reintento (aun con el reloj más adelante para las ya vencidas): no cambia nada.
        $this->artisan('contactur:expire-contact-requests')
            ->expectsOutputToContain('Solicitudes marcadas como expired: 0.')
            ->assertSuccessful();
        $this->assertEquals($snapshot, DB::table('contact_requests')->orderBy('id')->get(['public_id', 'status', 'updated_at', 'responded_at'])->toArray());
        $this->artisan('contactur:expire-contact-requests', ['--batch' => '0'])->assertFailed();

        // El historial sigue mostrando el mismo estado efectivo antes y después del job.
        $this->withToken($token)->getJson('/api/v1/profiles/'.$company->public_id.'/contact-requests?status=expired')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_p24_accepted_reveals_both_declared_contacts_only_to_enabled_participants(): void
    {
        [$token, $company, $companyOwner] = $this->actor('reveal-company@example.test');
        [$guideToken, $guide, $guideOwner] = $this->actor('reveal-guide@example.test', ProfileCategory::Guide);
        [$thirdToken, $third] = $this->actor('reveal-third@example.test', ProfileCategory::Guide);
        $this->declareContacts($company, $guide);
        $id = $this->createRequest($token, $company, $guide);

        // Antes de aceptar: no hay contacto para nadie.
        $this->contact($token, $company, $id)->assertStatus(409)->assertJsonPath('error.code', 'contact_request_not_accepted');
        $this->contact($guideToken, $guide, $id)->assertStatus(409)->assertJsonPath('error.code', 'contact_request_not_accepted');
        $this->respond($guideToken, $guide, $id, 'accept')->assertOk();

        $forGuide = $this->contact($guideToken, $guide, $id)->assertOk()
            ->assertJsonPath('data.contact_request_id', $id)
            ->assertJsonPath('data.actor.profile_id', $guide->public_id)
            ->assertJsonPath('data.actor.display_name', $guide->display_name)
            ->assertJsonPath('data.actor.contact_email', self::GUIDE_EMAIL)
            ->assertJsonPath('data.actor.phone', self::GUIDE_PHONE)
            ->assertJsonPath('data.counterpart.profile_id', $company->public_id)
            ->assertJsonPath('data.counterpart.contact_email', self::COMPANY_EMAIL)
            ->assertJsonPath('data.counterpart.phone', self::COMPANY_PHONE);
        $this->assertStringContainsString('no-store', (string) $forGuide->headers->get('Cache-Control'));
        $this->assertSame(['data'], array_keys((array) $forGuide->json()));
        $this->assertSame(['contact_request_id', 'actor', 'counterpart'], array_keys((array) $forGuide->json('data')));
        $this->assertSame(['profile_id', 'display_name', 'contact_email', 'phone'], array_keys((array) $forGuide->json('data.actor')));
        // Nunca el email de login.
        $this->assertStringNotContainsString($guideOwner->email, (string) $forGuide->getContent());
        $this->assertStringNotContainsString($companyOwner->email, (string) $forGuide->getContent());

        $this->contact($token, $company, $id)->assertOk()
            ->assertJsonPath('data.actor.profile_id', $company->public_id)
            ->assertJsonPath('data.actor.contact_email', self::COMPANY_EMAIL)
            ->assertJsonPath('data.counterpart.profile_id', $guide->public_id)
            ->assertJsonPath('data.counterpart.phone', self::GUIDE_PHONE);

        // Terceros: 404 con su perfil o con uno ajeno.
        $this->contact($thirdToken, $third, $id)->assertNotFound();
        $this->contact($thirdToken, $guide, $id)->assertNotFound();

        // Participante vencido: 403 real para él, counterpart_access_unavailable para el otro.
        AccessGrant::query()->whereKey($guide->id)->update(['expires_at' => '2026-11-10 11:00:00+00']);
        $this->contact($guideToken, $guide, $id)->assertForbidden()->assertJsonPath('error.code', 'subscription_expired');
        $this->contact($token, $company, $id)->assertForbidden()->assertJsonPath('error.code', 'counterpart_access_unavailable');
        AccessGrant::query()->whereKey($guide->id)->update(['expires_at' => null]);

        // Perfil suspendido.
        Profile::query()->whereKey($company->id)->update(['status' => ProfileStatus::Suspended->value]);
        $this->contact($token, $company, $id)->assertForbidden()->assertJsonPath('error.code', 'profile_suspended');
        $this->contact($guideToken, $guide, $id)->assertForbidden()->assertJsonPath('error.code', 'counterpart_access_unavailable');
        Profile::query()->whereKey($company->id)->update(['status' => ProfileStatus::Published->value]);

        // Cuenta suspendida.
        User::query()->whereKey($guideOwner->id)->update(['status' => User::STATUS_SUSPENDED]);
        $this->contact($guideToken, $guide, $id)->assertForbidden()->assertJsonPath('error.code', 'account_suspended');
        $this->contact($token, $company, $id)->assertForbidden()->assertJsonPath('error.code', 'counterpart_access_unavailable');
        User::query()->whereKey($guideOwner->id)->update(['status' => User::STATUS_ACTIVE]);

        $this->contact($token, $company, $id)->assertOk();
    }

    public function test_rejected_expired_and_cancelled_requests_never_reveal_contact(): void
    {
        [$token, $company] = $this->actor('closed-company@example.test');
        [$guideToken, $guide] = $this->actor('closed-guide@example.test', ProfileCategory::Guide);
        $this->declareContacts($company, $guide);

        $rejected = $this->createRequest($token, $company, $guide);
        $this->respond($guideToken, $guide, $rejected, 'reject')->assertOk();
        $second = $this->ownedReceiver($guide, 'Guía cancelada');
        $third = $this->ownedReceiver($guide, 'Guía vencida');
        $cancelled = $this->createRequest($token, $company, $second);
        $this->respond($token, $company, $cancelled, 'cancel')->assertOk();
        $expired = $this->createRequest($token, $company, $third);

        $this->clock->set(CarbonImmutable::parse('2026-11-17T12:00:00Z'));
        $cases = [[$rejected, $guide], [$cancelled, $second], [$expired, $third]];
        foreach ($cases as [$id, $recipient]) {
            $this->contact($token, $company, $id)->assertStatus(409)->assertJsonPath('error.code', 'contact_request_not_accepted');
            $this->contact($guideToken, $recipient, $id)->assertStatus(409)->assertJsonPath('error.code', 'contact_request_not_accepted');
        }
        // También después de materializar la expiración.
        $this->artisan('contactur:expire-contact-requests')->assertSuccessful();
        $this->contact($guideToken, $third, $expired)->assertStatus(409);
    }

    public function test_no_json_outside_the_contact_endpoint_contains_declared_contact_values(): void
    {
        [$token, $company] = $this->actor('leak-company@example.test');
        [$guideToken, $guide] = $this->actor('leak-guide@example.test', ProfileCategory::Guide);
        $this->declareContacts($company, $guide);
        $other = $this->ownedReceiver($guide, 'Guía que rechaza');
        $accepted = $this->createRequest($token, $company, $guide);
        $rejected = $this->createRequest($token, $company, $other);
        $secrets = [self::GUIDE_EMAIL, self::GUIDE_PHONE, self::COMPANY_EMAIL, self::COMPANY_PHONE, 'contact_email', '"phone"'];

        $responses = [
            $this->respond($guideToken, $guide, $accepted, 'accept')->assertOk(),
            $this->respond($guideToken, $other, $rejected, 'reject')->assertOk(),
            $this->withToken($token)->getJson('/api/v1/profiles/'.$company->public_id.'/contact-requests')->assertOk(),
            $this->withToken($guideToken)->getJson('/api/v1/profiles/'.$guide->public_id.'/contact-requests')->assertOk(),
            $this->withToken($token)->getJson('/api/v1/profiles/'.$company->public_id.'/contact-requests/'.$accepted)->assertOk(),
            $this->withToken($guideToken)->getJson('/api/v1/profiles/'.$guide->public_id.'/contact-requests/'.$accepted)->assertOk(),
            $this->contact($guideToken, $other, $rejected)->assertStatus(409),
        ];
        foreach ($responses as $response) {
            foreach ($secrets as $secret) {
                $this->assertStringNotContainsString($secret, (string) $response->getContent());
            }
        }

        $this->assertStringContainsString(self::COMPANY_EMAIL, (string) $this->contact($guideToken, $guide, $accepted)->assertOk()->getContent());
    }

    private function declareContacts(Profile $company, Profile $guide): void
    {
        ProfileContact::query()->whereKey($guide->id)->update(['contact_email' => self::GUIDE_EMAIL, 'phone' => self::GUIDE_PHONE]);
        ProfileContact::query()->whereKey($company->id)->update(['contact_email' => self::COMPANY_EMAIL, 'phone' => self::COMPANY_PHONE]);
    }

    private function restore(Profile $company, User $owner): void
    {
        AccessGrant::query()->whereKey($company->id)->update(['expires_at' => null, 'status' => AccessGrantStatus::Active->value]);
        Profile::query()->whereKey($company->id)->update(['status' => ProfileStatus::Published->value]);
        User::query()->whereKey($owner->id)->update(['status' => User::STATUS_ACTIVE]);
    }

    /** @return array{days: array<int, array<string, mixed>>, version: int} */
    private function calendar(Profile $profile): array
    {
        return [
            'days' => DB::table('availability_days')->where('profile_id', $profile->id)->orderBy('date')->get()
                ->map(static fn (object $row): array => (array) $row)->all(),
            'version' => (int) DB::table('profiles')->where('id', $profile->id)->value('availability_version'),
        ];
    }

    /**
     * Cuenta registrada por API con un perfil propio publicado, completo y con grant.
     *
     * @return array{string, Profile, User}
     */
    private function actor(string $email, ProfileCategory $category = ProfileCategory::TourismCompany): array
    {
        $token = (string) $this->postJson('/api/v1/auth/register', [
            'name' => 'Persona de Prueba',
            'email' => $email,
            'password' => 'Clave-segura-2026',
            'password_confirmation' => 'Clave-segura-2026',
        ])->assertCreated()->json('data.access_token');
        $owner = User::query()->where('email', $email)->firstOrFail();
        DB::table('auth_sessions')->where('user_id', $owner->id)->update(['expires_at' => '2030-01-01 00:00:00+00']);

        return [$token, $this->receiver('Actor '.Str::before($email, '@'), $category, $owner), $owner];
    }

    /** Otro perfil receptor de la misma cuenta que $sibling (mismo token). */
    private function ownedReceiver(Profile $sibling, string $name): Profile
    {
        $owner = User::query()->findOrFail($sibling->owner_user_id);

        return $this->receiver($name, ProfileCategory::Guide, $owner);
    }

    private function receiver(string $name, ProfileCategory $category = ProfileCategory::Guide, ?User $owner = null): Profile
    {
        $profile = Profile::factory()->create(array_merge([
            'category' => $category,
            'display_name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(8)),
            'status' => ProfileStatus::Published,
            'onboarding_step' => 4,
            'completed_at' => CarbonImmutable::parse('2026-10-01T12:00:00Z'),
        ], $owner === null ? [] : ['owner_user_id' => $owner->id]));
        AccessGrant::query()->create([
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
        ]);

        return $profile;
    }

    /** @param array<string, mixed> $overrides */
    private function createRequest(string $token, Profile $sender, Profile $target, array $overrides = []): string
    {
        return (string) $this->withToken($token)->postJson('/api/v1/profiles/'.$sender->public_id.'/contact-requests', [
            'target_profile_id' => $target->public_id,
            'service_description' => 'Guía bilingüe para trekking',
            ...$overrides,
        ])->assertCreated()->json('data.id');
    }

    /** @return TestResponse<Response> */
    private function respond(string $token, Profile $actor, string $id, string $action): TestResponse
    {
        return $this->withToken($token)->postJson('/api/v1/profiles/'.$actor->public_id.'/contact-requests/'.$id.'/'.$action);
    }

    /** @return TestResponse<Response> */
    private function contact(string $token, Profile $actor, string $id): TestResponse
    {
        return $this->withToken($token)->getJson('/api/v1/profiles/'.$actor->public_id.'/contact-requests/'.$id.'/contact');
    }
}
