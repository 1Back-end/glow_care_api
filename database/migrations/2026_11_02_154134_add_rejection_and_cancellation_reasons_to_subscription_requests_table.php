<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_requests', function (Blueprint $table) {
            $table->text('reason_for_rejected')->nullable()->after('rejected_by');
            $table->text('reason_for_cancelled')->nullable()->after('cancelled_by');
        });
    }

    public function down(): void
    {
        Schema::table('subscription_requests', function (Blueprint $table) {
            $table->dropColumn(['reason_for_rejected', 'reason_for_cancelled']);
        });
    }
};
