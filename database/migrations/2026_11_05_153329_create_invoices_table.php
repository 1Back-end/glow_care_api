<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->string('code');

            $table->foreignUuid('establishment_uuid')
                ->constrained('establishments', 'uuid')
                ->onDelete('cascade');

            $table->foreignUuid('client_uuid')
                ->constrained('clients', 'uuid')
                ->onDelete('cascade');

            $table->integer('regulated')->default(0);

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->onDelete('set null');

            $table->string('type')->default('facial_care');
            $table->timestamp('programmation_date')->nullable();
            $table->boolean('state')->default(false);

            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->onDelete('set null');

            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
