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
        Schema::table('appointments', function (Blueprint $table) {
            if (!Schema::hasColumn('appointments', 'rejected_by')) {
                $table->unsignedBigInteger('rejected_by')->nullable()->after('status');
                $table->foreign('rejected_by')->references('id')->on('users')->nullOnDelete();
            }

            if (!Schema::hasColumn('appointments', 'reason_rejected')) {
                $table->text('reason_rejected')->nullable()->after('rejected_by');
            }

            if (!Schema::hasColumn('appointments', 'rejected_at')) {
                $table->timestamp('rejected_at')->nullable()->after('reason_rejected');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            if (Schema::hasColumn('appointments', 'rejected_at')) {
                $table->dropColumn('rejected_at');
            }
            if (Schema::hasColumn('appointments', 'reason_rejected')) {
                $table->dropColumn('reason_rejected');
            }
            if (Schema::hasColumn('appointments', 'rejected_by')) {
                $table->dropForeign(['rejected_by']);
                $table->dropColumn('rejected_by');
            }
        });
    }
};
