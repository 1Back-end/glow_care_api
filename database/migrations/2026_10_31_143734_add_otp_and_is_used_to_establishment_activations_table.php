<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('establishment_activations', function (Blueprint $table) {
            $table->string('otp')->nullable()->after('license_uuid');
            $table->boolean('is_used')->default(false)->after('otp');
        });
    }

    public function down(): void
    {
        Schema::table('establishment_activations', function (Blueprint $table) {
            $table->dropColumn(['otp', 'is_used']);
        });
    }
};
