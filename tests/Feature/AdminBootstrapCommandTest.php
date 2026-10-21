<?php

namespace Tests\Feature;

use App\Models\AccountToken;
use App\Models\AuthSession;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

final class AdminBootstrapCommandTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Clave-de-consola-2026';

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    public function test_command_promotes_an_existing_account_without_touching_its_state(): void
    {
        $user = User::factory()->create([
            'email' => 'promovible@example.test',
            'name' => 'Rosa Promovible',
        ]);
        $this->assertSame(User::ROLE_USER, $user->system_role);
        $verifiedAt = $user->fresh()?->email_verified_at?->getTimestamp();
        $this->assertNotNull($verifiedAt);

        [$status, $output] = $this->runAdminCommand('Promovible@example.test');

        $this->assertSame(0, $status);
        $user->refresh();
        $this->assertSame(User::ROLE_ADMIN, $user->system_role);
        $this->assertSame(User::STATUS_ACTIVE, $user->status);
        $this->assertSame('Rosa Promovible', $user->name);
        $this->assertSame('promovible@example.test', $user->email);
        $this->assertSame($verifiedAt, $user->email_verified_at?->getTimestamp());
        $this->assertStringContainsString('promovida a rol admin', $output);
        $this->assertStringContainsString($user->public_id, $output);
        $this->assertSame(1, User::query()->count());
    }

    public function test_command_creates_a_verified_admin_only_when_creation_is_requested(): void
    {
        [$status, $output] = $this->runAdminCommand(
            ' Nueva-Admin@Example.test ',
            ['--create' => true, '--name' => 'Administración Contactur'],
            self::PASSWORD,
        );

        $this->assertSame(0, $status);
        $user = User::query()->where('email', 'nueva-admin@example.test')->firstOrFail();
        $this->assertSame(User::ROLE_ADMIN, $user->system_role);
        $this->assertSame(User::STATUS_ACTIVE, $user->status);
        $this->assertSame('Administración Contactur', $user->name);
        $this->assertNotNull($user->email_verified_at);
        $this->assertStringContainsString($user->public_id, $output);
        $this->assertStringContainsString('email verificado', $output);
        // La creación por consola no abre sesión ni emite token de verificación.
        $this->assertSame(0, AuthSession::query()->count());
        $this->assertSame(0, AccountToken::query()->count());
    }

    public function test_command_refuses_an_unknown_email_without_the_create_flag_and_writes_nothing(): void
    {
        [$status, $output] = $this->runAdminCommand('typo-en-el-email@example.test');

        $this->assertSame(1, $status);
        $this->assertSame(0, User::query()->count());
        $this->assertStringContainsString('No existe una cuenta con el email typo-en-el-email@example.test', $output);
        $this->assertStringContainsString('--create', $output);
    }

    public function test_command_demotes_an_admin_back_to_a_plain_user(): void
    {
        $user = User::factory()->create(['email' => 'ex-admin@example.test']);
        [$promoteStatus] = $this->runAdminCommand('ex-admin@example.test');
        $this->assertSame(0, $promoteStatus);
        $this->assertSame(User::ROLE_ADMIN, $user->refresh()->system_role);

        [$status, $output] = $this->runAdminCommand('ex-admin@example.test', ['--demote' => true]);

        $this->assertSame(0, $status);
        $this->assertSame(User::ROLE_USER, $user->refresh()->system_role);
        $this->assertSame(User::STATUS_ACTIVE, $user->status);
        $this->assertStringContainsString('degradada a rol user', $output);
        $this->assertStringContainsString($user->public_id, $output);
    }

    public function test_command_output_never_leaks_the_password_its_hash_or_any_token(): void
    {
        [$status, $output] = $this->runAdminCommand(
            'sin-filtraciones@example.test',
            ['--create' => true, '--name' => 'Administración Contactur'],
            self::PASSWORD,
        );

        $this->assertSame(0, $status);
        $user = User::query()->where('email', 'sin-filtraciones@example.test')->firstOrFail();
        $this->assertStringNotContainsString(self::PASSWORD, $output);
        $this->assertStringNotContainsString($user->password, $output);
        $this->assertStringNotContainsString('$2y$', $output);
        // Str::random y los hashes sha256/JWT del proyecto son tiras alfanuméricas
        // largas; el public_id son 26 caracteres, así que 40+ solo puede ser secreto.
        $this->assertDoesNotMatchRegularExpression('/[A-Za-z0-9]{40,}/', $output);
        $this->assertStringNotContainsString('password', mb_strtolower($output));
        $this->assertStringNotContainsString('token', mb_strtolower($output));
        $this->assertStringContainsString($user->public_id, $output);
    }

    public function test_admin_created_by_the_command_can_log_in_and_read_admin_metrics(): void
    {
        $this->seed(CatalogSeeder::class);
        [$status] = $this->runAdminCommand(
            'backoffice@example.test',
            ['--create' => true, '--name' => 'Administración Contactur'],
            self::PASSWORD,
        );
        $this->assertSame(0, $status);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'backoffice@example.test',
            'password' => self::PASSWORD,
        ])->assertOk()->json('data.access_token');

        $this->withToken((string) $token)
            ->getJson('/api/v1/admin/metrics')
            ->assertOk()
            ->assertJsonStructure(['data' => ['users']]);
    }

    public function test_command_requires_explicit_confirmation_in_production(): void
    {
        $user = User::factory()->create(['email' => 'produccion@example.test']);
        $this->app['env'] = 'production';

        [$status, $output] = $this->runAdminCommand('produccion@example.test');

        $this->assertSame(1, $status);
        $this->assertSame(User::ROLE_USER, $user->refresh()->system_role);
        $this->assertStringContainsString('cancelada', $output);

        [$forced] = $this->runAdminCommand('produccion@example.test', ['--force' => true]);

        $this->assertSame(0, $forced);
        $this->assertSame(User::ROLE_ADMIN, $user->refresh()->system_role);
    }

    /**
     * Ejecuta el comando con una entrada real para poder capturar la salida completa:
     * las respuestas ocultas se alimentan por stream, nunca por argumento.
     *
     * @param  array<string, mixed>  $options
     * @return array{int, string}
     */
    private function runAdminCommand(string $email, array $options = [], ?string $password = null): array
    {
        $input = new ArrayInput(array_merge(
            ['command' => 'contactur:admin', 'email' => $email],
            $options,
        ));
        $input->setInteractive($password !== null);

        if ($password !== null) {
            $stream = fopen('php://memory', 'r+');
            $this->assertNotFalse($stream);
            fwrite($stream, $password."\n".$password."\n");
            rewind($stream);
            $input->setStream($stream);
        }

        $output = new BufferedOutput;
        $status = $this->app->make(Kernel::class)->handle($input, $output);

        if (isset($stream) && is_resource($stream)) {
            fclose($stream);
        }

        return [$status, $output->fetch()];
    }
}
