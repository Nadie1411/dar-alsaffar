<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // "Buy more, save more": from min_quantity units of a product in one
        // line, a discount comes off that line once (not per unit), and
        // delivery can be waived. The highest tier a line reaches applies.
        Schema::create('product_quantity_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('min_quantity');
            $table->string('discount_type', 12)->default('none'); // none | fixed | percentage
            $table->unsignedInteger('discount_fils')->default(0);
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->boolean('free_delivery')->default(false);
            $table->string('label_ar', 120)->nullable();
            $table->string('label_en', 120)->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'min_quantity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_quantity_tiers');
    }
};
