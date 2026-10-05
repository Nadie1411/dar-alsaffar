<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            // The number the shopper and the shop quote. Assigned right after
            // the row exists, from its id.
            $table->string('number', 20)->nullable()->unique();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();

            // Who it is for, as typed at checkout. A guest has no account to
            // read this from, and an account holder may change theirs later.
            $table->string('customer_name', 120);
            $table->string('customer_email', 190)->nullable();
            $table->string('customer_phone', 20);

            $table->string('status', 20);
            $table->string('payment_method', 20); // cod | online
            $table->string('payment_status', 20)->default('unpaid');

            // All money is whole fils. The total is what was agreed at checkout.
            $table->char('currency', 3)->default('KWD');
            $table->unsignedInteger('subtotal_fils');
            $table->unsignedInteger('discount_fils')->default(0);
            $table->unsignedInteger('delivery_fee_fils')->default(0);
            $table->unsignedInteger('addons_total_fils')->default(0);
            $table->unsignedInteger('cod_fee_fils')->default(0);
            $table->unsignedInteger('total_fils');
            $table->string('voucher_code', 60)->nullable()->index();

            // The delivery address is copied here, not looked up: renaming or
            // removing an area must not rewrite where an old order went.
            $table->foreignId('delivery_city_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('delivery_area_id')->nullable()->constrained()->nullOnDelete();
            $table->string('city_name_ar', 190)->nullable();
            $table->string('city_name_en', 190)->nullable();
            $table->string('area_name_ar', 190)->nullable();
            $table->string('area_name_en', 190)->nullable();
            $table->string('block', 40)->nullable();
            $table->string('street', 120)->nullable();
            $table->string('avenue', 60)->nullable();
            $table->string('building', 60)->nullable();
            $table->string('floor', 30)->nullable();
            $table->string('apartment', 30)->nullable();

            $table->json('addons')->nullable();
            $table->text('notes')->nullable();
            $table->text('admin_notes')->nullable();
            $table->string('locale', 2)->default('ar');

            $table->timestamp('placed_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->string('overzaki_id', 40)->nullable()->unique();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['customer_id', 'created_at']);
            $table->index('payment_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
