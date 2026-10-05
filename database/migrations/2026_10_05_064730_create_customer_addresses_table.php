<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            // An address outlives a delivery area being retired; it simply
            // loses the link and keeps the text the shopper typed.
            $table->foreignId('delivery_city_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('delivery_area_id')->nullable()->constrained()->nullOnDelete();
            $table->string('block', 40)->nullable();
            $table->string('street', 120)->nullable();
            $table->string('avenue', 60)->nullable();
            $table->string('building', 60)->nullable();
            $table->string('floor', 30)->nullable();
            $table->string('apartment', 30)->nullable();
            $table->timestamps();

            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_addresses');
    }
};
