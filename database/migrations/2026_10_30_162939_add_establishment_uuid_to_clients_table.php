<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->uuid('establishment_uuid')->nullable()->after('uuid');

            $table->foreign('establishment_uuid')
                ->references('uuid')
                ->on('establishments')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropForeign(['establishment_uuid']);
            $table->dropColumn('establishment_uuid');
        });
    }
};
