<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('name', 120);
            $table->string('email', 254);
            $table->string('pending_email', 254)->nullable();
            $table->timestampTz('pending_email_requested_at')->nullable();
            $table->timestampTz('email_verified_at')->nullable();
            $table->string('password');
            $table->string('system_role', 20)->default('user');
            $table->string('status', 20)->default('active');
            $table->string('locale', 10)->default('es-AR');
            $table->string('timezone', 64)->default('America/Argentina/Buenos_Aires');
            $table->jsonb('notification_preferences')->default('[]');
            $table->rememberToken();
            $table->timestampsTz();

            $table->index('pending_email');
            $table->index('status');
        });

        DB::statement('CREATE UNIQUE INDEX users_email_lower_unique ON users (LOWER(email))');

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email', 254)->primary();
            $table->string('token');
            $table->timestampTz('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index()->constrained()->nullOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
