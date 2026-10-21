<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auth_sessions', function (Blueprint $table) {
            $table->ulid('sid')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->ulid('family_id')->index();
            $table->char('refresh_hash', 64)->unique();
            $table->timestampTz('expires_at');
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('rotated_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->ulid('replaced_by_sid')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestampsTz();

            $table->index(['user_id', 'revoked_at']);
        });

        Schema::table('auth_sessions', function (Blueprint $table) {
            $table->foreign('replaced_by_sid')->references('sid')->on('auth_sessions')->nullOnDelete();
        });

        Schema::create('account_tokens', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('purpose', 40);
            $table->char('token_hash', 64)->unique();
            $table->string('target_email', 254)->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampsTz();

            $table->index(['user_id', 'purpose']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_tokens');
        Schema::dropIfExists('auth_sessions');
    }
};
