<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            // The product may be deleted later; the order keeps what was sold.
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name_ar', 255);
            $table->string('name_en', 255);
            $table->string('sku', 100)->nullable();
            $table->string('image')->nullable();
            $table->unsignedSmallInteger('quantity');
            // The unit price paid: the product's price after its own
            // discount, plus the options chosen.
            $table->unsignedInteger('unit_price_fils');
            $table->unsignedInteger('total_fils');
            $table->json('options')->nullable();
            $table->boolean('is_gift')->default(false);
            $table->timestamps();

            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
