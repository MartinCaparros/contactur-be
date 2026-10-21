<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * T19 (R18/R23).
     *
     * - `profile_notifications` y no `notifications`: User usa el trait Notifiable,
     *   cuya relación `notifications()` (DatabaseNotification) apunta a una tabla
     *   `notifications` con otro esquema (uuid, morphs, data text). Un nombre propio
     *   evita que el canal `database` del framework o `$user->notifications` lean o
     *   escriban esta tabla por accidente.
     *   Es además el outbox: `dedupe_key` UNIQUE hace idempotente el registro
     *   (INSERT … ON CONFLICT DO NOTHING) y `email_status` es el estado del correo
     *   que el job condiciona para no enviar dos veces. `profile_id` null = evento de
     *   cuenta (bienvenida, verificación, suspensión de cuenta).
     * - `support_inquiries`: consultas públicas. `ip_hash` es HMAC-SHA256 de la IP
     *   con la APP_KEY: permite correlacionar abuso sin guardar la IP en claro.
     */
    public function up(): void
    {
        Schema::create('profile_notifications', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('profile_id')->nullable()->constrained('profiles')->cascadeOnDelete();
            $table->string('type', 40);
            $table->jsonb('safe_data')->default('{}');
            $table->string('dedupe_key', 191)->unique();
            $table->string('email_status', 30);
            $table->timestampTz('emailed_at')->nullable();
            $table->timestampTz('read_at')->nullable();
            $table->timestampTz('created_at');
        });
        DB::statement("ALTER TABLE profile_notifications ADD CONSTRAINT profile_notifications_type_check CHECK (type IN ('welcome', 'email_verified', 'contact_request_received', 'contact_request_accepted', 'contact_request_rejected', 'contact_request_expired', 'administrative_change'))");
        DB::statement("ALTER TABLE profile_notifications ADD CONSTRAINT profile_notifications_email_status_check CHECK (email_status IN ('not_applicable', 'pending', 'sent', 'skipped_preference', 'failed'))");
        DB::statement("ALTER TABLE profile_notifications ADD CONSTRAINT profile_notifications_safe_data_object_check CHECK (jsonb_typeof(safe_data) = 'object')");
        // Listado por perfil (más nuevas primero) y su variante no leída.
        DB::statement('CREATE INDEX profile_notifications_profile_id_index ON profile_notifications (profile_id, id DESC) WHERE profile_id IS NOT NULL');
        DB::statement('CREATE INDEX profile_notifications_profile_unread_index ON profile_notifications (profile_id, id DESC) WHERE profile_id IS NOT NULL AND read_at IS NULL');
        // Eventos de cuenta: se suman al listado de cada perfil propio.
        DB::statement('CREATE INDEX profile_notifications_account_id_index ON profile_notifications (user_id, id DESC) WHERE profile_id IS NULL');
        DB::statement('CREATE INDEX profile_notifications_account_unread_index ON profile_notifications (user_id, id DESC) WHERE profile_id IS NULL AND read_at IS NULL');
        // Barrido del outbox: correos que quedaron pendientes.
        DB::statement("CREATE INDEX profile_notifications_email_pending_index ON profile_notifications (id) WHERE email_status = 'pending'");

        Schema::create('support_inquiries', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique();
            $table->string('name', 120);
            $table->string('email', 254);
            $table->string('category', 80)->nullable();
            $table->text('message');
            $table->string('status', 20)->default('pending');
            $table->char('ip_hash', 64);
            $table->timestampTz('created_at');
            $table->timestampTz('emailed_at')->nullable();
            $table->index(['status', 'created_at'], 'support_inquiries_status_created_index');
            $table->index(['ip_hash', 'created_at'], 'support_inquiries_ip_hash_created_index');
        });
        DB::statement("ALTER TABLE support_inquiries ADD CONSTRAINT support_inquiries_status_check CHECK (status IN ('pending', 'answered', 'discarded'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('support_inquiries');
        Schema::dropIfExists('profile_notifications');
    }
};
