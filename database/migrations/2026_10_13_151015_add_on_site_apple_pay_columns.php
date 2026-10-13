<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An Apple Pay button on the shop's own page is paid through a MyFatoorah
        // "session", not an invoice. The session id is how such an attempt is found
        // again: an invoice only exists once the shopper has paid.
        Schema::table('payments', function (Blueprint $table) {
            $table->string('mf_session_id', 80)->nullable()->unique()->after('mf_invoice_id');
        });

        Schema::table('gateway_credentials', function (Blueprint $table) {
            // Null means the server's own setting.
            $table->boolean('apple_pay_on_site')->nullable()->after('enabled');
            // The file Apple (through MyFatoorah) gives to prove the shop owns its domain. Served as it
            // was pasted, at /.well-known/apple-developer-merchantid-domain-association. Not a secret.
            $table->text('apple_pay_domain_file')->nullable()->after('apple_pay_on_site');
            // The domain MyFatoorah was last asked to register for Apple Pay, and when it said yes.
            $table->string('apple_pay_domain', 190)->nullable()->after('apple_pay_domain_file');
            $table->timestamp('apple_pay_registered_at')->nullable()->after('apple_pay_domain');
        });
    }

    public function down(): void
    {
        Schema::table('gateway_credentials', function (Blueprint $table) {
            $table->dropColumn(['apple_pay_on_site', 'apple_pay_domain_file', 'apple_pay_domain', 'apple_pay_registered_at']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['mf_session_id']);
            $table->dropColumn('mf_session_id');
        });
    }
};
