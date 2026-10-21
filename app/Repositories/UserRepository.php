<?php

namespace App\Repositories;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class UserRepository
{
    /**
     * Carácter de escape explícito para LIKE: evita depender de
     * standard_conforming_strings y permite tratar %, _ y el propio escape
     * como literales cuando llegan en el término de búsqueda.
     */
    private const LIKE_ESCAPE = '!';

    public function findById(int $id): ?User
    {
        return User::query()->find($id);
    }

    public function findByEmail(string $normalizedEmail): ?User
    {
        return User::query()
            ->whereRaw('LOWER(email) = ?', [$normalizedEmail])
            ->first();
    }

    public function emailBelongsToAnotherUser(string $normalizedEmail, int $userId): bool
    {
        return User::query()
            ->whereRaw('LOWER(email) = ?', [$normalizedEmail])
            ->whereKeyNot($userId)
            ->exists();
    }

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): User
    {
        $user = new User;
        $user->forceFill($attributes);
        $user->save();

        return $user;
    }

    /** @param array<string, mixed> $attributes */
    public function update(User $user, array $attributes): User
    {
        $user->fill($attributes);
        $user->save();

        return $user->refresh();
    }

    public function lockByPublicId(string $publicId): ?User
    {
        return User::query()
            ->where('public_id', $publicId)
            ->lockForUpdate()
            ->first();
    }

    /**
     * users.status no es fillable a propósito; la escritura administrativa es la
     * única vía habilitada y pasa por forceFill para no abrir mass assignment.
     */
    public function updateStatus(User $user, string $status): User
    {
        $user->forceFill(['status' => $status])->save();

        return $user->refresh();
    }

    /**
     * users.system_role tampoco es fillable: el rol de sistema solo se escribe
     * desde consola (contactur:admin) y pasa por forceFill para no abrir mass
     * assignment ni permitir una escalada de privilegios vía payload HTTP.
     */
    public function updateSystemRole(User $user, string $systemRole): User
    {
        $user->forceFill(['system_role' => $systemRole])->save();

        return $user->refresh();
    }

    /**
     * @return array{items: Collection<int, User>, next_cursor: string|null, total: int}
     */
    public function listForAdmin(
        ?string $search,
        ?string $status,
        ?int $cursor,
        int $perPage,
    ): array {
        $base = User::query()
            ->when($status !== null, fn ($query) => $query->where('users.status', $status))
            ->when($search !== null, function ($query) use ($search): void {
                $pattern = '%'.$this->escapeLike((string) $search).'%';
                $query->where(function ($inner) use ($pattern): void {
                    $inner
                        ->whereRaw("LOWER(users.name) LIKE ? ESCAPE '".self::LIKE_ESCAPE."'", [$pattern])
                        ->orWhereRaw("LOWER(users.email) LIKE ? ESCAPE '".self::LIKE_ESCAPE."'", [$pattern]);
                });
            });
        $total = (clone $base)->count();
        $items = $base
            ->when($cursor !== null, fn ($query) => $query->where('users.id', '<', $cursor))
            ->withCount('profiles')
            ->orderByDesc('users.id')
            ->limit($perPage + 1)
            ->get();
        $hasMore = $items->count() > $perPage;
        /** @var Collection<int, User> $page */
        $page = $items->take($perPage)->values();

        return [
            'items' => $page,
            'next_cursor' => $hasMore ? (string) $page->last()?->id : null,
            'total' => $total,
        ];
    }

    /**
     * Contador agregado por estado de cuenta. "Fecha" acá es `users.created_at`, la
     * fecha de alta de la cuenta: el rango decide qué cuentas entran, no cuándo
     * cambiaron de estado. El estado contado es siempre el vigente.
     *
     * @return array<string, int>
     */
    public function countByStatus(?CarbonImmutable $createdFrom, ?CarbonImmutable $createdUntil): array
    {
        $counts = [];
        $rows = DB::table('users')
            ->when($createdFrom !== null, fn ($query) => $query->where('users.created_at', '>=', $createdFrom))
            ->when($createdUntil !== null, fn ($query) => $query->where('users.created_at', '<', $createdUntil))
            ->groupBy('users.status')
            ->selectRaw('users.status AS status, COUNT(*) AS total')
            ->get();

        foreach ($rows as $row) {
            $counts[(string) $row->status] = (int) $row->total;
        }

        return $counts;
    }

    private function escapeLike(string $term): string
    {
        return str_replace(
            [self::LIKE_ESCAPE, '%', '_'],
            [self::LIKE_ESCAPE.self::LIKE_ESCAPE, self::LIKE_ESCAPE.'%', self::LIKE_ESCAPE.'_'],
            mb_strtolower(trim($term)),
        );
    }
}
