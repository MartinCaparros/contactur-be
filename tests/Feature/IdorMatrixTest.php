<?php

namespace Tests\Feature;

use App\Enums\ProfileCategory;
use App\Models\ProfileNotification;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Str;
use Tests\Support\ReferenceFixtures;
use Tests\TestCase;

/**
 * T22 — matriz de IDOR (R21, P07). En lugar de muestrear rutas, recorre TODAS las
 * rutas registradas bajo `api/v1/profiles/{profile}/…` y `api/v1/admin/…`:
 *
 * - Con el token de la cuenta A y el `{profile}` de la cuenta B, ninguna ruta
 *   responde 2xx ni devuelve datos de B (nombre visible, contacto, email).
 * - Con el perfil PROPIO de A y recursos anidados de B (solicitud, notificación,
 *   referencia), la respuesta es 404 sin datos de B.
 * - Un usuario sin rol admin recibe 403 en cada ruta admin.
 *
 * Una ruta nueva entra sola en la matriz: si alguien agrega un endpoint sin
 * chequeo de ownership, este test falla.
 */
final class IdorMatrixTest extends TestCase
{
    use ReferenceFixtures;
    use RefreshDatabase;

    private const T0 = '2026-11-10T15:00:00Z';

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Mail::fake();
        $this->seed(CatalogSeeder::class);
        $this->freezeReferenceClock(self::T0);
    }

    public function test_every_profile_route_rejects_a_profile_of_another_account_without_leaking_it(): void
    {
        [$tokenA] = $this->actor('idor-a@example.test', ProfileCategory::TourismCompany);
        [, $profileB] = $this->actor('idor-b@example.test', ProfileCategory::Guide);
        $profileB->contact()->updateOrCreate([], ['contact_email' => 'privado-b@example.test', 'phone' => '+5492901777777']);
        $needles = [$profileB->display_name, 'privado-b@example.test', '+5492901777777', 'idor-b@example.test'];

        $routes = $this->routesMatching('api/v1/profiles/{profile}');
        $this->assertGreaterThan(25, count($routes), 'la matriz debe cubrir todas las rutas de perfil');

        foreach ($routes as [$method, $uri]) {
            $path = $this->fill($uri, ['profile' => $profileB->public_id, 'target' => $profileB->public_id]);
            $response = $this->withToken($tokenA)->json($method, $path, []);
            $status = $response->getStatusCode();

            $this->assertContains(
                $status,
                [403, 404, 422],
                "{$method} {$uri} con un perfil ajeno respondió {$status}",
            );
            foreach ($needles as $needle) {
                $this->assertStringNotContainsString($needle, (string) $response->getContent(), "{$method} {$uri} filtró {$needle}");
            }
        }
    }

    public function test_own_profile_with_nested_resources_of_another_account_is_404(): void
    {
        [$tokenA, $profileA] = $this->actor('nested-a@example.test', ProfileCategory::TourismCompany);
        [$tokenB, $profileB] = $this->actor('nested-b@example.test', ProfileCategory::Guide);
        [$tokenC, $profileC] = $this->actor('nested-c@example.test', ProfileCategory::TourismCompany);

        // Match aceptado entre C y B, con referencia de C sobre B y la notificación de B.
        $requestId = $this->acceptedMatch($tokenC, $profileC, $tokenB, $profileB);
        $referenceId = (string) $this->createReference($tokenC, $profileC, $requestId)->assertCreated()->json('data.id');
        $notificationId = (string) ProfileNotification::query()->where('profile_id', $profileB->id)->value('public_id');
        $this->assertNotSame('', $notificationId);

        // Cada intento con el body válido de su contrato: así un 422 no tapa el chequeo de ownership.
        $attempts = [
            ['GET', "contact-requests/{$requestId}", []],
            ['GET', "contact-requests/{$requestId}/contact", []],
            ['POST', "contact-requests/{$requestId}/accept", []],
            ['POST', "contact-requests/{$requestId}/reject", []],
            ['POST', "contact-requests/{$requestId}/cancel", []],
            ['POST', "contact-requests/{$requestId}/references", ['body' => 'Intento de referencia ajena desde otro perfil.']],
            ['POST', "notifications/{$notificationId}/read", []],
            ['PATCH', "references/{$referenceId}/visibility", ['visibility' => 'visible']],
        ];

        foreach ($attempts as [$method, $suffix, $body]) {
            $response = $this->withToken($tokenA)->json($method, '/api/v1/profiles/'.$profileA->public_id.'/'.$suffix, $body);
            $this->assertContains($response->getStatusCode(), [403, 404], "{$method} {$suffix} con recurso ajeno respondió {$response->getStatusCode()}");
            foreach ([$profileB->display_name, $profileC->display_name, 'nested-b@example.test'] as $needle) {
                $this->assertStringNotContainsString($needle, (string) $response->getContent(), "{$method} {$suffix} filtró {$needle}");
            }
        }

        // La notificación ajena sigue sin leer y la referencia sigue oculta: nada se mutó.
        $this->assertNull(ProfileNotification::query()->where('public_id', $notificationId)->value('read_at'));
        $this->listReferences($tokenB, $profileB, '?direction=received')
            ->assertOk()
            ->assertJsonPath('data.0.visibility', 'hidden');
    }

    public function test_a_sibling_profile_of_the_same_account_cannot_act_on_resources_of_the_other(): void
    {
        [$tokenB, $guide] = $this->actor('sibling-b@example.test', ProfileCategory::Guide);
        $sibling = $this->siblingProfile($guide, 'Hermano del guía', ProfileCategory::Driver);
        [$tokenC, $company] = $this->actor('sibling-c@example.test', ProfileCategory::TourismCompany);
        $requestId = $this->createRequest($tokenC, $company, $guide);

        // Mismo token (misma cuenta), otro perfil: la solicitud es del guía, no del hermano (R05).
        foreach (['GET' => '', 'POST' => '/accept'] as $method => $suffix) {
            $this->withToken($tokenB)
                ->json($method, '/api/v1/profiles/'.$sibling->public_id.'/contact-requests/'.$requestId.$suffix)
                ->assertNotFound();
        }
        $this->withToken($tokenB)
            ->getJson('/api/v1/profiles/'.$sibling->public_id.'/contact-requests')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_every_admin_route_is_403_for_a_regular_account(): void
    {
        [$token] = $this->actor('no-admin@example.test', ProfileCategory::TourismCompany);
        $routes = $this->routesMatching('api/v1/admin');
        $this->assertGreaterThan(9, count($routes));

        foreach ($routes as [$method, $uri]) {
            $path = $this->fill($uri, []);
            $response = $this->withToken($token)->json($method, $path, ['reason' => 'Intento sin permisos', 'action' => 'hide']);
            $this->assertSame(403, $response->getStatusCode(), "{$method} {$uri} respondió {$response->getStatusCode()} a una cuenta sin rol admin");
        }
    }

    /** @return list<array{string, string}> */
    private function routesMatching(string $prefix): array
    {
        $routes = [];
        /** @var Route $route */
        foreach (RouteFacade::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), $prefix)) {
                continue;
            }
            foreach ($route->methods() as $method) {
                if ($method !== 'HEAD') {
                    $routes[] = [$method, $route->uri()];
                }
            }
        }

        return $routes;
    }

    /** @param  array<string, string>  $values  parámetros conocidos; el resto recibe un ULID al azar */
    private function fill(string $uri, array $values): string
    {
        return '/'.preg_replace_callback('/\{(\w+)\}/', fn (array $match): string => $values[$match[1]] ?? (string) Str::ulid(), $uri);
    }
}
