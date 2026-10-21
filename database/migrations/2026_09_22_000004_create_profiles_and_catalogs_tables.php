<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cities', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('name', 120);
            $table->string('province_code', 10);
            $table->char('country_code', 2)->default('AR');
            $table->string('timezone', 64);
            $table->timestampsTz();
            $table->unique(['name', 'province_code', 'country_code']);
        });

        foreach (['languages', 'services', 'specialties'] as $catalog) {
            Schema::create($catalog, function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('code', 80)->unique();
                $table->string('name', 160);
                $table->timestampsTz();
            });
        }

        Schema::create('document_types', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('code', 100);
            $table->string('category', 40);
            $table->string('label', 200);
            $table->boolean('requires_expiration')->default(false);
            $table->boolean('required')->default(true);
            $table->string('jurisdiction', 120)->nullable();
            $table->timestampsTz();
            $table->unique(['category', 'code']);
        });

        Schema::create('profiles', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique();
            $table->foreignId('owner_user_id')->constrained('users')->restrictOnDelete();
            $table->string('category', 40);
            $table->string('display_name', 160);
            $table->string('slug', 190)->unique();
            $table->text('description')->nullable();
            $table->foreignId('primary_city_id')->nullable()->constrained('cities')->restrictOnDelete();
            $table->unsignedBigInteger('avatar_file_id')->nullable();
            $table->unsignedSmallInteger('onboarding_step')->default(1);
            $table->timestampTz('completed_at')->nullable();
            $table->string('status', 20)->default('draft');
            $table->unsignedBigInteger('availability_version')->default(0);
            $table->timestampsTz();
            $table->index(['owner_user_id', 'status']);
            $table->index(['category', 'status']);
        });

        Schema::create('profile_contacts', function (Blueprint $table): void {
            $table->foreignId('profile_id')->primary()->constrained('profiles')->cascadeOnDelete();
            $table->string('contact_email', 254)->nullable();
            $table->string('phone', 40)->nullable();
            $table->timestampsTz();
        });

        Schema::create('company_details', function (Blueprint $table): void {
            $table->foreignId('profile_id')->primary()->constrained('profiles')->cascadeOnDelete();
            $table->string('legal_name', 200);
            $table->unsignedInteger('years_active')->nullable();
            $table->timestampsTz();
        });

        Schema::create('guide_details', function (Blueprint $table): void {
            $table->foreignId('profile_id')->primary()->constrained('profiles')->cascadeOnDelete();
            $table->text('education_summary')->nullable();
            $table->timestampsTz();
        });

        Schema::create('driver_details', function (Blueprint $table): void {
            $table->foreignId('profile_id')->primary()->constrained('profiles')->cascadeOnDelete();
            $table->string('license_type', 120)->nullable();
            $table->string('vehicle_type', 120)->nullable();
            $table->unsignedInteger('vehicle_capacity')->nullable();
            $table->timestampsTz();
        });

        Schema::create('instructor_details', function (Blueprint $table): void {
            $table->foreignId('profile_id')->primary()->constrained('profiles')->cascadeOnDelete();
            $table->text('training_summary')->nullable();
            $table->timestampsTz();
        });

        Schema::create('transport_details', function (Blueprint $table): void {
            $table->foreignId('profile_id')->primary()->constrained('profiles')->cascadeOnDelete();
            $table->string('legal_name', 200);
            $table->text('fleet_description')->nullable();
            $table->timestampsTz();
        });

        Schema::create('profile_cities', function (Blueprint $table): void {
            $table->foreignId('profile_id')->constrained('profiles')->cascadeOnDelete();
            $table->foreignId('city_id')->constrained('cities')->restrictOnDelete();
            $table->primary(['profile_id', 'city_id']);
        });

        Schema::create('profile_language', function (Blueprint $table): void {
            $table->foreignId('profile_id')->constrained('profiles')->cascadeOnDelete();
            $table->foreignId('language_id')->constrained('languages')->restrictOnDelete();
            $table->primary(['profile_id', 'language_id']);
        });

        Schema::create('profile_service', function (Blueprint $table): void {
            $table->foreignId('profile_id')->constrained('profiles')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('services')->restrictOnDelete();
            $table->primary(['profile_id', 'service_id']);
        });

        Schema::create('profile_specialty', function (Blueprint $table): void {
            $table->foreignId('profile_id')->constrained('profiles')->cascadeOnDelete();
            $table->foreignId('specialty_id')->constrained('specialties')->restrictOnDelete();
            $table->primary(['profile_id', 'specialty_id']);
        });

        Schema::create('qualifications', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('profile_id')->constrained('profiles')->cascadeOnDelete();
            $table->string('title', 160);
            $table->string('institution', 160);
            $table->date('completed_on');
            $table->date('expires_on')->nullable();
            $table->timestampsTz();
        });

        Schema::create('fleet_vehicles', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('profile_id')->constrained('profiles')->cascadeOnDelete();
            $table->string('vehicle_type', 120);
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('capacity');
            $table->timestampsTz();
        });

        Schema::create('files', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('owner_profile_id')->nullable()->constrained('profiles')->cascadeOnDelete();
            $table->foreignId('uploader_user_id')->constrained('users')->restrictOnDelete();
            $table->string('kind', 30);
            $table->string('disk', 40);
            $table->string('private_path', 500);
            $table->string('mime', 120);
            $table->unsignedBigInteger('size');
            $table->char('checksum', 64);
            $table->timestampTz('created_at')->useCurrent();
        });

        Schema::table('profiles', function (Blueprint $table): void {
            $table->foreign('avatar_file_id')->references('id')->on('files')->nullOnDelete();
        });

        Schema::create('profile_documents', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('profile_id')->constrained('profiles')->cascadeOnDelete();
            $table->foreignId('document_type_id')->constrained('document_types')->restrictOnDelete();
            $table->foreignId('file_id')->constrained('files')->restrictOnDelete();
            $table->string('registration_number', 160)->nullable();
            $table->date('valid_until')->nullable();
            $table->string('status', 20)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestampsTz();
            $table->index(['profile_id', 'document_type_id', 'status']);
        });

        Schema::create('terms_acceptances', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('profile_id')->constrained('profiles')->cascadeOnDelete();
            $table->string('version', 50);
            $table->timestampTz('accepted_at');
            $table->unique(['user_id', 'profile_id', 'version']);
        });

        DB::statement("ALTER TABLE profiles ADD CONSTRAINT profiles_category_check CHECK (category IN ('tourism_company', 'guide', 'driver', 'instructor', 'transport_company'))");
        DB::statement("ALTER TABLE profiles ADD CONSTRAINT profiles_status_check CHECK (status IN ('draft', 'published', 'suspended'))");
        DB::statement('ALTER TABLE profiles ADD CONSTRAINT profiles_onboarding_step_check CHECK (onboarding_step BETWEEN 1 AND 4)');
        DB::statement("ALTER TABLE document_types ADD CONSTRAINT document_types_category_check CHECK (category IN ('tourism_company', 'guide', 'driver', 'instructor', 'transport_company'))");
        DB::statement('ALTER TABLE driver_details ADD CONSTRAINT driver_details_capacity_check CHECK (vehicle_capacity IS NULL OR vehicle_capacity > 0)');
        DB::statement('ALTER TABLE fleet_vehicles ADD CONSTRAINT fleet_vehicles_values_check CHECK (quantity > 0 AND capacity > 0)');
        DB::statement('ALTER TABLE qualifications ADD CONSTRAINT qualifications_dates_check CHECK (expires_on IS NULL OR expires_on >= completed_on)');
        DB::statement("ALTER TABLE profile_documents ADD CONSTRAINT profile_documents_status_check CHECK (status IN ('pending', 'approved', 'rejected'))");
        DB::statement('ALTER TABLE files ADD CONSTRAINT files_size_check CHECK (size > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('terms_acceptances');
        Schema::dropIfExists('profile_documents');
        Schema::table('profiles', function (Blueprint $table): void {
            $table->dropForeign(['avatar_file_id']);
        });
        Schema::dropIfExists('files');
        Schema::dropIfExists('fleet_vehicles');
        Schema::dropIfExists('qualifications');
        Schema::dropIfExists('profile_specialty');
        Schema::dropIfExists('profile_service');
        Schema::dropIfExists('profile_language');
        Schema::dropIfExists('profile_cities');
        Schema::dropIfExists('transport_details');
        Schema::dropIfExists('instructor_details');
        Schema::dropIfExists('driver_details');
        Schema::dropIfExists('guide_details');
        Schema::dropIfExists('company_details');
        Schema::dropIfExists('profile_contacts');
        Schema::dropIfExists('profiles');
        Schema::dropIfExists('document_types');
        Schema::dropIfExists('specialties');
        Schema::dropIfExists('services');
        Schema::dropIfExists('languages');
        Schema::dropIfExists('cities');
    }
};
