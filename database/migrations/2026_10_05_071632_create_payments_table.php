<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per attempt to pay an order online. An order can have
        // several: a shopper who abandons a payment page and tries again gets
        // a new invoice.
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 20)->default('myfatoorah');

            // Our id for the attempt, sent to MyFatoorah as the invoice's
            // external identifier and echoed back in every status and webhook.
            $table->string('reference', 40)->unique();
            $table->string('state', 20)->default('pending');
            $table->unsignedInteger('amount_fils');
            $table->char('currency', 3)->default('KWD');
            $table->string('method', 20)->nullable();

            $table->string('mf_invoice_id', 40)->nullable()->unique();
            $table->string('mf_payment_id', 40)->nullable()->unique();
            $table->text('mf_payment_url')->nullable();
            $table->string('mf_method', 60)->nullable();
            $table->string('mf_transaction_id', 40)->nullable();
            $table->string('mf_reference_id', 60)->nullable();
            $table->string('mf_track_id', 60)->nullable();

            $table->string('failure_reason', 255)->nullable();
            // Something a person has to look at: a payment that arrived for an
            // order already cancelled, an amount that does not match, a second
            // payment for one order.
            $table->string('anomaly', 40)->nullable()->index();

            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index(['state', 'created_at']);
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
