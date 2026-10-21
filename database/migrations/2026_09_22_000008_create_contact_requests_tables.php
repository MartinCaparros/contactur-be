<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * T17 (R13/D13/D22).
     *
     * - `contact_requests`: el índice único parcial `WHERE status = 'pending'`
     *   garantiza en la base una sola pendiente por par (emisor, destino) aun ante
     *   escrituras concurrentes. `status`/`created_at` alimentan las métricas admin.
     * - `contact_usage`: cuota mensual por perfil emisor y mes de negocio
     *   (America/Argentina/Buenos_Aires); la fila se bloquea al consumir.
     * - `idempotency_keys`: perfil actor + actor + operación + key con hash del
     *   payload normalizado y snapshot de la respuesta original.
     */
    public function up(): void
    {
        Schema::create('contact_requests', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique();
            $table->foreignId('sender_profile_id')->constrained('profiles')->cascadeOnDelete();
            $table->foreignId('recipient_profile_id')->constrained('profiles')->cascadeOnDelete();
            $table->text('service_description');
            $table->date('requested_from')->nullable();
            $table->date('requested_to')->nullable();
            $table->foreignId('requested_city_id')->nullable()->constrained('cities')->restrictOnDelete();
            $table->string('status', 20);
            $table->string('availability_status', 20);
            $table->timestampTz('expires_at');
            $table->timestampTz('responded_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampsTz();

            $table->index(['sender_profile_id', 'id'], 'contact_requests_sender_id_index');
            $table->index(['recipient_profile_id', 'id'], 'contact_requests_recipient_id_index');
            $table->index(['sender_profile_id', 'recipient_profile_id'], 'contact_requests_pair_index');
            $table->index(['status', 'expires_at'], 'contact_requests_status_expires_index');
        });

        DB::statement("ALTER TABLE contact_requests ADD CONSTRAINT contact_requests_status_check CHECK (status IN ('pending', 'accepted', 'rejected', 'expired', 'cancelled'))");
        DB::statement("ALTER TABLE contact_requests ADD CONSTRAINT contact_requests_availability_status_check CHECK (availability_status IN ('available', 'changed', 'unavailable', 'unspecified'))");
        DB::statement('ALTER TABLE contact_requests ADD CONSTRAINT contact_requests_distinct_profiles_check CHECK (sender_profile_id <> recipient_profile_id)');
        DB::statement('ALTER TABLE contact_requests ADD CONSTRAINT contact_requests_range_check CHECK ((requested_from IS NULL) = (requested_to IS NULL) AND (requested_to IS NULL OR requested_to >= requested_from))');
        DB::statement("CREATE UNIQUE INDEX contact_requests_pending_pair_unique ON contact_requests (sender_profile_id, recipient_profile_id) WHERE status = 'pending'");

        Schema::create('contact_usage', function (Blueprint $table): void {
            $table->foreignId('profile_id')->constrained('profiles')->cascadeOnDelete();
            $table->date('month_start');
            $table->unsignedInteger('used')->default(0);
            $table->timestampsTz();

            $table->primary(['profile_id', 'month_start'], 'contact_usage_profile_month_primary');
        });

        DB::statement('ALTER TABLE contact_usage ADD CONSTRAINT contact_usage_used_check CHECK (used >= 0)');

        Schema::create('idempotency_keys', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('profile_id')->constrained('profiles')->cascadeOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('operation', 64);
            $table->string('idempotency_key', 128);
            $table->char('request_hash', 64);
            $table->unsignedSmallInteger('response_status');
            // json (no jsonb): conserva el orden de claves para reproducir el mismo cuerpo.
            $table->json('response_body');
            $table->timestampTz('created_at');

            $table->unique(
                ['profile_id', 'actor_user_id', 'operation', 'idempotency_key'],
                'idempotency_keys_scope_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('contact_usage');
        Schema::dropIfExists('contact_requests');
    }
};
