<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices_items', function (Blueprint $table) {
            if (!Schema::hasColumn('invoices_items', 'cash_register_uuid')) {
                $table->uuid('cash_register_uuid')->nullable()->after('establishment_uuid');
                $table->index('cash_register_uuid', 'invoices_items_cash_register_uuid_index');

                $table->foreign('cash_register_uuid', 'invoices_items_cash_register_uuid_foreign')
                    ->references('uuid')
                    ->on('cash_registers')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoices_items', function (Blueprint $table) {
            if (Schema::hasColumn('invoices_items', 'cash_register_uuid')) {
                $table->dropForeign('invoices_items_cash_register_uuid_foreign');
                $table->dropIndex('invoices_items_cash_register_uuid_index');
                $table->dropColumn('cash_register_uuid');
            }
        });
    }
};
