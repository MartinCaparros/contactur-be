<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        $missing = [
            'public_id' => ! Schema::hasColumn('users', 'public_id'),
            'pending_email' => ! Schema::hasColumn('users', 'pending_email'),
            'pending_email_requested_at' => ! Schema::hasColumn('users', 'pending_email_requested_at'),
            'email_verified_at' => ! Schema::hasColumn('users', 'email_verified_at'),
            'system_role' => ! Schema::hasColumn('users', 'system_role'),
            'status' => ! Schema::hasColumn('users', 'status'),
            'locale' => ! Schema::hasColumn('users', 'locale'),
            'timezone' => ! Schema::hasColumn('users', 'timezone'),
            'notification_preferences' => ! Schema::hasColumn('users', 'notification_preferences'),
            'created_at' => ! Schema::hasColumn('users', 'created_at'),
            'updated_at' => ! Schema::hasColumn('users', 'updated_at'),
        ];

        if (in_array(true, $missing, true)) {
            Schema::table('users', function (Blueprint $table) use ($missing): void {
                if ($missing['public_id']) {
                    $table->ulid('public_id')->nullable();
                }

                if ($missing['pending_email']) {
                    $table->string('pending_email', 254)->nullable();
                }

                if ($missing['pending_email_requested_at']) {
                    $table->timestampTz('pending_email_requested_at')->nullable();
                }

                if ($missing['email_verified_at']) {
                    $table->timestampTz('email_verified_at')->nullable();
                }

                if ($missing['system_role']) {
                    $table->string('system_role', 20)->default('user');
                }

                if ($missing['status']) {
                    $table->string('status', 20)->default('active');
                }

                if ($missing['locale']) {
                    $table->string('locale', 10)->default('es-AR');
                }

                if ($missing['timezone']) {
                    $table->string('timezone', 64)->default('America/Argentina/Buenos_Aires');
                }

                if ($missing['notification_preferences']) {
                    $table->jsonb('notification_preferences')->default('[]');
                }

                if ($missing['created_at']) {
                    $table->timestampTz('created_at')->nullable();
                }

                if ($missing['updated_at']) {
                    $table->timestampTz('updated_at')->nullable();
                }
            });
        }

        $this->backfillPublicIds();
        $this->enforceUserDefaults();

        DB::statement('ALTER TABLE users ALTER COLUMN public_id SET NOT NULL');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS users_public_id_unique ON users (public_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS users_pending_email_index ON users (pending_email)');
        DB::statement('CREATE INDEX IF NOT EXISTS users_status_index ON users (status)');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS users_email_lower_unique ON users (LOWER(email))');

        $this->addSessionsUserForeignKeyWhenSafe();
    }

    public function down(): void
    {
        // Forward-only data migration: removing these columns would destroy account data.
    }

    private function backfillPublicIds(): void
    {
        DB::table('users')
            ->select('id')
            ->where(function ($query): void {
                $query->whereNull('public_id')
                    ->orWhereRaw("BTRIM(public_id::text) = ''");
            })
            ->orderBy('id')
            ->chunkById(500, function ($users): void {
                foreach ($users as $user) {
                    do {
                        $publicId = (string) Str::ulid();
                    } while (DB::table('users')->where('public_id', $publicId)->exists());

                    DB::table('users')
                        ->where('id', $user->id)
                        ->where(function ($query): void {
                            $query->whereNull('public_id')
                                ->orWhereRaw("BTRIM(public_id::text) = ''");
                        })
                        ->update(['public_id' => $publicId]);
                }
            }, 'id');

        $hasInvalidPublicId = DB::table('users')
            ->whereNull('public_id')
            ->orWhereRaw("public_id::text !~ '^[0-9A-HJKMNP-TV-Z]{26}$'")
            ->exists();

        if ($hasInvalidPublicId) {
            throw new RuntimeException('users.public_id contains a non-ULID value; migration stopped without rewriting existing identifiers.');
        }

        $hasDuplicatePublicId = DB::query()
            ->fromSub(
                DB::table('users')
                    ->select('public_id')
                    ->groupBy('public_id')
                    ->havingRaw('COUNT(*) > 1'),
                'duplicate_public_ids',
            )
            ->exists();

        if ($hasDuplicatePublicId) {
            throw new RuntimeException('users.public_id contains duplicate values; migration stopped without rewriting existing identifiers.');
        }
    }

    private function enforceUserDefaults(): void
    {
        DB::table('users')->whereNull('system_role')->update(['system_role' => 'user']);
        DB::table('users')->whereNull('status')->update(['status' => 'active']);
        DB::table('users')->whereNull('locale')->update(['locale' => 'es-AR']);
        DB::table('users')->whereNull('timezone')->update(['timezone' => 'America/Argentina/Buenos_Aires']);
        DB::table('users')->whereNull('notification_preferences')->update([
            'notification_preferences' => DB::raw("'[]'::jsonb"),
        ]);

        DB::statement("ALTER TABLE users ALTER COLUMN system_role SET DEFAULT 'user'");
        DB::statement('ALTER TABLE users ALTER COLUMN system_role SET NOT NULL');
        DB::statement("ALTER TABLE users ALTER COLUMN status SET DEFAULT 'active'");
        DB::statement('ALTER TABLE users ALTER COLUMN status SET NOT NULL');
        DB::statement("ALTER TABLE users ALTER COLUMN locale SET DEFAULT 'es-AR'");
        DB::statement('ALTER TABLE users ALTER COLUMN locale SET NOT NULL');
        DB::statement("ALTER TABLE users ALTER COLUMN timezone SET DEFAULT 'America/Argentina/Buenos_Aires'");
        DB::statement('ALTER TABLE users ALTER COLUMN timezone SET NOT NULL');
        DB::statement("ALTER TABLE users ALTER COLUMN notification_preferences SET DEFAULT '[]'::jsonb");
        DB::statement('ALTER TABLE users ALTER COLUMN notification_preferences SET NOT NULL');
    }

    private function addSessionsUserForeignKeyWhenSafe(): void
    {
        if (
            ! Schema::hasTable('sessions')
            || ! Schema::hasColumn('sessions', 'user_id')
            || $this->sessionsUserForeignKeyExists()
        ) {
            return;
        }

        $hasOrphans = DB::table('sessions as sessions')
            ->leftJoin('users as users', 'users.id', '=', 'sessions.user_id')
            ->whereNotNull('sessions.user_id')
            ->whereNull('users.id')
            ->exists();

        if ($hasOrphans) {
            throw new RuntimeException('sessions.user_id contains orphan values; migration stopped without deleting or rewriting sessions.');
        }

        Schema::table('sessions', function (Blueprint $table): void {
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    private function sessionsUserForeignKeyExists(): bool
    {
        $result = DB::selectOne(<<<'SQL'
            SELECT EXISTS (
                SELECT 1
                FROM information_schema.table_constraints AS constraints
                INNER JOIN information_schema.key_column_usage AS columns
                    ON columns.constraint_schema = constraints.constraint_schema
                    AND columns.constraint_name = constraints.constraint_name
                INNER JOIN information_schema.constraint_column_usage AS referenced_columns
                    ON referenced_columns.constraint_schema = constraints.constraint_schema
                    AND referenced_columns.constraint_name = constraints.constraint_name
                WHERE constraints.constraint_schema = current_schema()
                    AND constraints.table_name = 'sessions'
                    AND constraints.constraint_type = 'FOREIGN KEY'
                    AND columns.column_name = 'user_id'
                    AND referenced_columns.table_name = 'users'
                    AND referenced_columns.column_name = 'id'
            ) AS exists
            SQL);

        return (bool) $result->exists;
    }
};
