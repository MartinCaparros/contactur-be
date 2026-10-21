<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Solo existen filas para días habilitados: la ausencia de fila es "bloqueado"
     * (default de R08). El unique (profile_id, date) garantiza una sola ciudad por
     * fecha y perfil (R09/D11) aun ante escrituras concurrentes; el índice
     * (city_id, date) sirve a la búsqueda por ciudad y rango de T14.
     */
    public function up(): void
    {
        Schema::create('availability_days', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('profile_id')->constrained('profiles')->cascadeOnDelete();
            $table->foreignId('city_id')->constrained('cities')->restrictOnDelete();
            $table->date('date');
            $table->timestampsTz();
            $table->unique(['profile_id', 'date'], 'availability_days_profile_date_unique');
            $table->index(['city_id', 'date'], 'availability_days_city_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('availability_days');
    }
};
