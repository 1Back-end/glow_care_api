<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (!Schema::hasColumn('invoices', 'cash_register_uuid')) {
                $table->uuid('cash_register_uuid')->nullable()->after('client_uuid');
                $table->index('cash_register_uuid', 'invoices_cash_register_uuid_index');

                $table->foreign('cash_register_uuid', 'invoices_cash_register_uuid_foreign')
                    ->references('uuid')
                    ->on('cash_registers')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (Schema::hasColumn('invoices', 'cash_register_uuid')) {
                $table->dropForeign('invoices_cash_register_uuid_foreign');
                $table->dropIndex('invoices_cash_register_uuid_index');
                $table->dropColumn('cash_register_uuid');
            }
        });
    }
};
