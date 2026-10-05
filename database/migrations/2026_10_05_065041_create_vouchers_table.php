<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 60)->nullable()->unique();
            $table->string('name_ar', 190);
            $table->string('name_en', 190);
            $table->string('type', 16); // percentage | fixed | free_shipping
            $table->decimal('percent', 5, 2)->default(0);
            $table->unsignedInteger('amount_fils')->default(0);
            $table->unsignedInteger('max_discount_fils')->nullable();
            $table->unsignedInteger('min_subtotal_fils')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('usage_limit_per_customer')->nullable();
            $table->boolean('is_active')->default(true);
            // Shown in the announcement strip and the pop-up.
            $table->boolean('is_public')->default(false);
            $table->string('overzaki_id', 40)->nullable()->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vouchers');
    }
};
