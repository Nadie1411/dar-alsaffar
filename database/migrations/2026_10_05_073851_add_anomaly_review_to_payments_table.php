<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A payment flagged as an anomaly stays on the books; what changes is
        // whether somebody has looked at it and decided it is dealt with.
        Schema::table('payments', function (Blueprint $table) {
            $table->timestamp('anomaly_reviewed_at')->nullable()->after('anomaly');
            $table->foreignId('anomaly_reviewed_by')->nullable()->after('anomaly_reviewed_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('anomaly_reviewed_by');
            $table->dropColumn('anomaly_reviewed_at');
        });
    }
};
