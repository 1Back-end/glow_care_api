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
        Schema::create('invoices_factures', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->string('code');
            $table->string('sequence_number');

            $table->foreignUuid('invoices_uuid')
                ->constrained('invoices', 'uuid')
                ->onDelete('cascade');

            $table->foreignUuid('establishment_uuid')
                ->constrained('establishments', 'uuid')
                ->onDelete('cascade');

            $table->string('type')->default('facial_care');
            $table->string('state_facture');
            $table->decimal('amount', 10, 2)->nullable();
            $table->timestamp('date_fac')->nullable();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->onDelete('set null');

            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->onDelete('set null');

            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices_factures');
    }
};
