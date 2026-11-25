<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->string('reference')->unique();
            $table->string('type');
            $table->string('label')->nullable();

            $table->foreignUuid('regulation_methods_uuid')
                ->nullable()
                ->constrained('regulation_methods', 'uuid')
                ->nullOnDelete();

            $table->foreignUuid('establishment_uuid')
                ->constrained('establishments', 'uuid')
                ->onDelete('cascade');

            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->decimal('amount', 15, 2);
            $table->timestamp('transaction_date')->useCurrent();
            $table->string('status')->default('pending');

            $table->nullableUuidMorphs('transactionable');

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
