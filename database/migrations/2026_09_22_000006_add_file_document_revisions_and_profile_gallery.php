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
        Schema::table('files', function (Blueprint $table): void {
            $table->ulid('public_id')->nullable()->unique()->after('id');
            $table->string('original_filename', 255)->nullable()->after('private_path');
            $table->unsignedInteger('width')->nullable()->after('size');
            $table->unsignedInteger('height')->nullable()->after('width');
            $table->timestampTz('storage_deleted_at')->nullable()->after('checksum');
        });

        DB::table('files')->orderBy('id')->chunkById(100, function ($files): void {
            foreach ($files as $file) {
                $filename = basename(str_replace('\\', '/', (string) $file->private_path));
                DB::table('files')->where('id', $file->id)->update([
                    'public_id' => (string) Str::ulid(),
                    'original_filename' => mb_substr($filename !== '' ? $filename : 'archivo-'.$file->id, 0, 255),
                ]);
            }
        });

        DB::statement('ALTER TABLE files ALTER COLUMN public_id SET NOT NULL');
        DB::statement('ALTER TABLE files ALTER COLUMN original_filename SET NOT NULL');
        DB::statement("ALTER TABLE files ADD CONSTRAINT files_kind_check CHECK (kind IN ('document', 'avatar', 'gallery'))");
        DB::statement("ALTER TABLE files ADD CONSTRAINT files_disk_check CHECK (disk IN ('local', 'public'))");
        DB::statement('ALTER TABLE files ADD CONSTRAINT files_dimensions_check CHECK ((width IS NULL AND height IS NULL) OR (width > 0 AND height > 0))');

        Schema::table('profile_documents', function (Blueprint $table): void {
            $table->ulid('public_id')->nullable()->unique()->after('id');
            $table->unsignedInteger('revision')->default(1)->after('file_id');
        });

        DB::table('profile_documents')->orderBy('id')->chunkById(100, function ($documents): void {
            foreach ($documents as $document) {
                DB::table('profile_documents')->where('id', $document->id)->update([
                    'public_id' => (string) Str::ulid(),
                ]);
            }
        });
        DB::statement(<<<'SQL'
            WITH ranked AS (
                SELECT id, ROW_NUMBER() OVER (PARTITION BY profile_id, document_type_id ORDER BY id) AS revision_number
                FROM profile_documents
            )
            UPDATE profile_documents
            SET revision = ranked.revision_number
            FROM ranked
            WHERE profile_documents.id = ranked.id
            SQL);
        DB::statement('ALTER TABLE profile_documents ALTER COLUMN public_id SET NOT NULL');
        DB::statement('ALTER TABLE profile_documents DROP CONSTRAINT profile_documents_status_check');
        DB::statement("ALTER TABLE profile_documents ADD CONSTRAINT profile_documents_status_check CHECK (status IN ('pending', 'approved', 'rejected', 'superseded', 'retired'))");
        DB::statement('ALTER TABLE profile_documents ADD CONSTRAINT profile_documents_revision_check CHECK (revision > 0)');
        DB::statement(<<<'SQL'
            ALTER TABLE profile_documents
            ADD CONSTRAINT profile_documents_review_check
            CHECK (
                status IN ('superseded', 'retired')
                OR (
                    status = 'pending'
                    AND reviewed_by IS NULL
                    AND reviewed_at IS NULL
                    AND rejection_reason IS NULL
                )
                OR (
                    status = 'approved'
                    AND reviewed_by IS NOT NULL
                    AND reviewed_at IS NOT NULL
                    AND rejection_reason IS NULL
                )
                OR (
                    status = 'rejected'
                    AND reviewed_by IS NOT NULL
                    AND reviewed_at IS NOT NULL
                    AND rejection_reason IS NOT NULL
                )
            )
            SQL);

        Schema::table('profile_documents', function (Blueprint $table): void {
            $table->unique(['profile_id', 'document_type_id', 'revision'], 'profile_documents_revision_unique');
            $table->index(['status', 'id'], 'profile_documents_review_queue_index');
        });

        Schema::create('profile_gallery', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique();
            $table->foreignId('profile_id')->constrained('profiles')->cascadeOnDelete();
            $table->foreignId('file_id')->constrained('files')->restrictOnDelete();
            $table->unsignedSmallInteger('position');
            $table->timestampsTz();
            $table->unique(['profile_id', 'file_id']);
            $table->unique(['profile_id', 'position']);
        });

        DB::statement('ALTER TABLE profile_gallery ADD CONSTRAINT profile_gallery_position_check CHECK (position >= 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE profile_documents DROP CONSTRAINT IF EXISTS profile_documents_review_check');
        DB::statement('ALTER TABLE profile_documents DROP CONSTRAINT IF EXISTS profile_documents_revision_check');
        DB::statement('ALTER TABLE files DROP CONSTRAINT IF EXISTS files_dimensions_check');
        DB::statement('ALTER TABLE files DROP CONSTRAINT IF EXISTS files_disk_check');
        DB::statement('ALTER TABLE files DROP CONSTRAINT IF EXISTS files_kind_check');

        Schema::dropIfExists('profile_gallery');

        Schema::table('profile_documents', function (Blueprint $table): void {
            $table->dropIndex('profile_documents_review_queue_index');
            $table->dropUnique('profile_documents_revision_unique');
        });
        DB::statement('ALTER TABLE profile_documents DROP CONSTRAINT profile_documents_status_check');
        DB::table('profile_documents')
            ->whereIn('status', ['superseded', 'retired'])
            ->update(['status' => 'rejected']);
        DB::statement("ALTER TABLE profile_documents ADD CONSTRAINT profile_documents_status_check CHECK (status IN ('pending', 'approved', 'rejected'))");
        Schema::table('profile_documents', function (Blueprint $table): void {
            $table->dropUnique(['public_id']);
            $table->dropColumn(['public_id', 'revision']);
        });

        Schema::table('files', function (Blueprint $table): void {
            $table->dropUnique(['public_id']);
            $table->dropColumn(['public_id', 'original_filename', 'width', 'height', 'storage_deleted_at']);
        });
    }
};
