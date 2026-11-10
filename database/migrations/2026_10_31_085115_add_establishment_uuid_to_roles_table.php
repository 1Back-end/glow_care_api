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
        Schema::table('roles', function (Blueprint $table) {
            $table->uuid('establishment_uuid')->nullable()->after('id');

            $table->foreign('establishment_uuid')
                ->references('uuid')
                ->on('establishments')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            // Suppression de la contrainte de clé étrangère puis de la colonne
            $table->dropForeign(['establishment_uuid']);
            $table->dropColumn('establishment_uuid');
        });
    }
};
