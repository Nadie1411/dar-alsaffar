<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The people who run the shop. A user is a member of staff; shoppers
        // are customers, in their own table.
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->default('staff')->after('password');
            $table->boolean('is_active')->default(true)->after('role');
            // The language the panel is shown in for this person.
            $table->string('locale', 2)->default('ar')->after('is_active');
            $table->timestamp('last_login_at')->nullable()->after('locale');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'is_active', 'locale', 'last_login_at']);
        });
    }
};
