<?php

namespace Tests\Feature;

use App\Contracts\Clock;
use App\Enums\AccessGrantStatus;
use App\Enums\NotificationType;
use App\Enums\ProfileCategory;
use App\Enums\ProfileStatus;
use App\Events\NotificationCreated;
use App\Jobs\SendNotificationEmail;
use App\Mail\ProfileNotificationMail;
use App\Models\AccessGrant;
use App\Models\City;
use App\Models\DocumentType;
use App\Models\Profile;
use App\Models\ProfileContact;
use App\Models\ProfileNotification;
use App\Models\User;
use App\Notifications\EmailVerificationNotification;
use App\Notifications\EmailVerifiedNotification;
use App\Services\ContactRequestService;
use App\Services\NotificationService;
use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\FrozenClock;
use Tests\Support\SyntheticUploads;
use Tests\TestCase;

/**
 * T19 lote A — outbox de notificaciones (R18, P23, P26): una fila in-app por
 * evento aunque se reintente, correo solo si aplica (preferencias / esenciales),
 * sin datos de contacto, y nada sale si la transacción de negocio hace rollback.
 */
final class NotificationDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private const T0 = '2026-11-10T12:00:00Z';

    private const COMPANY_CONTACT_EMAIL = 'contacto-privado-empresa@example.test';

    private const COMPANY_PHONE = '+54 9 2901 555-0404';

    private FrozenClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Mail::fake();
        $this->seed(CatalogSeeder::class);
        $now = CarbonImmutable::parse(self::T0);
        $this->travelTo($now);
        $this->clock = new FrozenClock($now);
        $this->app->instance(Clock::class, $this->clock);
    }

    protected function tearDown(): void
    {
        SyntheticUploads::cleanup();
        parent::tearDown();
    }

    public function test_p26_a_new_request_notifies_the_recipient_once_even_on_idempotent_replay_and_the_email_has_no_contact_data(): void
    {
        [$companyToken, $company, $companyOwner] = $this->actor('p26-company@example.test', ProfileCategory::TourismCompany, 'Andes Viajes Ficticios');
        [$guideToken, $guide, $guideOwner] = $this->actor('p26-guide@example.test', ProfileCategory::Guide, 'Guía Ficticia');
        ProfileContact::query()->whereKey($company->id)->update(['contact_email' => self::COMPANY_CONTACT_EMAIL, 'phone' => self::COMPANY_PHONE]);
        $city = City::query()->orderBy('id')->firstOrFail();
        $payload = [
            'target_profile_id' => $guide->public_id,
            'service_description' => 'Guiado bilingüe para grupo de trekking',
            'requested_from' => '2026-11-20',
            'requested_to' => '2026-11-22',
            'requested_city_id' => $city->id,
        ];

        $first = $this->withToken($companyToken)->withHeader('Idempotency-Key', 'p26-request-key-0001')
            ->postJson('/api/v1/profiles/'.$company->public_id.'/contact-requests', $payload)
            ->assertCreated();
        $requestId = (string) $first->json('data.id');
        $this->withToken($companyToken)->withHeader('Idempotency-Key', 'p26-request-key-0001')
            ->postJson('/api/v1/profiles/'.$company->public_id.'/contact-requests', $payload)
            ->assertCreated()
            ->assertHeader('Idempotency-Replayed', 'true');

        $rows = ProfileNotification::query()->where('type', NotificationType::ContactRequestReceived->value)->get();
        $this->assertCount(1, $rows);
        $row = $rows->firstOrFail();
        $this->assertSame('contact_request_received:'.$requestId, $row->dedupe_key);
        $this->assertSame($guide->id, $row->profile_id);
        $this->assertSame($guideOwner->id, $row->user_id);
        $this->assertSame(ProfileNotification::EMAIL_SENT, $row->email_status);
        $this->assertNotNull($row->emailed_at);
        // jsonb no conserva el orden de claves: comparación sin orden.
        $this->assertEquals([
            'profile_id' => $guide->public_id,
            'contact_request_id' => $requestId,
            'role' => 'recipient',
            'counterpart_profile_id' => $company->public_id,
            'counterpart_display_name' => 'Andes Viajes Ficticios',
            'counterpart_category' => 'tourism_company',
        ], $row->safe_data);

        Mail::assertSent(ProfileNotificationMail::class, 1);
        Mail::assertSent(ProfileNotificationMail::class, function (ProfileNotificationMail $mail) use ($guideOwner, $requestId, $guide, $companyOwner, $city): bool {
            $html = $mail->render();
            $returnTo = rawurlencode('/app/requests/'.$requestId.'?profile='.$guide->public_id);
            $this->assertStringContainsString('Andes Viajes Ficticios', $html);
            $this->assertStringContainsString('Empresa turística', $html);
            $this->assertStringContainsString('Guiado bilingüe para grupo de trekking', $html);
            $this->assertStringContainsString('20/11/2026', $html);
            $this->assertStringContainsString(e($city->name), $html);
            $this->assertStringContainsString(e(config('contactur.frontend_url').'/?auth=login&returnTo='.$returnTo), $html);
            foreach ([self::COMPANY_CONTACT_EMAIL, self::COMPANY_PHONE, $companyOwner->email] as $private) {
                $this->assertStringNotContainsString($private, $html);
            }

            return $mail->hasTo($guideOwner->email) && $mail->subjectLine === 'Nueva solicitud de contacto';
        });

        // Nada privado en la API in-app del destinatario.
        $list = $this->withToken($guideToken)->getJson('/api/v1/profiles/'.$guide->public_id.'/notifications')->assertOk();
        $this->assertSame(['contact_request_received', 'welcome'], $list->json('data.*.type'));
        foreach ([self::COMPANY_CONTACT_EMAIL, self::COMPANY_PHONE, $companyOwner->email, 'Guiado bilingüe'] as $private) {
            $this->assertStringNotContainsString($private, (string) $list->getContent());
        }
    }

    public function test_p26_recording_twice_queues_one_job_and_running_the_job_twice_sends_one_email(): void
    {
        [, $profile, $owner] = $this->actor('p26-outbox@example.test', ProfileCategory::Guide, 'Guía Outbox');
        Queue::fake();
        Event::fake([NotificationCreated::class]);
        $service = app(NotificationService::class);
        $record = static function () use ($service, $owner, $profile): void {
            DB::transaction(static function () use ($service, $owner, $profile): void {
                $service->administrativeChange($owner, $profile, 'access_updated', '01JCONTACTURAUDIT000000001', ['access_grant_version' => 2]);
            });
        };

        $record();
        $record();
        DB::transaction(fn (): bool => $service->record($owner, $profile, NotificationType::AdministrativeChange, ['change' => 'access_updated'], 'administrative_change:01JCONTACTURAUDIT000000001'));

        $rows = ProfileNotification::query()->where('type', 'administrative_change')->get();
        $this->assertCount(1, $rows);
        $notification = $rows->firstOrFail();
        $this->assertSame(ProfileNotification::EMAIL_PENDING, $notification->email_status);
        Queue::assertPushed(SendNotificationEmail::class, 1);
        Queue::assertPushed(SendNotificationEmail::class, fn (SendNotificationEmail $job): bool => $job->notificationId === $notification->id && $job->queue === 'default' && $job->tries === 3);
        Event::assertDispatchedTimes(NotificationCreated::class, 1);
        Event::assertDispatched(NotificationCreated::class, fn (NotificationCreated $event): bool => $event->profileId === $profile->public_id
            && $event->notificationId === $notification->public_id
            && $event->broadcastAs() === 'notification.created'
            && $event->broadcastWith() === ['profile_id' => $profile->public_id, 'notification_id' => $notification->public_id]
            && $event->broadcastOn()[0]->name === 'private-profile.'.$profile->public_id);

        // Reintento o job duplicado: el segundo encuentra `sent` y no reenvía.
        (new SendNotificationEmail($notification->id))->handle($service);
        (new SendNotificationEmail($notification->id))->handle($service);
        Mail::assertSent(ProfileNotificationMail::class, 1);
        Mail::assertSent(ProfileNotificationMail::class, fn (ProfileNotificationMail $mail): bool => $mail->hasTo($owner->email));
        $notification->refresh();
        $this->assertSame(ProfileNotification::EMAIL_SENT, $notification->email_status);
        $sentAt = $notification->emailed_at;
        $this->assertNotNull($sentAt);

        // failed() tras agotar intentos no pisa un `sent`.
        (new SendNotificationEmail($notification->id))->failed(new RuntimeException('smtp'));
        $this->assertSame(ProfileNotification::EMAIL_SENT, $notification->refresh()->email_status);
        $this->assertSame(1, ProfileNotification::query()->count() - ProfileNotification::query()->where('type', 'welcome')->count());

        // Un correo que falla sigue pending (el job reintenta); al agotar intentos queda failed.
        DB::transaction(fn (): bool => $service->record($owner, $profile, NotificationType::AdministrativeChange, ['change' => 'access_updated', 'profile_id' => $profile->public_id], 'test:failing'));
        $failing = ProfileNotification::query()->where('dedupe_key', 'test:failing')->firstOrFail();
        Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp caído'));
        try {
            (new SendNotificationEmail($failing->id))->handle($service);
            $this->fail('El envío debía fallar.');
        } catch (RuntimeException) {
        }
        $this->assertSame(ProfileNotification::EMAIL_PENDING, $failing->refresh()->email_status);
        (new SendNotificationEmail($failing->id))->failed(new RuntimeException('smtp caído'));
        $this->assertSame(ProfileNotification::EMAIL_FAILED, $failing->refresh()->email_status);
        $this->assertNull($failing->emailed_at);
    }

    public function test_p26_a_lost_push_is_requeued_by_the_sweeper_and_still_sends_once(): void
    {
        [, $profile, $owner] = $this->actor('p26-sweeper@example.test', ProfileCategory::Guide, 'Guía Barrido');
        Queue::fake();
        DB::transaction(fn (): bool => app(NotificationService::class)->record($owner, $profile, NotificationType::AdministrativeChange, ['change' => 'access_updated', 'profile_id' => $profile->public_id], 'test:sweeper'));
        $row = ProfileNotification::query()->where('dedupe_key', 'test:sweeper')->sole();

        // Recién creada: el barrido no la toca (el job original puede estar en vuelo).
        $this->artisan('contactur:dispatch-pending-notification-emails')->expectsOutputToContain('reencolados: 0.')->assertSuccessful();
        $this->clock->set($this->clock->now()->addMinutes(11));
        $this->artisan('contactur:dispatch-pending-notification-emails')->expectsOutputToContain('reencolados: 1.')->assertSuccessful();
        Queue::assertPushed(SendNotificationEmail::class, 2);

        // Job original + reencolado: un solo correo; después ya no queda nada pendiente.
        (new SendNotificationEmail($row->id))->handle(app(NotificationService::class));
        (new SendNotificationEmail($row->id))->handle(app(NotificationService::class));
        Mail::assertSent(ProfileNotificationMail::class, 1);
        $this->artisan('contactur:dispatch-pending-notification-emails')->expectsOutputToContain('reencolados: 0.')->assertSuccessful();
        $this->artisan('contactur:dispatch-pending-notification-emails', ['--limit' => '0'])->assertFailed();
    }

    public function test_p26_optional_opt_out_keeps_in_app_without_email_and_essential_events_ignore_it(): void
    {
        [$companyToken, $company, $companyOwner] = $this->actor('p26-optout-company@example.test', ProfileCategory::TourismCompany, 'Empresa Opt Out');
        [$guideToken, $guide] = $this->actor('p26-optout-guide@example.test', ProfileCategory::Guide, 'Guía Opt Out');
        $this->withToken($companyToken)->patchJson('/api/v1/me', ['notification_preferences' => [
            ['event_type' => 'contact_request_rejected', 'email_enabled' => false],
            ['event_type' => 'administrative_change', 'email_enabled' => false],
        ]])->assertOk();

        $requestId = $this->createRequest($companyToken, $company, $guide);
        $this->withToken($guideToken)->postJson('/api/v1/profiles/'.$guide->public_id.'/contact-requests/'.$requestId.'/reject')->assertOk();

        $rejected = ProfileNotification::query()->where('type', 'contact_request_rejected')->sole();
        $this->assertSame($company->id, $rejected->profile_id);
        $this->assertSame('contact_request_rejected:'.$requestId, $rejected->dedupe_key);
        $this->assertSame(ProfileNotification::EMAIL_SKIPPED_PREFERENCE, $rejected->email_status);
        $this->assertNull($rejected->emailed_at);
        Mail::assertNotSent(ProfileNotificationMail::class, fn (ProfileNotificationMail $mail): bool => $mail->hasTo($companyOwner->email));
        $this->withToken($companyToken)->getJson('/api/v1/profiles/'.$company->public_id.'/notifications')
            ->assertOk()
            ->assertJsonPath('data.0.type', 'contact_request_rejected')
            ->assertJsonPath('data.0.safe_data.contact_request_id', $requestId);

        // Esencial: el cambio de acceso por admin manda correo aunque haya opt-out.
        [, $adminToken] = $this->admin('p26-optout-admin@example.test');
        $this->withToken($adminToken)->putJson('/api/v1/admin/profiles/'.$company->public_id.'/access', [
            'status' => AccessGrantStatus::Active->value,
            'starts_at' => null,
            'expires_at' => null,
            'city_limit' => null,
            'monthly_contact_limit' => 3,
            'gallery_enabled' => false,
            'expected_version' => 1,
            'reason' => 'Ajuste de cuota de prueba',
        ])->assertOk();
        $access = ProfileNotification::query()->where('type', 'administrative_change')->sole();
        $this->assertEquals(['change' => 'access_updated', 'profile_id' => $company->public_id, 'access_grant_version' => 2], $access->safe_data);
        $this->assertSame(ProfileNotification::EMAIL_SENT, $access->email_status);
        $this->assertStringStartsWith('administrative_change:', $access->dedupe_key);
        Mail::assertSent(ProfileNotificationMail::class, fn (ProfileNotificationMail $mail): bool => $mail->hasTo($companyOwner->email)
            && $mail->subjectLine === 'Cambios en tu acceso'
            && ! str_contains($mail->render(), 'Ajuste de cuota de prueba'));

        // La preferencia se re-evalúa al ejecutar el job: opt-out posterior → skipped.
        Queue::fake();
        $accepted = $this->createRequest($companyToken, $company, $this->receiver('Guía Tardía'));
        $received = ProfileNotification::query()->where('dedupe_key', 'contact_request_received:'.$accepted)->sole();
        $this->assertSame(ProfileNotification::EMAIL_PENDING, $received->email_status);
        $recipientOwner = User::query()->findOrFail($received->user_id);
        $recipientOwner->forceFill(['notification_preferences' => [['event_type' => 'contact_request_received', 'email_enabled' => false]]])->save();
        $mailsBefore = count(Mail::sent(ProfileNotificationMail::class));
        (new SendNotificationEmail($received->id))->handle(app(NotificationService::class));
        $this->assertSame(ProfileNotification::EMAIL_SKIPPED_PREFERENCE, $received->refresh()->email_status);
        $this->assertCount($mailsBefore, Mail::sent(ProfileNotificationMail::class));
    }

    public function test_p26_accepted_email_goes_to_the_sender_without_contact_data(): void
    {
        [$companyToken, $company, $companyOwner] = $this->actor('p26-accept-company@example.test', ProfileCategory::TourismCompany, 'Empresa Aceptada');
        [$guideToken, $guide, $guideOwner] = $this->actor('p26-accept-guide@example.test', ProfileCategory::Guide, 'Guía Que Acepta');
        ProfileContact::query()->whereKey($guide->id)->update(['contact_email' => 'guia-privada@example.test', 'phone' => '+54 9 11 4444-0000']);
        $requestId = $this->createRequest($companyToken, $company, $guide);

        $this->withToken($guideToken)->postJson('/api/v1/profiles/'.$guide->public_id.'/contact-requests/'.$requestId.'/accept')->assertOk();

        $accepted = ProfileNotification::query()->where('type', 'contact_request_accepted')->sole();
        $this->assertSame($company->id, $accepted->profile_id);
        $this->assertSame('sender', $accepted->safe_data['role']);
        $this->assertSame($guide->public_id, $accepted->safe_data['counterpart_profile_id']);
        Mail::assertSent(ProfileNotificationMail::class, function (ProfileNotificationMail $mail) use ($companyOwner, $guideOwner): bool {
            if (! $mail->hasTo($companyOwner->email)) {
                return false;
            }
            $html = $mail->render();
            $this->assertStringContainsString('Guía Que Acepta', $html);
            foreach (['guia-privada@example.test', '+54 9 11 4444-0000', $guideOwner->email] as $private) {
                $this->assertStringNotContainsString($private, $html);
            }

            return $mail->subjectLine === 'Solicitud aceptada';
        });
    }

    public function test_p23_running_the_expiration_job_twice_records_one_expired_per_participant(): void
    {
        [$companyToken, $company, $companyOwner] = $this->actor('p23-company@example.test', ProfileCategory::TourismCompany, 'Empresa Vencida');
        [, $guide, $guideOwner] = $this->actor('p23-guide@example.test', ProfileCategory::Guide, 'Guía Vencida');
        $requestId = $this->createRequest($companyToken, $company, $guide);
        $other = $this->createRequest($companyToken, $company, $this->receiver('Guía Lote 2'));
        $this->clock->set(CarbonImmutable::parse('2026-11-17T12:00:00Z'));
        $mailsBefore = count(Mail::sent(ProfileNotificationMail::class));

        $this->artisan('contactur:expire-contact-requests', ['--batch' => 1])->assertSuccessful();
        $this->artisan('contactur:expire-contact-requests')->assertSuccessful();
        $this->clock->set(CarbonImmutable::parse('2026-11-18T12:00:00Z'));
        $this->artisan('contactur:expire-contact-requests')->assertSuccessful();

        $expired = ProfileNotification::query()->where('type', 'contact_request_expired')->orderBy('id')->get();
        $this->assertCount(4, $expired);
        $keys = $expired->pluck('dedupe_key')->all();
        sort($keys);
        $expectedKeys = [
            'contact_request_expired:'.$other.':recipient',
            'contact_request_expired:'.$other.':sender',
            'contact_request_expired:'.$requestId.':recipient',
            'contact_request_expired:'.$requestId.':sender',
        ];
        sort($expectedKeys);
        $this->assertSame($expectedKeys, $keys);
        $forPair = $expired->filter(fn (ProfileNotification $row): bool => ($row->safe_data['contact_request_id'] ?? null) === $requestId);
        $this->assertEqualsCanonicalizing([$company->id, $guide->id], $forPair->pluck('profile_id')->all());
        $this->assertEqualsCanonicalizing([$companyOwner->id, $guideOwner->id], $forPair->pluck('user_id')->all());
        $this->assertCount($mailsBefore + 4, Mail::sent(ProfileNotificationMail::class));

        // Reenvío tras el cooldown: la materialización al crear no duplica lo ya notificado.
        $this->clock->set(CarbonImmutable::parse('2026-11-25T12:00:00Z'));
        $this->createRequest($companyToken, $company, $guide);
        $this->assertSame(4, ProfileNotification::query()->where('type', 'contact_request_expired')->count());
    }

    public function test_p23_a_pending_request_materialized_as_expired_on_resend_is_notified_once(): void
    {
        [$companyToken, $company] = $this->actor('p23-resend-company@example.test', ProfileCategory::TourismCompany, 'Empresa Reenvío');
        [, $guide] = $this->actor('p23-resend-guide@example.test', ProfileCategory::Guide, 'Guía Reenvío');
        $first = $this->createRequest($companyToken, $company, $guide);

        // El job nunca corrió: el reenvío materializa `expired` y notifica en su transacción.
        $this->clock->set(CarbonImmutable::parse('2026-11-24T12:00:01Z'));
        $this->createRequest($companyToken, $company, $guide);
        $this->artisan('contactur:expire-contact-requests')->assertSuccessful();

        $this->assertSame(
            ['contact_request_expired:'.$first.':recipient', 'contact_request_expired:'.$first.':sender'],
            ProfileNotification::query()->where('type', 'contact_request_expired')->orderBy('dedupe_key')->pluck('dedupe_key')->all(),
        );
    }

    public function test_a_business_transaction_that_rolls_back_leaves_no_notification_job_or_broadcast(): void
    {
        [, $company, $companyOwner] = $this->actor('rollback-company@example.test', ProfileCategory::TourismCompany, 'Empresa Rollback');
        [, $guide] = $this->actor('rollback-guide@example.test', ProfileCategory::Guide, 'Guía Rollback');
        $baseline = ProfileNotification::query()->count();
        Queue::fake();
        Event::fake([NotificationCreated::class]);

        // Falla después de registrar la notificación (al renderizar la respuesta).
        try {
            app(ContactRequestService::class)->create($companyOwner, $company->public_id, [
                'target_profile_id' => $guide->public_id,
                'service_description' => 'Solicitud que no debe confirmarse',
                'requested_from' => null,
                'requested_to' => null,
                'requested_city_id' => null,
            ], null, static fn (): array => throw new RuntimeException('Falla posterior al registro'));
            $this->fail('La creación debía fallar.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Falla posterior al registro', $exception->getMessage());
        }

        // Y una transacción directa que registra y luego falla.
        try {
            DB::transaction(function () use ($companyOwner, $company): void {
                app(NotificationService::class)->record($companyOwner, $company, NotificationType::AdministrativeChange, ['change' => 'access_updated'], 'test:rollback');
                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(0, DB::table('contact_requests')->count());
        $this->assertSame($baseline, ProfileNotification::query()->count());
        $this->assertFalse(ProfileNotification::query()->where('dedupe_key', 'test:rollback')->exists());
        Queue::assertNotPushed(SendNotificationEmail::class);
        Event::assertNotDispatched(NotificationCreated::class);
        Mail::assertNotSent(ProfileNotificationMail::class);
    }

    public function test_administrative_changes_notify_the_owner_with_safe_data_and_account_events_reach_each_profile(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        [$ownerToken, $guide, $owner] = $this->actor('admin-change-owner@example.test', ProfileCategory::Guide, 'Guía Administrada');
        $second = $this->receiver('Instructor Administrado', ProfileCategory::Instructor, $owner);
        [$admin, $adminToken] = $this->admin('admin-change-admin@example.test');
        $owner->forceFill(['notification_preferences' => [['event_type' => 'administrative_change', 'email_enabled' => false]]])->save();

        $this->withToken($adminToken)->patchJson('/api/v1/admin/profiles/'.$guide->public_id.'/status', [
            'status' => ProfileStatus::Suspended->value,
            'reason' => 'Motivo interno de suspensión',
        ])->assertOk();
        $this->withToken($adminToken)->patchJson('/api/v1/admin/profiles/'.$guide->public_id.'/status', [
            'status' => ProfileStatus::Suspended->value,
            'reason' => 'Repetida sin cambio',
        ])->assertOk();
        $this->withToken($adminToken)->patchJson('/api/v1/admin/profiles/'.$guide->public_id.'/status', [
            'status' => ProfileStatus::Published->value,
            'reason' => 'Motivo interno de restauración',
        ])->assertOk();

        $type = DocumentType::query()->where('category', ProfileCategory::Guide->value)->where('required', true)->firstOrFail();
        $documentId = (string) $this->withToken($ownerToken)->withHeader('Accept', 'application/json')->post(
            '/api/v1/profiles/'.$guide->public_id.'/documents',
            [
                'document_type_id' => $type->id,
                'file' => SyntheticUploads::file('t19-doc.pdf', 'application/pdf', SyntheticUploads::pdf('t19')),
                'valid_until' => $this->clock->now()->addMonth()->toDateString(),
            ],
        )->assertCreated()->json('data.id');
        $this->withToken($adminToken)->postJson('/api/v1/admin/documents/'.$documentId.'/review', [
            'action' => 'reject',
            'rejection_reason' => 'Documento sintético ilegible.',
        ])->assertOk();

        Event::fake([NotificationCreated::class]);
        $this->withToken($adminToken)->patchJson('/api/v1/admin/users/'.$owner->public_id.'/status', [
            'status' => User::STATUS_SUSPENDED,
            'reason' => 'Motivo interno de cuenta',
        ])->assertOk();

        $changes = ProfileNotification::query()->where('type', 'administrative_change')->orderBy('id')->get();
        $this->assertSame(
            ['profile_suspended', 'profile_restored', 'document_rejected', 'account_suspended'],
            $changes->map(fn (ProfileNotification $row): string => (string) $row->safe_data['change'])->all(),
        );
        $this->assertSame([$guide->id, $guide->id, $guide->id, null], $changes->pluck('profile_id')->all());
        $this->assertEquals(['change' => 'document_rejected', 'profile_id' => $guide->public_id, 'document_id' => $documentId, 'document_type' => $type->label, 'rejection_reason' => 'Documento sintético ilegible.'], $changes[2]->safe_data);
        $this->assertSame(['change' => 'account_suspended'], $changes[3]->safe_data);
        foreach ($changes as $change) {
            // Esencial: correo enviado aun con opt-out y con la cuenta ya suspendida.
            $this->assertSame(ProfileNotification::EMAIL_SENT, $change->email_status);
            $this->assertStringStartsWith('administrative_change:', $change->dedupe_key);
            $this->assertTrue(DB::table('audit_logs')->where('public_id', Str::after($change->dedupe_key, 'administrative_change:'))->exists());
            $json = json_encode($change->safe_data, JSON_THROW_ON_ERROR);
            foreach ([$admin->public_id, $admin->email, 'Motivo interno', 'reviewer'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $json);
            }
        }
        Mail::assertSent(ProfileNotificationMail::class, fn (ProfileNotificationMail $mail): bool => $mail->hasTo($owner->email) && $mail->subjectLine === 'Cuenta suspendida');
        Mail::assertSent(ProfileNotificationMail::class, fn (ProfileNotificationMail $mail): bool => $mail->subjectLine === 'Documento rechazado'
            && ! str_contains($mail->render(), $admin->email));

        // Evento de cuenta: una invalidación por cada perfil propio, cada una en su canal.
        Event::assertDispatchedTimes(NotificationCreated::class, 2);
        foreach ([$guide, $second] as $profile) {
            Event::assertDispatched(NotificationCreated::class, fn (NotificationCreated $event): bool => $event->profileId === $profile->public_id
                && $event->notificationId === $changes[3]->public_id);
        }

        // Reactivar y verificar que el evento de cuenta aparece en ambos perfiles.
        $this->withToken($adminToken)->patchJson('/api/v1/admin/users/'.$owner->public_id.'/status', [
            'status' => User::STATUS_ACTIVE,
            'reason' => 'Reactivación',
        ])->assertOk();
        foreach ([$guide, $second] as $profile) {
            $types = $this->withToken($ownerToken)->getJson('/api/v1/profiles/'.$profile->public_id.'/notifications')
                ->assertOk()
                ->json('data.*.safe_data.change');
            $this->assertContains('account_suspended', $types);
            $this->assertContains('account_reactivated', $types);
        }
    }

    public function test_registration_and_email_verification_are_in_app_only_and_keep_the_existing_email(): void
    {
        [, , $owner] = $this->actor('verify-owner@example.test', ProfileCategory::Guide, 'Guía Verificada');
        $welcome = ProfileNotification::query()->where('user_id', $owner->id)->where('type', 'welcome')->sole();
        $this->assertNull($welcome->profile_id);
        $this->assertSame('welcome:'.$owner->public_id, $welcome->dedupe_key);
        $this->assertSame(ProfileNotification::EMAIL_NOT_APPLICABLE, $welcome->email_status);

        $token = null;
        Notification::assertSentTo($owner, EmailVerificationNotification::class, function (EmailVerificationNotification $notification) use (&$token): bool {
            $token = $notification->token;

            return true;
        });
        $this->postJson('/api/v1/auth/email/verify', ['token' => $token])->assertOk();
        $this->postJson('/api/v1/auth/email/verify', ['token' => $token])->assertOk();

        $verified = ProfileNotification::query()->where('user_id', $owner->id)->where('type', 'email_verified')->sole();
        $this->assertNull($verified->profile_id);
        $this->assertSame(['source' => 'registration'], $verified->safe_data);
        $this->assertSame(ProfileNotification::EMAIL_NOT_APPLICABLE, $verified->email_status);
        Notification::assertSentToTimes($owner, EmailVerifiedNotification::class, 1);
        Mail::assertNotSent(ProfileNotificationMail::class);
    }

    /** @return array{string, Profile, User} */
    private function actor(string $email, ProfileCategory $category, string $name): array
    {
        $token = (string) $this->postJson('/api/v1/auth/register', [
            'name' => 'Persona de Prueba',
            'email' => $email,
            'password' => 'Clave-segura-2026',
            'password_confirmation' => 'Clave-segura-2026',
        ])->assertCreated()->json('data.access_token');
        $owner = User::query()->where('email', $email)->firstOrFail();
        DB::table('auth_sessions')->where('user_id', $owner->id)->update(['expires_at' => '2030-01-01 00:00:00+00']);

        return [$token, $this->receiver($name, $category, $owner), $owner];
    }

    /** @return array{User, string} */
    private function admin(string $email): array
    {
        [$token, , $user] = $this->actor($email, ProfileCategory::Guide, 'Perfil Admin '.Str::random(4));
        $user->forceFill(['system_role' => User::ROLE_ADMIN])->save();

        return [$user, $token];
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

    private function createRequest(string $token, Profile $sender, Profile $target): string
    {
        return (string) $this->withToken($token)->postJson('/api/v1/profiles/'.$sender->public_id.'/contact-requests', [
            'target_profile_id' => $target->public_id,
            'service_description' => 'Guía bilingüe para trekking',
        ])->assertCreated()->json('data.id');
    }
}
