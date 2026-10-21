<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_grants', function (Blueprint $table): void {
            $table->foreignId('profile_id')->primary()->constrained('profiles')->cascadeOnDelete();
            $table->string('status', 20);
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->unsignedInteger('city_limit')->nullable();
            $table->unsignedInteger('monthly_contact_limit')->nullable();
            $table->boolean('gallery_enabled')->default(false);
            $table->unsignedBigInteger('version');
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->timestampsTz();
            $table->index(['status', 'expires_at']);
        });

        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('target_type', 100);
            $table->string('target_id', 100);
            $table->string('action', 100);
            $table->text('reason')->nullable();
            $table->jsonb('safe_changes');
            $table->string('request_id', 100);
            $table->timestampTz('created_at');
            $table->index(['target_type', 'target_id', 'id']);
            $table->index(['action', 'id']);
            $table->index('created_at');
        });

        DB::statement("ALTER TABLE access_grants ADD CONSTRAINT access_grants_status_check CHECK (status IN ('inactive', 'active', 'suspended'))");
        DB::statement('ALTER TABLE access_grants ADD CONSTRAINT access_grants_version_check CHECK (version > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('access_grants');
    }
};
