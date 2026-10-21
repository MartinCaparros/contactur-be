<?php

namespace Tests\Feature;

use App\Enums\ProfileCategory;
use App\Events\DirectoryChanged;
use App\Mail\ProfileNotificationMail;
use App\Models\AuditLog;
use App\Models\ProfileNotification;
use App\Models\Reference;
use App\Models\User;
use App\Services\ReferenceService;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\ReferenceFixtures;
use Tests\TestCase;

/**
 * T20 / R17 / R20 — moderación administrativa de referencias: listado con filtros
 * y cursor, ocultar/restaurar con motivo, auditoría sin el texto, avisos a autor
 * y destinatario e invalidación del directorio después del commit.
 */
final class AdminReferenceModerationTest extends TestCase
{
    use ReferenceFixtures;
    use RefreshDatabase;

    private const BODY = 'Texto confidencial de la referencia que no debe salir del sistema.';

    private const ADMIN_REFERENCE_KEYS = [
        'id', 'contact_request_id', 'author', 'subject', 'body', 'visibility', 'moderated', 'moderation_reason', 'moderated_at', 'created_at',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Mail::fake();
        $this->seed(CatalogSeeder::class);
        $this->freezeReferenceClock('2026-11-10T12:00:00Z');
    }

    public function test_admin_lists_references_with_filters_and_cursor_and_non_admins_are_rejected(): void
    {
        [$token, $company] = $this->actor('list-company@example.test');
        [$guideToken, $guide] = $this->actor('list-guide@example.test', ProfileCategory::Guide);
        [$driverToken, $driver] = $this->actor('list-driver@example.test', ProfileCategory::Driver);
        [, $adminToken] = $this->registerAdmin('list-admin@example.test');
        $first = $this->acceptedMatch($token, $company, $guideToken, $guide);
        $second = $this->acceptedMatch($token, $company, $driverToken, $driver);
        $ids = [
            'company_guide' => (string) $this->createReference($token, $company, $first)->json('data.id'),
            'guide_company' => (string) $this->createReference($guideToken, $guide, $first)->json('data.id'),
            'company_driver' => (string) $this->createReference($token, $company, $second)->json('data.id'),
        ];
        $this->moderate($adminToken, $ids['guide_company'], 'hide')->assertOk();

        $all = $this->adminList($adminToken)->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonPath('meta.next_cursor', null);
        $this->assertSame([$ids['company_driver'], $ids['guide_company'], $ids['company_guide']], $this->ids($all));
        foreach ((array) $all->json('data') as $item) {
            $this->assertSame(self::ADMIN_REFERENCE_KEYS, array_keys((array) $item));
        }
        $moderated = collect((array) $all->json('data'))->firstWhere('id', $ids['guide_company']);
        $this->assertIsArray($moderated);
        $this->assertTrue($moderated['moderated']);
        $this->assertSame('2026-11-10T12:00:00.000000Z', $moderated['moderated_at']);
        $this->assertSame('Contenido contrario a las reglas', $moderated['moderation_reason']);
        // Ni contactos, ni emails de cuenta, ni quién moderó.
        foreach (['contact_email', '"phone"', '"email"', 'moderated_by', '"owner'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, (string) $all->getContent());
        }

        $this->assertSame([$ids['guide_company']], $this->ids($this->adminList($adminToken, '?moderated=true')->assertOk()->assertJsonPath('meta.total', 1)));
        $this->assertSame([$ids['company_driver'], $ids['company_guide']], $this->ids($this->adminList($adminToken, '?moderated=false')->assertOk()));
        $this->assertSame([$ids['company_driver'], $ids['company_guide']], $this->ids($this->adminList($adminToken, '?moderated=0')->assertOk()));
        // Perfil como autor o destinatario.
        $this->assertSame([$ids['guide_company'], $ids['company_guide']], $this->ids($this->adminList($adminToken, '?profile='.$guide->public_id)->assertOk()));
        $this->assertSame([$ids['company_driver']], $this->ids($this->adminList($adminToken, '?profile='.$driver->public_id.'&moderated=false')->assertOk()));
        $this->adminList($adminToken, '?profile='.Str::ulid())->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);

        // Cursor: sin duplicados ni huecos.
        $page = $this->adminList($adminToken, '?per_page=2')->assertOk()->assertJsonCount(2, 'data');
        $cursor = (string) $page->json('meta.next_cursor');
        $rest = $this->adminList($adminToken, '?per_page=2&cursor='.$cursor)->assertOk()->assertJsonPath('meta.next_cursor', null);
        $this->assertSame($this->ids($all), [...$this->ids($page), ...$this->ids($rest)]);

        foreach (['?moderated=yes', '?profile=abc', '?per_page=0', '?per_page=51', '?cursor=x', '?status=hidden'] as $query) {
            $this->adminList($adminToken, $query)->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed');
        }
        // No admin.
        $this->adminList($token)->assertForbidden();
        $this->moderate($guideToken, $ids['company_guide'], 'hide')->assertForbidden();
        $this->assertNull(Reference::query()->where('public_id', $ids['company_guide'])->value('moderated_at'));
    }

    public function test_hide_and_restore_audit_without_the_body_notify_both_parties_and_invalidate_the_directory(): void
    {
        [$token, $company, $companyOwner] = $this->actor('mod-company@example.test');
        [$guideToken, $guide, $guideOwner] = $this->actor('mod-guide@example.test', ProfileCategory::Guide);
        [$admin, $adminToken] = $this->registerAdmin('mod-admin@example.test');
        $match = $this->acceptedMatch($token, $company, $guideToken, $guide);
        $id = (string) $this->createReference($token, $company, $match, ['body' => self::BODY])->assertCreated()->json('data.id');
        $this->setVisibility($guideToken, $guide, $id, 'visible')->assertOk();
        Event::fake([DirectoryChanged::class]);

        // Ocultar: Reference del contrato (sin moderated_at), motivo recortado.
        $hidden = $this->moderate($adminToken, $id, 'hide', '  Contiene datos personales  ')->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.moderated', true)
            ->assertJsonPath('data.moderation_reason', 'Contiene datos personales')
            ->assertJsonPath('data.visibility', 'visible')
            ->assertJsonPath('data.body', self::BODY);
        $this->assertSame(
            ['id', 'contact_request_id', 'author', 'subject', 'body', 'visibility', 'moderated', 'moderation_reason', 'created_at'],
            array_keys((array) $hidden->json('data')),
        );
        $row = DB::table('references')->where('public_id', $id)->first();
        $this->assertNotNull($row);
        $this->assertNotNull($row->moderated_at);
        $this->assertSame($admin->id, (int) $row->moderated_by);
        $this->assertSame(self::BODY, $row->body);
        // La ficha deja de mostrarla aunque el destinatario la tenga visible.
        [$viewerToken, $viewer] = $this->actor('mod-viewer@example.test');
        $this->directoryProfile($viewerToken, $viewer, $guide)->assertOk()->assertJsonPath('data.visible_references', []);

        $audit = AuditLog::query()->sole();
        $this->assertSame('reference', $audit->target_type);
        $this->assertSame($id, $audit->target_id);
        $this->assertSame('reference.hidden', $audit->action);
        $this->assertSame('Contiene datos personales', $audit->reason);
        $this->assertSame($admin->id, $audit->actor_user_id);
        // jsonb no conserva el orden de claves: se compara por clave.
        $this->assertEquals([
            'action' => 'hide',
            'moderated' => ['before' => false, 'after' => true],
            'visibility' => 'visible',
            'contact_request_id' => $match,
            'author_profile_id' => $company->public_id,
            'subject_profile_id' => $guide->public_id,
        ], $audit->safe_changes);
        $this->assertSame(false, $audit->safe_changes['moderated']['before']);
        $this->assertStringNotContainsString(self::BODY, (string) json_encode(DB::table('audit_logs')->get()));
        // El admin puede ver la auditoría: tampoco ahí aparece el texto.
        $auditResponse = $this->withToken($adminToken)->getJson('/api/v1/admin/audit-logs?target_type=reference')->assertOk()
            ->assertJsonPath('data.0.action', 'reference.hidden');
        $this->assertStringNotContainsString(self::BODY, (string) $auditResponse->getContent());

        // Avisos: uno al destinatario y otro al autor, con la auditoría + rol como clave.
        $this->assertNotifications($audit->public_id, 'reference_hidden', $id, [
            'subject' => [$guideOwner, $guide->id, $guide->public_id],
            'author' => [$companyOwner, $company->id, $company->public_id],
        ]);
        $this->assertMails('Referencia ocultada', [$guideOwner->email, $companyOwner->email]);
        Event::assertDispatchedTimes(DirectoryChanged::class, 1);
        Event::assertDispatched(DirectoryChanged::class, fn (DirectoryChanged $event): bool => $event->profileId === $guide->public_id);

        // Ocultar otra vez: 200 con el estado vigente, sin auditoría, avisos ni evento; el motivo no cambia.
        $this->moderate($adminToken, $id, 'hide', 'Otro motivo distinto')->assertOk()
            ->assertJsonPath('data.moderated', true)
            ->assertJsonPath('data.moderation_reason', 'Contiene datos personales');
        $this->assertSame(1, AuditLog::query()->count());
        $this->assertSame(2, $this->administrativeNotifications());
        $this->assertCount(2, Mail::sent(ProfileNotificationMail::class, fn (ProfileNotificationMail $mail): bool => str_starts_with($mail->subjectLine, 'Referencia')));
        Event::assertDispatchedTimes(DirectoryChanged::class, 1);

        // Restaurar: limpia la moderación y conserva la visibilidad del destinatario.
        $this->moderate($adminToken, $id, 'restore', 'Revisión completada')->assertOk()
            ->assertJsonPath('data.moderated', false)
            ->assertJsonPath('data.moderation_reason', null)
            ->assertJsonPath('data.visibility', 'visible');
        $row = DB::table('references')->where('public_id', $id)->first();
        $this->assertNotNull($row);
        $this->assertNull($row->moderated_at);
        $this->assertNull($row->moderated_by);
        $this->assertNull($row->moderation_reason);
        $this->assertSame([$id], array_column((array) $this->directoryProfile($viewerToken, $viewer, $guide)->assertOk()->json('data.visible_references'), 'id'));
        $restored = AuditLog::query()->where('action', 'reference.restored')->sole();
        $this->assertEquals(['before' => true, 'after' => false], $restored->safe_changes['moderated']);
        $this->assertSame('Revisión completada', $restored->reason);
        $this->assertNotifications($restored->public_id, 'reference_restored', $id, [
            'subject' => [$guideOwner, $guide->id, $guide->public_id],
            'author' => [$companyOwner, $company->id, $company->public_id],
        ]);
        $this->assertMails('Referencia restaurada', [$guideOwner->email, $companyOwner->email]);
        Event::assertDispatchedTimes(DirectoryChanged::class, 2);

        // Restaurar una no moderada: sin efecto.
        $this->moderate($adminToken, $id, 'restore', 'Sin efecto')->assertOk()->assertJsonPath('data.moderated', false);
        $this->assertSame(2, AuditLog::query()->count());
        $this->assertSame(4, $this->administrativeNotifications());
        $this->assertCount(4, Mail::sent(ProfileNotificationMail::class, fn (ProfileNotificationMail $mail): bool => str_starts_with($mail->subjectLine, 'Referencia')));
        Event::assertDispatchedTimes(DirectoryChanged::class, 2);

        // Los avisos del centro de notificaciones no traen el texto ni el motivo.
        $notifications = $this->withToken($guideToken)->getJson('/api/v1/profiles/'.$guide->public_id.'/notifications')->assertOk();
        foreach ([self::BODY, 'Contiene datos personales', 'Revisión completada'] as $secret) {
            $this->assertStringNotContainsString($secret, (string) $notifications->getContent());
        }
    }

    public function test_moderation_validates_input_and_unknown_references(): void
    {
        [$token, $company] = $this->actor('val-company@example.test');
        [$guideToken, $guide] = $this->actor('val-guide@example.test', ProfileCategory::Guide);
        [, $adminToken] = $this->registerAdmin('val-admin@example.test');
        $match = $this->acceptedMatch($token, $company, $guideToken, $guide);
        $id = (string) $this->createReference($token, $company, $match)->json('data.id');

        $invalid = [
            [['action' => 'delete', 'reason' => 'Motivo válido'], 'action'],
            [['action' => 'hide'], 'reason'],
            [['action' => 'hide', 'reason' => 'ab'], 'reason'],
            [['action' => 'hide', 'reason' => '    '], 'reason'],
            [['action' => 'hide', 'reason' => str_repeat('m', 1001)], 'reason'],
            [['action' => 'hide', 'reason' => 'Motivo válido', 'body' => 'Texto nuevo de reemplazo para la referencia'], 'body'],
            [['reason' => 'Motivo válido'], 'action'],
        ];
        foreach ($invalid as [$payload, $field]) {
            $this->withToken($adminToken)->postJson('/api/v1/admin/references/'.$id.'/moderate', $payload)
                ->assertUnprocessable()
                ->assertJsonPath('error.code', 'validation_failed')
                ->assertJsonStructure(['error' => ['fields' => [$field]]]);
        }
        $this->moderate($adminToken, (string) Str::ulid(), 'hide')->assertNotFound()->assertJsonPath('error.code', 'not_found');
        $this->assertSame(0, AuditLog::query()->count());
        $this->assertNull(Reference::query()->where('public_id', $id)->value('moderated_at'));
        // Motivo con 3 caracteres útiles rodeados de espacios: válido y recortado.
        $this->moderate($adminToken, $id, 'hide', '  ok!  ')->assertOk()->assertJsonPath('data.moderation_reason', 'ok!');
    }

    public function test_a_rolled_back_moderation_leaves_no_audit_notification_or_event(): void
    {
        [$token, $company] = $this->actor('rb-company@example.test');
        [$guideToken, $guide] = $this->actor('rb-guide@example.test', ProfileCategory::Guide);
        [$admin] = $this->registerAdmin('rb-admin@example.test');
        $match = $this->acceptedMatch($token, $company, $guideToken, $guide);
        $id = (string) $this->createReference($token, $company, $match)->json('data.id');
        Event::fake([DirectoryChanged::class]);
        $mailsBefore = count(Mail::sent(ProfileNotificationMail::class));
        $notificationsBefore = ProfileNotification::query()->count();

        try {
            DB::transaction(function () use ($admin, $id): void {
                $this->app->make(ReferenceService::class)->moderate($admin, $id, 'hide', 'Motivo de prueba', 'req-rollback');

                throw new RuntimeException('rollback forzado');
            });
            $this->fail('La transacción debía fallar.');
        } catch (RuntimeException $exception) {
            $this->assertSame('rollback forzado', $exception->getMessage());
        }

        Event::assertNotDispatched(DirectoryChanged::class);
        $this->assertCount($mailsBefore, Mail::sent(ProfileNotificationMail::class));
        $this->assertSame(0, AuditLog::query()->count());
        $this->assertSame($notificationsBefore, ProfileNotification::query()->count());
        $this->assertSame(0, $this->administrativeNotifications());
        $this->assertNull(Reference::query()->where('public_id', $id)->value('moderated_at'));
    }

    /**
     * @param  array<string, array{User, int, string}>  $expected  rol => [titular, id interno, public_id del perfil]
     */
    private function assertNotifications(string $auditId, string $change, string $referenceId, array $expected): void
    {
        foreach ($expected as $role => [$owner, $profileId, $profilePublicId]) {
            $notification = ProfileNotification::query()->where('dedupe_key', 'administrative_change:'.$auditId.':'.$role)->sole();
            $this->assertSame('administrative_change', $notification->type->value);
            $this->assertSame($owner->id, $notification->user_id);
            $this->assertSame($profileId, $notification->profile_id);
            $safeData = $notification->safe_data;
            ksort($safeData);
            $this->assertSame([
                'change' => $change,
                'profile_id' => $profilePublicId,
                'reference_id' => $referenceId,
                'role' => $role,
            ], $safeData);
        }
    }

    /** @param list<string> $recipients */
    private function assertMails(string $subject, array $recipients): void
    {
        foreach ($recipients as $email) {
            Mail::assertSent(ProfileNotificationMail::class, function (ProfileNotificationMail $mail) use ($email, $subject): bool {
                if (! $mail->hasTo($email) || $mail->subjectLine !== $subject) {
                    return false;
                }
                $text = implode(' ', $mail->lines).' '.$mail->actionUrl;
                // Copy genérico: sin texto de la referencia ni motivo del admin.
                $this->assertStringNotContainsString(self::BODY, $text);
                $this->assertStringNotContainsString('Contiene datos personales', $text);
                $this->assertStringNotContainsString('Revisión completada', $text);
                $this->assertStringContainsString('referencia', $text);
                $this->assertStringContainsString(rawurlencode('/app/references'), (string) $mail->actionUrl);

                return true;
            });
        }
    }

    private function administrativeNotifications(): int
    {
        return ProfileNotification::query()->where('type', 'administrative_change')->count();
    }

    /** @return TestResponse<Response> */
    private function adminList(string $token, string $query = ''): TestResponse
    {
        return $this->withToken($token)->getJson('/api/v1/admin/references'.$query);
    }

    /**
     * @param  TestResponse<Response>  $response
     * @return list<string>
     */
    private function ids(TestResponse $response): array
    {
        return array_values(array_map(static fn (array $item): string => (string) $item['id'], (array) $response->json('data')));
    }
}
