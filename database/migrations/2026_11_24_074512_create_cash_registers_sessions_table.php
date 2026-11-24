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
        Schema::create('cash_registers_sessions', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->string('code', 30)->unique();

            $table->uuid('cash_register_uuid');
            $table->uuid('establishment_uuid');
            $table->unsignedBigInteger('user_id');
            $table->string('status', 20)->default('open');
            $table->decimal('opening_balance', 15, 2)->default(0);
            $table->decimal('expected_closing_balance', 15, 2)->nullable();
            $table->decimal('closing_balance', 15, 2)->nullable();
            $table->decimal('difference', 15, 2)->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('resumed_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->text('opening_note')->nullable();
            $table->text('closing_note')->nullable();
            $table->unsignedBigInteger('opened_by')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->foreign('cash_register_uuid')
                ->references('uuid')
                ->on('cash_registers')
                ->cascadeOnDelete();

            $table->foreign('establishment_uuid')
                ->references('uuid')
                ->on('establishments')
                ->cascadeOnDelete();

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();

            $table->foreign('opened_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('closed_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['establishment_uuid', 'user_id', 'status']);
            $table->index(['cash_register_uuid', 'status']);
            $table->index('opened_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cash_registers_sessions');
    }
};
