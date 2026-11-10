<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Vérifie si l'index unique existe déjà pour éviter l'erreur 1061
        $indexExists = collect(DB::select("SHOW INDEXES FROM clients WHERE Key_name = 'clients_code_unique'"))->isNotEmpty();

        if (!$indexExists) {
            Schema::table('clients', function (Blueprint $table) {
                $table->string('code')->unique()->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $indexExists = collect(DB::select("SHOW INDEXES FROM clients WHERE Key_name = 'clients_code_unique'"))->isNotEmpty();

        if ($indexExists) {
            Schema::table('clients', function (Blueprint $table) {
                $table->dropUnique('clients_code_unique');
            });
        }
    }
};
