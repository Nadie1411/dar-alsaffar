<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_areas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_city_id')->constrained()->cascadeOnDelete();
            $table->string('name_ar', 190);
            $table->string('name_en', 190);
            // Null means "use the store's default delivery fee".
            $table->unsignedInteger('fee_fils')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->string('overzaki_id', 40)->nullable()->unique();
            $table->timestamps();

            $table->index(['delivery_city_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_areas');
    }
};
