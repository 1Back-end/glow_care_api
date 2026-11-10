<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('uuid')->unique();


            $table->uuid('establishment_uuid');
            $table->uuid('invoices_uuid');

            $table->decimal('amount', 12, 2);
            $table->string('payment_method');
            $table->string('gateway')->default('manual');
            $table->string('reference')->nullable();
            $table->string('status')->default('success');
            $table->json('gateway_response')->nullable();

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

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
