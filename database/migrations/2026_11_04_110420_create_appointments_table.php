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
        Schema::create('appointments', function (Blueprint $table) {
            $table->uuid('uuid')->unique();
            $table->string('code');
            $table->string('title')->nullable();

            $table->foreignUuid('establishment_uuid')
                ->constrained('establishments', 'uuid')
                ->onDelete('cascade');

            $table->foreignUuid('client_uuid')
                ->constrained('clients', 'uuid')
                ->onDelete('cascade');

            $table->foreignUuid('prestation_uuid')
                ->constrained('prestations', 'uuid')
                ->onDelete('cascade');

            $table->foreignId('staff_id')
                ->nullable()
                ->constrained('users')
                ->onDelete('set null');

            $table->dateTime('start_time');
            $table->dateTime('end_time');

            $table->string('status')->default('pending');

            $table->decimal('price', 10, 2);
            $table->text('notes')->nullable();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->onDelete('set null');

            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->onDelete('set null');

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
