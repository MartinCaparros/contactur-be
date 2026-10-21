<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * T20 (R17/D14/P25).
     *
     * - Nombre `references` como en docs/modelo-datos.md. Es palabra reservada de
     *   PostgreSQL: el query builder siempre la cita ("references"); cualquier SQL
     *   crudo debe citarla también.
     * - Una referencia por (match, autor, destinatario): el índice único es la
     *   garantía ante creaciones concurrentes; el servicio traduce su violación a 409.
     * - `visibility` la decide el destinatario (default hidden). La moderación
     *   administrativa es independiente: `moderated_at`/`moderated_by`/
     *   `moderation_reason` van juntos (todos o ninguno) y restaurar los limpia.
     * - El texto no se edita nunca: no hay columna de edición ni endpoint de update.
     */
    public function up(): void
    {
        Schema::create('references', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique();
            $table->foreignId('contact_request_id')->constrained('contact_requests')->cascadeOnDelete();
            $table->foreignId('author_profile_id')->constrained('profiles')->cascadeOnDelete();
            $table->foreignId('subject_profile_id')->constrained('profiles')->cascadeOnDelete();
            $table->text('body');
            $table->string('visibility', 10)->default('hidden');
            $table->timestampTz('moderated_at')->nullable();
            $table->foreignId('moderated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('moderation_reason')->nullable();
            $table->timestampsTz();
            $table->unique(
                ['contact_request_id', 'author_profile_id', 'subject_profile_id'],
                'references_request_author_subject_unique',
            );
            // Listados del titular (emitidas/recibidas, más nuevas primero).
            $table->index(['author_profile_id', 'id'], 'references_author_id_index');
            $table->index(['subject_profile_id', 'id'], 'references_subject_id_index');
        });
        DB::statement("ALTER TABLE \"references\" ADD CONSTRAINT references_visibility_check CHECK (visibility IN ('hidden', 'visible'))");
        DB::statement('ALTER TABLE "references" ADD CONSTRAINT references_distinct_profiles_check CHECK (author_profile_id <> subject_profile_id)');
        DB::statement('ALTER TABLE "references" ADD CONSTRAINT references_body_length_check CHECK (char_length(body) BETWEEN 20 AND 2000)');
        DB::statement('ALTER TABLE "references" ADD CONSTRAINT references_moderation_consistency_check CHECK ((moderated_at IS NULL) = (moderation_reason IS NULL) AND (moderated_at IS NULL) = (moderated_by IS NULL))');
        // Ficha de directorio: solo visibles y no moderadas del destinatario.
        DB::statement("CREATE INDEX references_public_subject_index ON \"references\" (subject_profile_id, id DESC) WHERE visibility = 'visible' AND moderated_at IS NULL");
    }

    public function down(): void
    {
        Schema::dropIfExists('references');
    }
};
