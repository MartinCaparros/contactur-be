<?php

namespace Tests\Feature;

use App\Contracts\Clock;
use App\Enums\AccessGrantStatus;
use App\Enums\NotificationType;
use App\Enums\ProfileCategory;
use App\Enums\ProfileStatus;
use App\Models\AccessGrant;
use App\Models\Profile;
use App\Models\ProfileNotification;
use App\Models\User;
use App\Services\NotificationService;
use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use stdClass;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FrozenClock;
use Tests\TestCase;

/**
 * T19 lote A — centro de notificaciones (R05, R18, R21): listado por perfil con
 * eventos de cuenta, filtro unread, cursor sin duplicados, lectura idempotente y
 * aislamiento entre perfiles de la misma cuenta y de otras cuentas.
 */
final class NotificationCenterTest extends TestCase
{
    use RefreshDatabase;

    private const T0 = '2026-11-10T12:00:00Z';

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

    public function test_r05_each_profile_lists_its_own_and_account_level_notifications_newest_first(): void
    {
        [$token, $owner] = $this->account('center-owner@example.test');
        $company = $this->profile($owner, ProfileCategory::TourismCompany, 'Empresa Centro');
        $guide = $this->profile($owner, ProfileCategory::Guide, 'Guía Centro');
        [$otherToken, $other] = $this->account('center-other@example.test');
        $foreign = $this->profile($other, ProfileCategory::Guide, 'Guía Ajena');

        $companyOld = $this->recordFor($owner, $company, 'empresa-1');
        $this->clock->set($this->clock->now()->addMinute());
        $guideOnly = $this->recordFor($owner, $guide, 'guia-1');
        $foreignOnly = $this->recordFor($other, $foreign, 'ajena-1');
        $companyNew = $this->recordFor($owner, $company, 'empresa-2');
        $welcome = $this->publicIdOf($owner, NotificationType::Welcome);

        $companyList = $this->list($token, $company)->assertOk();
        $this->assertSame([$companyNew, $companyOld, $welcome], $companyList->json('data.*.id'));
        $this->assertSame(['next_cursor' => null, 'per_page' => 20], $companyList->json('meta'));
        $this->assertContractShape($companyList->getContent());

        // El otro perfil de la misma cuenta: su propia fila y la bienvenida, nunca las de la empresa.
        $guideList = $this->list($token, $guide)->assertOk();
        $this->assertSame([$guideOnly, $welcome], $guideList->json('data.*.id'));
        $this->assertNotContains($foreignOnly, $guideList->json('data.*.id'));

        // Ownership: perfil ajeno → 404, igual que el resto de /profiles/{profile}/…
        $this->list($otherToken, $company)->assertNotFound()->assertJsonPath('error.code', 'not_found');
        $this->list($token, $foreign)->assertNotFound();
        $this->withoutToken()->getJson('/api/v1/profiles/'.$company->public_id.'/notifications')->assertUnauthorized();

        // El payload de cuenta trae objeto vacío, no array.
        $this->assertStringContainsString('"type":"welcome","safe_data":{}', (string) $companyList->getContent());
    }

    public function test_cursor_pagination_has_no_duplicates_and_unread_filters(): void
    {
        [$token, $owner] = $this->account('cursor-owner@example.test');
        $profile = $this->profile($owner, ProfileCategory::Guide, 'Guía Cursor');
        $created = [];
        foreach (range(1, 5) as $index) {
            $created[] = $this->recordFor($owner, $profile, 'cursor-'.$index);
        }
        $expected = array_merge(array_reverse($created), [$this->publicIdOf($owner, NotificationType::Welcome)]);

        $seen = [];
        $cursor = null;
        $pages = 0;
        do {
            $query = '?per_page=2'.($cursor !== null ? '&cursor='.$cursor : '');
            $page = $this->list($token, $profile, $query)->assertOk();
            $this->assertLessThanOrEqual(2, count((array) $page->json('data')));
            $this->assertSame(2, $page->json('meta.per_page'));
            array_push($seen, ...$page->json('data.*.id'));
            $cursor = $page->json('meta.next_cursor');
            $pages++;
        } while ($cursor !== null && $pages < 10);
        $this->assertSame($expected, $seen);
        $this->assertSame(3, $pages);

        $this->read($token, $profile, $created[0])->assertOk();
        $this->read($token, $profile, $created[3])->assertOk();
        $unread = $this->list($token, $profile, '?unread=true')->assertOk()->json('data.*.id');
        $this->assertSame([$created[4], $created[2], $created[1], $expected[5]], $unread);
        $this->assertCount(6, (array) $this->list($token, $profile, '?unread=false')->json('data'));
        $this->assertCount(4, (array) $this->list($token, $profile, '?unread=1')->json('data'));

        $this->list($token, $profile, '?unread=quizas')->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['unread']]]);
        $this->list($token, $profile, '?per_page=51')->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['per_page']]]);
        $this->list($token, $profile, '?cursor=abc')->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['cursor']]]);
        $this->list($token, $profile, '?status=x')->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['status']]]);
    }

    public function test_marking_read_is_idempotent_and_an_account_level_notification_is_read_once(): void
    {
        [$token, $owner] = $this->account('read-owner@example.test');
        $company = $this->profile($owner, ProfileCategory::TourismCompany, 'Empresa Lectura');
        $guide = $this->profile($owner, ProfileCategory::Guide, 'Guía Lectura');
        [$otherToken, $other] = $this->account('read-other@example.test');
        $foreign = $this->profile($other, ProfileCategory::Guide, 'Guía Ajena Lectura');
        $guideOnly = $this->recordFor($owner, $guide, 'lectura-guia');
        $welcome = $this->publicIdOf($owner, NotificationType::Welcome);

        $this->clock->set(CarbonImmutable::parse('2026-11-10T13:00:00Z'));
        $first = $this->read($token, $company, $welcome)->assertOk()
            ->assertJsonPath('data.id', $welcome)
            ->assertJsonPath('data.type', 'welcome')
            ->assertJsonPath('data.read_at', '2026-11-10T13:00:00.000000Z');
        $this->assertSame(['data'], array_keys((array) $first->json()));

        // Segunda lectura (más tarde y desde el otro perfil propio): 200 con el mismo read_at.
        $this->clock->set(CarbonImmutable::parse('2026-11-10T15:00:00Z'));
        $this->read($token, $company, $welcome)->assertOk()->assertJsonPath('data.read_at', '2026-11-10T13:00:00.000000Z');
        $this->read($token, $guide, $welcome)->assertOk()->assertJsonPath('data.read_at', '2026-11-10T13:00:00.000000Z');
        $this->assertNotContains($welcome, $this->list($token, $guide, '?unread=true')->json('data.*.id'));

        // Fila de otro perfil propio, de otra cuenta o inexistente → 404 sin tocar nada.
        $this->read($token, $company, $guideOnly)->assertNotFound();
        $this->read($otherToken, $foreign, $guideOnly)->assertNotFound();
        $this->read($otherToken, $company, $guideOnly)->assertNotFound();
        $this->read($token, $company, (string) Str::ulid())->assertNotFound();
        $this->assertNull(ProfileNotification::query()->where('public_id', $guideOnly)->value('read_at'));

        // Sin body en el contrato.
        $this->withToken($token)
            ->postJson('/api/v1/profiles/'.$guide->public_id.'/notifications/'.$guideOnly.'/read', ['read' => true])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['fields' => ['read']]]);
        $this->assertNull(ProfileNotification::query()->where('public_id', $guideOnly)->value('read_at'));
    }

    public function test_a_suspended_profile_can_still_read_why_it_was_suspended(): void
    {
        [$token, $owner] = $this->account('suspended-reader@example.test');
        $profile = $this->profile($owner, ProfileCategory::Guide, 'Guía Suspendida');
        $id = $this->recordFor($owner, $profile, 'suspension');
        Profile::query()->whereKey($profile->id)->update(['status' => ProfileStatus::Suspended->value]);

        $this->list($token, $profile)->assertOk()->assertJsonPath('data.0.id', $id);
        $this->read($token, $profile, $id)->assertOk();
    }

    private function assertContractShape(string|false $content): void
    {
        $payload = json_decode((string) $content, false, 512, JSON_THROW_ON_ERROR);
        $this->assertInstanceOf(stdClass::class, $payload);
        $this->assertSame(['data', 'meta'], array_keys((array) $payload));
        $types = array_map(static fn (NotificationType $type): string => $type->value, NotificationType::cases());

        foreach ($payload->data as $item) {
            $this->assertSame(['id', 'type', 'safe_data', 'read_at', 'created_at'], array_keys((array) $item));
            $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $item->id);
            $this->assertContains($item->type, $types);
            $this->assertInstanceOf(stdClass::class, $item->safe_data);
            $this->assertNotFalse(CarbonImmutable::createFromFormat('Y-m-d\TH:i:s.u\Z', $item->created_at));
        }
    }

    private function recordFor(User $owner, Profile $profile, string $key): string
    {
        DB::transaction(fn (): bool => app(NotificationService::class)->record(
            $owner,
            $profile,
            NotificationType::AdministrativeChange,
            ['change' => 'access_updated', 'profile_id' => $profile->public_id, 'access_grant_version' => 2],
            'test:'.$key,
        ));

        return (string) ProfileNotification::query()->where('dedupe_key', 'test:'.$key)->value('public_id');
    }

    private function publicIdOf(User $owner, NotificationType $type): string
    {
        return (string) ProfileNotification::query()
            ->where('user_id', $owner->id)
            ->where('type', $type->value)
            ->value('public_id');
    }

    /** @return TestResponse<Response> */
    private function list(string $token, Profile $profile, string $query = ''): TestResponse
    {
        return $this->withToken($token)->getJson('/api/v1/profiles/'.$profile->public_id.'/notifications'.$query);
    }

    /** @return TestResponse<Response> */
    private function read(string $token, Profile $profile, string $id): TestResponse
    {
        return $this->withToken($token)->postJson('/api/v1/profiles/'.$profile->public_id.'/notifications/'.$id.'/read');
    }

    /** @return array{string, User} */
    private function account(string $email): array
    {
        $token = (string) $this->postJson('/api/v1/auth/register', [
            'name' => 'Persona de Prueba',
            'email' => $email,
            'password' => 'Clave-segura-2026',
            'password_confirmation' => 'Clave-segura-2026',
        ])->assertCreated()->json('data.access_token');
        $owner = User::query()->where('email', $email)->firstOrFail();
        DB::table('auth_sessions')->where('user_id', $owner->id)->update(['expires_at' => '2030-01-01 00:00:00+00']);

        return [$token, $owner];
    }

    private function profile(User $owner, ProfileCategory $category, string $name): Profile
    {
        $profile = Profile::factory()->create([
            'owner_user_id' => $owner->id,
            'category' => $category,
            'display_name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(8)),
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

        return $profile;
    }
}
