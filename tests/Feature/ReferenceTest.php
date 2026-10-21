<?php

namespace Tests\Feature;

use App\Enums\AccessGrantStatus;
use App\Enums\ProfileCategory;
use App\Enums\ProfileStatus;
use App\Events\DirectoryChanged;
use App\Models\AccessGrant;
use App\Models\Profile;
use App\Models\ProfileContact;
use App\Models\Reference;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\ReferenceFixtures;
use Tests\TestCase;

/**
 * T20 / R17 / P25 — referencias del titular: crear (bilateral, una por dirección),
 * listar, cambiar visibilidad y ficha de directorio. La moderación admin está en
 * AdminReferenceModerationTest y las carreras reales en ReferenceConcurrencyTest.
 */
final class ReferenceTest extends TestCase
{
    use ReferenceFixtures;
    use RefreshDatabase;

    private const T0 = '2026-11-10T12:00:00Z';

    private const REFERENCE_KEYS = [
        'id', 'contact_request_id', 'author', 'subject', 'body', 'visibility', 'moderated', 'moderation_reason', 'created_at',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->seed(CatalogSeeder::class);
        $this->freezeReferenceClock(self::T0);
    }

    public function test_p25_both_participants_of_an_accepted_match_leave_one_hidden_reference_each(): void
    {
        [$token, $company, $companyOwner] = $this->actor('ref-company@example.test');
        [$guideToken, $guide, $guideOwner] = $this->actor('ref-guide@example.test', ProfileCategory::Guide);
        ProfileContact::query()->whereKey($guide->id)->update(['contact_email' => 'privado-guia@example.test', 'phone' => '+54 9 2901 555-0101']);
        ProfileContact::query()->whereKey($company->id)->update(['contact_email' => 'privado-empresa@example.test', 'phone' => '+54 9 2901 555-0202']);
        $match = $this->acceptedMatch($token, $company, $guideToken, $guide);
        $this->clock->set(CarbonImmutable::parse('2026-11-12T08:15:00Z'));

        // Empresa → guía. El texto llega recortado.
        $created = $this->createReference($token, $company, $match, ['body' => "  La coordinación del recorrido fue clara y puntual.\n "])
            ->assertCreated()
            ->assertJsonPath('data.contact_request_id', $match)
            ->assertJsonPath('data.author.id', $company->public_id)
            ->assertJsonPath('data.subject.id', $guide->public_id)
            ->assertJsonPath('data.body', 'La coordinación del recorrido fue clara y puntual.')
            ->assertJsonPath('data.visibility', 'hidden')
            ->assertJsonPath('data.moderated', false)
            ->assertJsonPath('data.moderation_reason', null)
            ->assertJsonPath('data.created_at', '2026-11-12T08:15:00.000000Z');
        $this->assertSame(['data'], array_keys((array) $created->json()));
        $this->assertSame(self::REFERENCE_KEYS, array_keys((array) $created->json('data')));
        // Autor y destinatario con el mismo SafeProfile que las solicitudes.
        $sender = $this->withToken($token)->getJson('/api/v1/profiles/'.$company->public_id.'/contact-requests/'.$match)
            ->assertOk()->json('data.sender');
        $this->assertSame(array_keys((array) $sender), array_keys((array) $created->json('data.author')));
        $this->assertSame(array_keys((array) $sender), array_keys((array) $created->json('data.subject')));

        // Guía → empresa: la otra dirección del mismo match también se permite.
        $back = $this->createReference($guideToken, $guide, $match)
            ->assertCreated()
            ->assertJsonPath('data.author.id', $guide->public_id)
            ->assertJsonPath('data.subject.id', $company->public_id)
            ->assertJsonPath('data.visibility', 'hidden');

        // Mismo autor, mismo match: 409 sin crear otra fila (P25).
        $this->createReference($token, $company, $match, ['body' => 'Un segundo texto sobre el mismo match acordado.'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'reference_already_exists');
        $this->createReference($guideToken, $guide, $match)->assertStatus(409)->assertJsonPath('error.code', 'reference_already_exists');

        $rows = DB::table('references')->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $requestId = (int) DB::table('contact_requests')->where('public_id', $match)->value('id');
        foreach ($rows as $row) {
            $this->assertSame($requestId, (int) $row->contact_request_id);
            $this->assertNotSame((int) $row->author_profile_id, (int) $row->subject_profile_id);
            $this->assertSame('hidden', $row->visibility);
            $this->assertNull($row->moderated_at);
        }
        $this->assertSame([$company->id, $guide->id], $rows->pluck('author_profile_id')->map(static fn ($id): int => (int) $id)->all());

        // Sin datos de contacto ni email de cuenta (SafeProfile).
        foreach ([$created, $back] as $response) {
            foreach (['privado-guia@example.test', 'privado-empresa@example.test', '555-0101', '555-0202', 'contact_email', '"phone"', $companyOwner->email, $guideOwner->email] as $secret) {
                $this->assertStringNotContainsString($secret, (string) $response->getContent());
            }
        }

        // Base: el autor nunca puede ser el destinatario.
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('references_distinct_profiles_check');
        DB::table('references')->insert([
            'public_id' => (string) Str::ulid(),
            'contact_request_id' => $requestId,
            'author_profile_id' => $guide->id,
            'subject_profile_id' => $guide->id,
            'body' => 'Texto que se autorreferencia indebidamente.',
            'visibility' => 'hidden',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_p25_only_participants_of_an_accepted_match_can_create(): void
    {
        [$token, $company] = $this->actor('only-company@example.test');
        [$guideToken, $guide] = $this->actor('only-guide@example.test', ProfileCategory::Guide);
        [$thirdToken, $third] = $this->actor('only-third@example.test', ProfileCategory::Guide);
        $match = $this->acceptedMatch($token, $company, $guideToken, $guide);
        $ownSibling = $this->siblingProfile($guide, 'Otro perfil propio');

        // Terceros: la solicitud no existe para ellos, ni con su perfil ni con uno ajeno.
        $this->createReference($thirdToken, $third, $match)->assertNotFound()->assertJsonPath('error.code', 'not_found');
        $this->createReference($thirdToken, $guide, $match)->assertNotFound();
        // La misma cuenta, pero con un perfil que no participa del match.
        $this->createReference($guideToken, $ownSibling, $match)->assertNotFound();
        // Solicitud inexistente.
        $this->createReference($token, $company, (string) Str::ulid())->assertNotFound();

        // Pendiente, rechazada, cancelada y vencida (por reloj y materializada) → 409.
        $pending = $this->createRequest($token, $company, $this->enabledProfile('Pendiente'));
        $rejectedTarget = $this->enabledProfile('Rechaza');
        $rejected = $this->createRequest($token, $company, $rejectedTarget);
        $cancelled = $this->createRequest($token, $company, $this->enabledProfile('Cancelada'));
        $this->respond($token, $company, $cancelled, 'cancel')->assertOk();
        DB::table('contact_requests')->where('public_id', $rejected)->update(['status' => 'rejected', 'responded_at' => '2026-11-10 12:30:00+00']);
        $byClock = $this->createRequest($token, $company, $this->enabledProfile('Vence por reloj'));
        $materialized = $this->createRequest($token, $company, $this->enabledProfile('Vence materializada'));
        DB::table('contact_requests')->where('public_id', $materialized)->update(['status' => 'expired']);

        $this->createReference($token, $company, $pending)->assertStatus(409)->assertJsonPath('error.code', 'contact_request_not_accepted');
        $this->clock->set(CarbonImmutable::parse('2026-11-17T12:00:00Z'));
        foreach ([$pending, $rejected, $cancelled, $byClock, $materialized] as $id) {
            $this->createReference($token, $company, $id)->assertStatus(409)->assertJsonPath('error.code', 'contact_request_not_accepted');
        }

        $this->assertSame(0, Reference::query()->count());
        // El match aceptado sigue habilitado aunque pasó el tiempo de vencimiento.
        $this->createReference($guideToken, $guide, $match)->assertCreated();
    }

    public function test_creating_requires_current_access_of_the_author_only(): void
    {
        [$token, $company, $companyOwner] = $this->actor('acc-company@example.test');
        [$guideToken, $guide] = $this->actor('acc-guide@example.test', ProfileCategory::Guide);
        $match = $this->acceptedMatch($token, $company, $guideToken, $guide);
        $past = '2026-11-10 11:00:00+00';

        $states = [
            'subscription_expired' => [fn () => AccessGrant::query()->whereKey($company->id)->update(['expires_at' => $past]), 'subscription_expired'],
            'subscription_required' => [fn () => AccessGrant::query()->whereKey($company->id)->update(['status' => AccessGrantStatus::Inactive->value]), 'subscription_required'],
            'grant suspendido' => [fn () => AccessGrant::query()->whereKey($company->id)->update(['status' => AccessGrantStatus::Suspended->value]), 'profile_suspended'],
            'perfil suspendido' => [fn () => Profile::query()->whereKey($company->id)->update(['status' => ProfileStatus::Suspended->value]), 'profile_suspended'],
            'cuenta suspendida' => [fn () => User::query()->whereKey($companyOwner->id)->update(['status' => User::STATUS_SUSPENDED]), 'account_suspended'],
        ];
        foreach ($states as $label => [$degrade, $code]) {
            $degrade();
            $this->createReference($token, $company, $match)->assertForbidden()->assertJsonPath('error.code', $code);
            $this->assertSame(0, Reference::query()->count(), $label);
            AccessGrant::query()->whereKey($company->id)->update(['expires_at' => null, 'status' => AccessGrantStatus::Active->value]);
            Profile::query()->whereKey($company->id)->update(['status' => ProfileStatus::Published->value]);
            User::query()->whereKey($companyOwner->id)->update(['status' => User::STATUS_ACTIVE]);
        }

        // El acceso de la contraparte no condiciona al autor (acceso.md: "Crear referencia").
        AccessGrant::query()->whereKey($guide->id)->update(['expires_at' => $past]);
        $this->createReference($token, $company, $match)->assertCreated();
        // El destinatario vencido tampoco puede escribir la suya.
        $this->createReference($guideToken, $guide, $match)->assertForbidden()->assertJsonPath('error.code', 'subscription_expired');
    }

    public function test_body_is_trimmed_and_validated_and_unknown_fields_are_rejected(): void
    {
        [$token, $company] = $this->actor('body-company@example.test');
        [$guideToken, $guide] = $this->actor('body-guide@example.test', ProfileCategory::Guide);
        $match = $this->acceptedMatch($token, $company, $guideToken, $guide);

        $invalid = [
            'faltante' => [[], 'body'],
            'no string' => [['body' => 12345678901234567890], 'body'],
            '19 tras recortar' => [['body' => '   '.str_repeat('a', 19).'   '], 'body'],
            'solo espacios' => [['body' => str_repeat(' ', 30)], 'body'],
            '2001' => [['body' => str_repeat('b', 2001)], 'body'],
            'visibilidad del cliente' => [['body' => str_repeat('c', 30), 'visibility' => 'visible'], 'visibility'],
            'destinatario del cliente' => [['body' => str_repeat('c', 30), 'subject_profile_id' => $company->public_id], 'subject_profile_id'],
            'puntaje' => [['body' => str_repeat('c', 30), 'rating' => 5], 'rating'],
        ];
        foreach ($invalid as $label => [$payload, $field]) {
            $this->createReference($token, $company, $match, $payload)
                ->assertUnprocessable()
                ->assertJsonPath('error.code', 'validation_failed')
                ->assertJsonStructure(['error' => ['fields' => [$field]]]);
            $this->assertSame(0, Reference::query()->count(), $label);
        }

        // Límites exactos: 20 (con espacios de borde) y 2000.
        $this->createReference($token, $company, $match, ['body' => '  '.str_repeat('á', 20).'  '])
            ->assertCreated()
            ->assertJsonPath('data.body', str_repeat('á', 20));
        $this->createReference($guideToken, $guide, $match, ['body' => str_repeat('z', 2000)])->assertCreated();
        // El texto se guarda tal cual: los clientes lo escapan al mostrarlo.
        $this->assertSame(str_repeat('z', 2000), Reference::query()->where('author_profile_id', $guide->id)->value('body'));
    }

    public function test_listing_by_direction_with_cursor_is_owner_only_and_allowed_with_expired_access(): void
    {
        [$token, $company, $companyOwner] = $this->actor('list-company@example.test');
        [$otherToken] = $this->actor('list-other@example.test');
        $authored = [];
        $received = [];
        foreach (['Uno', 'Dos', 'Tres'] as $index => $name) {
            [$guideToken, $guide] = $this->actor('list-guide-'.$index.'@example.test', ProfileCategory::Guide);
            $match = $this->acceptedMatch($token, $company, $guideToken, $guide);
            $authored[] = (string) $this->createReference($token, $company, $match)->assertCreated()->json('data.id');
            $received[] = (string) $this->createReference($guideToken, $guide, $match)->assertCreated()->json('data.id');
        }

        $this->assertSame(array_reverse($authored), $this->ids($this->listReferences($token, $company, '?direction=authored')->assertOk()));
        $this->assertSame(array_reverse($received), $this->ids($this->listReferences($token, $company, '?direction=received')->assertOk()));
        $all = $this->listReferences($token, $company)->assertOk()
            ->assertJsonPath('meta.next_cursor', null)
            ->assertJsonPath('meta.per_page', 20);
        $this->assertSame(['data', 'meta'], array_keys((array) $all->json()));
        $this->assertCount(6, $all->json('data'));
        foreach ((array) $all->json('data') as $item) {
            $this->assertSame(self::REFERENCE_KEYS, array_keys((array) $item));
        }

        // Cursor numérico: páginas sin duplicados ni huecos, más nuevas primero.
        $seen = [];
        $cursor = null;
        do {
            $page = $this->listReferences($token, $company, '?per_page=4'.($cursor === null ? '' : '&cursor='.$cursor))->assertOk();
            $seen = [...$seen, ...$this->ids($page)];
            $cursor = $page->json('meta.next_cursor');
        } while ($cursor !== null);
        $this->assertSame($this->ids($all), $seen);
        $this->assertSame(count($seen), count(array_unique($seen)));

        // Contrato: authored|received; `sent` no existe para referencias.
        foreach (['?direction=sent', '?per_page=51', '?cursor=abc', '?status=visible'] as $query) {
            $this->listReferences($token, $company, $query)->assertUnprocessable();
        }
        // Solo el titular.
        $this->listReferences($otherToken, $company)->assertNotFound();
        // Historial con acceso vencido (D21) sí; cuenta suspendida no.
        AccessGrant::query()->whereKey($company->id)->update(['expires_at' => '2026-11-10 11:00:00+00']);
        $this->listReferences($token, $company)->assertOk()->assertJsonCount(6, 'data');
        User::query()->whereKey($companyOwner->id)->update(['status' => User::STATUS_SUSPENDED]);
        $this->listReferences($token, $company)->assertForbidden()->assertJsonPath('error.code', 'account_suspended');
    }

    public function test_only_the_subject_changes_visibility_idempotently_and_never_over_a_moderation(): void
    {
        [$token, $company] = $this->actor('vis-company@example.test');
        [$guideToken, $guide, $guideOwner] = $this->actor('vis-guide@example.test', ProfileCategory::Guide);
        [$thirdToken, $third] = $this->actor('vis-third@example.test', ProfileCategory::Guide);
        [, $adminToken] = $this->registerAdmin('vis-admin@example.test');
        $match = $this->acceptedMatch($token, $company, $guideToken, $guide);
        $id = (string) $this->createReference($token, $company, $match)->assertCreated()->json('data.id');
        $body = (string) Reference::query()->where('public_id', $id)->value('body');
        Event::fake([DirectoryChanged::class]);

        // Destinatario publica: un evento del destinatario.
        $this->setVisibility($guideToken, $guide, $id, 'visible')->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.visibility', 'visible')
            ->assertJsonPath('data.body', $body);
        Event::assertDispatchedTimes(DirectoryChanged::class, 1);
        Event::assertDispatched(DirectoryChanged::class, fn (DirectoryChanged $event): bool => $event->profileId === $guide->public_id);

        // Mismo valor: 200 sin cambios ni evento.
        $this->clock->set(CarbonImmutable::parse('2026-11-10T13:00:00Z'));
        $updatedAt = DB::table('references')->where('public_id', $id)->value('updated_at');
        $this->setVisibility($guideToken, $guide, $id, 'visible')->assertOk()->assertJsonPath('data.visibility', 'visible');
        Event::assertDispatchedTimes(DirectoryChanged::class, 1);
        $this->assertSame($updatedAt, DB::table('references')->where('public_id', $id)->value('updated_at'));

        // Autor: participante equivocado → 403; terceros → 404.
        $this->setVisibility($token, $company, $id, 'hidden')->assertForbidden()->assertJsonPath('error.code', 'forbidden');
        $this->setVisibility($thirdToken, $third, $id, 'hidden')->assertNotFound();
        $this->setVisibility($thirdToken, $guide, $id, 'hidden')->assertNotFound();
        $this->setVisibility($guideToken, $guide, (string) Str::ulid(), 'hidden')->assertNotFound();
        // Validación: valor fuera del enum, campos extra (no se edita el texto).
        $this->setVisibility($guideToken, $guide, $id, 'public')->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['visibility']]]);
        $this->setVisibility($guideToken, $guide, $id, 'hidden', ['body' => str_repeat('x', 30)])
            ->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['body']]]);
        $this->assertSame('visible', Reference::query()->where('public_id', $id)->value('visibility'));
        $this->assertSame($body, Reference::query()->where('public_id', $id)->value('body'));

        // Vencido o inactivo: sí (D21).
        AccessGrant::query()->whereKey($guide->id)->update(['expires_at' => '2026-11-10 11:00:00+00']);
        $this->setVisibility($guideToken, $guide, $id, 'hidden')->assertOk()->assertJsonPath('data.visibility', 'hidden');
        AccessGrant::query()->whereKey($guide->id)->update(['expires_at' => null, 'status' => AccessGrantStatus::Inactive->value]);
        $this->setVisibility($guideToken, $guide, $id, 'visible')->assertOk()->assertJsonPath('data.visibility', 'visible');
        // Suspendido: no.
        AccessGrant::query()->whereKey($guide->id)->update(['status' => AccessGrantStatus::Active->value]);
        Profile::query()->whereKey($guide->id)->update(['status' => ProfileStatus::Suspended->value]);
        $this->setVisibility($guideToken, $guide, $id, 'hidden')->assertForbidden()->assertJsonPath('error.code', 'profile_suspended');
        Profile::query()->whereKey($guide->id)->update(['status' => ProfileStatus::Published->value]);
        User::query()->whereKey($guideOwner->id)->update(['status' => User::STATUS_SUSPENDED]);
        $this->setVisibility($guideToken, $guide, $id, 'hidden')->assertForbidden()->assertJsonPath('error.code', 'account_suspended');
        User::query()->whereKey($guideOwner->id)->update(['status' => User::STATUS_ACTIVE]);
        $this->assertSame('visible', Reference::query()->where('public_id', $id)->value('visibility'));

        // Moderada: 409 para cualquier valor, también el vigente.
        $this->moderate($adminToken, $id, 'hide')->assertOk();
        $eventsBefore = count(Event::dispatched(DirectoryChanged::class));
        foreach (['hidden', 'visible'] as $value) {
            $this->setVisibility($guideToken, $guide, $id, $value)->assertStatus(409)->assertJsonPath('error.code', 'reference_moderated');
        }
        $this->assertCount($eventsBefore, Event::dispatched(DirectoryChanged::class));
        $this->assertSame('visible', Reference::query()->where('public_id', $id)->value('visibility'));

        // Tras restaurar vuelve a decidir el destinatario.
        $this->moderate($adminToken, $id, 'restore')->assertOk();
        $this->setVisibility($guideToken, $guide, $id, 'hidden')->assertOk()->assertJsonPath('data.visibility', 'hidden');
    }

    public function test_moderation_reason_is_visible_to_both_parties_in_their_lists(): void
    {
        [$token, $company] = $this->actor('reason-company@example.test');
        [$guideToken, $guide] = $this->actor('reason-guide@example.test', ProfileCategory::Guide);
        [, $adminToken] = $this->registerAdmin('reason-admin@example.test');
        $match = $this->acceptedMatch($token, $company, $guideToken, $guide);
        $id = (string) $this->createReference($token, $company, $match)->assertCreated()->json('data.id');

        $this->moderate($adminToken, $id, 'hide', '  Incluye datos personales  ')->assertOk();

        foreach ([[$token, $company, 'authored'], [$guideToken, $guide, 'received']] as [$actorToken, $actor, $direction]) {
            $this->listReferences($actorToken, $actor, '?direction='.$direction)->assertOk()
                ->assertJsonPath('data.0.id', $id)
                ->assertJsonPath('data.0.moderated', true)
                ->assertJsonPath('data.0.moderation_reason', 'Incluye datos personales');
        }
    }

    public function test_directory_profile_shows_only_visible_and_unmoderated_references_newest_first(): void
    {
        [$guideToken, $guide] = $this->actor('dir-guide@example.test', ProfileCategory::Guide);
        [$viewerToken, $viewer] = $this->actor('dir-viewer@example.test');
        [, $adminToken] = $this->registerAdmin('dir-admin@example.test');
        $this->directoryProfile($viewerToken, $viewer, $guide)->assertOk()->assertJsonPath('data.visible_references', []);

        // Cuentas y matches primero: el JWT se emite con el reloj de la app en T0.
        $matches = [];
        foreach (['visible-a', 'oculta', 'moderada', 'visible-b'] as $label) {
            [$companyToken, $company] = $this->actor('dir-'.$label.'@example.test');
            $matches[$label] = [$companyToken, $company, $this->acceptedMatch($companyToken, $company, $guideToken, $guide)];
        }
        $ids = [];
        foreach (array_keys($matches) as $index => $label) {
            [$companyToken, $company, $match] = $matches[$label];
            $this->clock->set(CarbonImmutable::parse(self::T0)->addMinutes($index + 1));
            $ids[$label] = (string) $this->createReference($companyToken, $company, $match, [
                'body' => 'Referencia '.$label.' sobre la coordinación del match.',
            ])->assertCreated()->json('data.id');
            // La guía también escribe la suya: nunca aparece en su propia ficha.
            $this->createReference($guideToken, $guide, $match)->assertCreated();
        }
        foreach (['visible-a', 'moderada', 'visible-b'] as $label) {
            $this->setVisibility($guideToken, $guide, $ids[$label], 'visible')->assertOk();
        }
        $this->moderate($adminToken, $ids['moderada'], 'hide', 'Motivo interno de moderación')->assertOk();

        $response = $this->directoryProfile($viewerToken, $viewer, $guide)->assertOk();
        $public = (array) $response->json('data.visible_references');
        $this->assertSame([$ids['visible-b'], $ids['visible-a']], array_column($public, 'id'));
        $this->assertSame('Referencia visible-b sobre la coordinación del match.', $public[0]['body']);
        $this->assertSame('2026-11-10T12:04:00.000000Z', $public[0]['created_at']);
        $targetKeys = array_keys((array) $response->json('data.profile'));
        foreach ($public as $item) {
            // PublicReference exacto: sin visibilidad, moderación, motivo ni solicitud.
            $this->assertSame(['id', 'author', 'body', 'created_at'], array_keys((array) $item));
            // El autor sale con el mismo SafeProfile que el target de la ficha.
            $this->assertSame($targetKeys, array_keys((array) $item['author']));
            $this->assertSame(ProfileCategory::TourismCompany->value, $item['author']['category']);
        }
        $content = (string) $response->getContent();
        foreach (['Referencia oculta', 'Referencia moderada', 'Motivo interno', '"visibility"', '"moderated"', 'moderation_reason', 'contact_request_id', 'contact_email', '"phone"'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $content);
        }

        // Ocultar una visible la retira; restaurar la moderada la devuelve (seguía visible).
        $this->setVisibility($guideToken, $guide, $ids['visible-a'], 'hidden')->assertOk();
        $this->moderate($adminToken, $ids['moderada'], 'restore', 'Revisión completada')->assertOk();
        $this->assertSame(
            [$ids['visible-b'], $ids['moderada']],
            array_column((array) $this->directoryProfile($viewerToken, $viewer, $guide)->assertOk()->json('data.visible_references'), 'id'),
        );

        // Tope configurable: solo las N más nuevas.
        config(['contactur.references.directory_limit' => 1]);
        $this->assertSame(
            [$ids['visible-b']],
            array_column((array) $this->directoryProfile($viewerToken, $viewer, $guide)->assertOk()->json('data.visible_references'), 'id'),
        );
    }

    public function test_directory_references_do_not_add_queries_per_reference(): void
    {
        [$guideToken, $guide] = $this->actor('n1-guide@example.test', ProfileCategory::Guide);
        [$viewerToken, $viewer] = $this->actor('n1-viewer@example.test');
        $publish = function (int $count) use ($guideToken, $guide): void {
            for ($i = 0; $i < $count; $i++) {
                [$companyToken, $company] = $this->actor('n1-company-'.Str::lower(Str::random(6)).'@example.test');
                $match = $this->acceptedMatch($companyToken, $company, $guideToken, $guide);
                $id = (string) $this->createReference($companyToken, $company, $match)->assertCreated()->json('data.id');
                $this->setVisibility($guideToken, $guide, $id, 'visible')->assertOk();
            }
        };

        $publish(1);
        $withOne = $this->countQueries(fn () => $this->directoryProfile($viewerToken, $viewer, $guide)->assertOk()->assertJsonCount(1, 'data.visible_references'));
        $publish(4);
        $withFive = $this->countQueries(fn () => $this->directoryProfile($viewerToken, $viewer, $guide)->assertOk()->assertJsonCount(5, 'data.visible_references'));

        $this->assertSame($withOne, $withFive);
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

    /**
     * @param  TestResponse<Response>  $response
     * @return list<string>
     */
    private function ids(TestResponse $response): array
    {
        return array_values(array_map(static fn (array $item): string => (string) $item['id'], (array) $response->json('data')));
    }
}
