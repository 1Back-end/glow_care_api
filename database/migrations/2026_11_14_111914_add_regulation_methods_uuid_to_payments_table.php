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
        Schema::table('payments', function (Blueprint $table) {
            $table->uuid('regulation_methods_uuid')->nullable()->after('uuid');
            $table->foreign('regulation_methods_uuid')
                ->references('uuid')
                ->on('regulation_methods')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['regulation_methods_uuid']);
            $table->dropColumn('regulation_methods_uuid');
        });
    }
};
