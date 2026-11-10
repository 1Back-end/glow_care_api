<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::dropIfExists('establishment_availability_days');

        Schema::create('establishment_availability_days', function (Blueprint $table) {
            $table->uuid('uuid')->primary();

            $table->uuid('establishment_uuid');
            $table->foreign('establishment_uuid')
                ->references('uuid')
                ->on('establishments')
                ->cascadeOnDelete();

            $table->string('day_of_week');

            $table->time('opening_time')->nullable();
            $table->time('closing_time')->nullable();

            $table->boolean('is_open')->default(true);

            $table->timestamps();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            // Nom court pour éviter l'erreur des 64 caractères de MySQL
            $table->unique(['establishment_uuid', 'day_of_week'], 'est_avail_days_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('establishment_availability_days');
    }
};
