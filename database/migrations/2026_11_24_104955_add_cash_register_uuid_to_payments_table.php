<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (!Schema::hasColumn('payments', 'cash_register_uuid')) {
                $table->uuid('cash_register_uuid')->nullable()->after('establishment_uuid');
                $table->index('cash_register_uuid', 'payments_cash_register_uuid_index');

                $table->foreign('cash_register_uuid', 'payments_cash_register_uuid_foreign')
                    ->references('uuid')
                    ->on('cash_registers')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (Schema::hasColumn('payments', 'cash_register_uuid')) {
                $table->dropForeign('payments_cash_register_uuid_foreign');
                $table->dropIndex('payments_cash_register_uuid_index');
                $table->dropColumn('cash_register_uuid');
            }
        });
    }
};
