<?php

namespace App\Console\Commands;

use App\Contracts\Clock;
use App\Models\User;
use App\Repositories\UserRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\Console\Exception\RuntimeException as ConsoleRuntimeException;

/**
 * Única vía para conseguir el primer `system_role = admin`. El registro público
 * siempre crea cuentas con rol `user` y ningún endpoint cambia el rol a propósito:
 * promover por API sería una escalada de privilegios. Esta operación vive en consola,
 * donde el operador ya tiene acceso al servidor.
 */
final class ManageAdminAccount extends Command
{
    private const PASSWORD_MIN = 12;

    private const PASSWORD_MAX = 128;

    /** @var string */
    protected $signature = 'contactur:admin
        {email : Email de la cuenta de administración}
        {--create : Crea la cuenta si el email no existe; sin esta bandera un email inexistente falla}
        {--name= : Nombre de la cuenta nueva; solo se usa junto a --create}
        {--demote : Devuelve la cuenta al rol user en lugar de promoverla}
        {--force : Omite la confirmación interactiva en entornos de producción}';

    /** @var string */
    protected $description = 'Promueve, crea o degrada la cuenta de administración del backoffice.';

    public function handle(UserRepository $users, Clock $clock): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));
        $create = (bool) $this->option('create');
        $demote = (bool) $this->option('demote');

        if ($create && $demote) {
            $this->components->error('--create y --demote son excluyentes: elegí una sola operación.');

            return self::FAILURE;
        }

        if ($this->invalid(['email' => $email], ['email' => ['required', 'string', 'email:rfc', 'max:254']])) {
            $this->components->error('El email indicado no es válido.');

            return self::FAILURE;
        }

        if (! $this->confirmProductionWrite()) {
            $this->components->warn('Operación cancelada: no se modificó ninguna cuenta.');

            return self::FAILURE;
        }

        $user = $users->findByEmail($email);

        if ($user === null) {
            return $demote
                ? $this->missingAccount($email, 'No hay nada que degradar.')
                : $this->createAdmin($users, $clock, $email, $create);
        }

        return $demote
            ? $this->demote($users, $user)
            : $this->promote($users, $user, $create);
    }

    private function missingAccount(string $email, string $tail): int
    {
        $this->components->error(sprintf('No existe una cuenta con el email %s. %s', $email, $tail));

        return self::FAILURE;
    }

    private function createAdmin(UserRepository $users, Clock $clock, string $email, bool $create): int
    {
        if (! $create) {
            return $this->missingAccount(
                $email,
                'Revisá que no haya un error de tipeo; si realmente querés crearla, volvé a ejecutar con --create. Sin esa bandera no se creó nada.',
            );
        }

        $name = $this->resolveName();

        if ($name === null) {
            return self::FAILURE;
        }

        $password = $this->askForPassword();

        if ($password === null) {
            return self::FAILURE;
        }

        $user = DB::transaction(fn (): User => $users->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'system_role' => User::ROLE_ADMIN,
            'status' => User::STATUS_ACTIVE,
            // El admin se crea verificado a propósito: no puede recibir el correo de
            // verificación antes de existir y sin cuenta no hay backoffice alcanzable.
            'email_verified_at' => $clock->now(),
            'locale' => 'es-AR',
            'timezone' => 'America/Argentina/Buenos_Aires',
            'notification_preferences' => [],
        ]));

        $this->report('Cuenta creada con rol admin, activa y con el email verificado.', $user);

        return self::SUCCESS;
    }

    private function promote(UserRepository $users, User $user, bool $create): int
    {
        if ($create) {
            $this->components->warn('Ya existía una cuenta con ese email: se promueve la existente y --create se ignora.');
        }

        if ($user->system_role === User::ROLE_ADMIN) {
            $this->report('La cuenta ya tenía rol admin: no se modificó nada.', $user);

            return self::SUCCESS;
        }

        $user = $users->updateSystemRole($user, User::ROLE_ADMIN);
        $this->report('Cuenta promovida a rol admin. El estado de la cuenta quedó sin cambios.', $user);
        $this->warnAboutBackofficeAccess($user);

        return self::SUCCESS;
    }

    private function demote(UserRepository $users, User $user): int
    {
        if ($user->system_role === User::ROLE_USER) {
            $this->report('La cuenta ya tenía rol user: no se modificó nada.', $user);

            return self::SUCCESS;
        }

        $user = $users->updateSystemRole($user, User::ROLE_USER);
        $this->report('Cuenta degradada a rol user. Perdió el acceso al backoffice.', $user);

        return self::SUCCESS;
    }

    private function resolveName(): ?string
    {
        $option = $this->option('name');
        $name = is_string($option) ? trim($option) : '';

        if ($name === '' && $this->input->isInteractive()) {
            $name = trim((string) $this->ask('Nombre de la cuenta', 'Administración Contactur'));
        }

        if ($this->invalid(['name' => $name], ['name' => ['required', 'string', 'min:2', 'max:120']])) {
            $this->components->error('El nombre debe tener entre 2 y 120 caracteres: pasalo con --name.');

            return null;
        }

        return $name;
    }

    /**
     * La contraseña se pide oculta y nunca se acepta como argumento ni se genera
     * para imprimirla: un argumento queda en el historial del shell y en la lista
     * de procesos, y una contraseña impresa queda en el scrollback y en los logs
     * de CI. Con secret() el secreto no aparece en ninguna salida del comando.
     */
    private function askForPassword(): ?string
    {
        if (! $this->input->isInteractive()) {
            $this->components->error('La contraseña solo se pide de forma interactiva: ejecutá el comando con una terminal real, por ejemplo "docker compose exec -it backend php artisan contactur:admin ... --create".');

            return null;
        }

        try {
            $password = (string) $this->secret(
                sprintf('Contraseña de la cuenta (mínimo %d caracteres, no se muestra)', self::PASSWORD_MIN),
                false,
            );
            $confirmation = (string) $this->secret('Repetí la contraseña', false);
        } catch (ConsoleRuntimeException) {
            $this->components->error('No se pudo leer la contraseña sin mostrarla en pantalla. Usá una terminal interactiva ("docker compose exec -it ...").');

            return null;
        }

        if ($password !== $confirmation) {
            $this->components->error('Las contraseñas no coinciden: no se creó ninguna cuenta.');

            return null;
        }

        if ($this->invalid(
            ['password' => $password],
            ['password' => ['required', 'string', 'min:'.self::PASSWORD_MIN, 'max:'.self::PASSWORD_MAX]],
        )) {
            $this->components->error(sprintf(
                'La contraseña debe tener entre %d y %d caracteres: no se creó ninguna cuenta.',
                self::PASSWORD_MIN,
                self::PASSWORD_MAX,
            ));

            return null;
        }

        return $password;
    }

    private function confirmProductionWrite(): bool
    {
        if (! $this->laravel->environment('production') || (bool) $this->option('force')) {
            return true;
        }

        return $this->confirm(
            'Estás en producción y vas a cambiar privilegios de administración. ¿Confirmás?',
            false,
        );
    }

    private function warnAboutBackofficeAccess(User $user): void
    {
        if ($user->status !== User::STATUS_ACTIVE) {
            $this->components->warn(sprintf(
                'La cuenta está en estado "%s" y el backoffice exige una cuenta activa.',
                $user->status,
            ));
        }

        if ($user->email_verified_at === null) {
            $this->components->warn('La cuenta no tiene el email verificado; el comando no lo marca por vos sobre cuentas que ya existían.');
        }
    }

    /**
     * Solo se imprime identificación pública y estado: nunca el hash de la
     * contraseña ni tokens de sesión o verificación.
     */
    private function report(string $headline, User $user): void
    {
        $this->components->info($headline);
        $this->components->twoColumnDetail('public_id', $user->public_id);
        $this->components->twoColumnDetail('email', $user->email);
        $this->components->twoColumnDetail('system_role', $user->system_role);
        $this->components->twoColumnDetail('status', $user->status);
        $this->components->twoColumnDetail(
            'email_verified_at',
            $user->email_verified_at?->toIso8601String() ?? 'sin verificar',
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, array<int, string>>  $rules
     */
    private function invalid(array $data, array $rules): bool
    {
        return Validator::make($data, $rules)->fails();
    }
}
