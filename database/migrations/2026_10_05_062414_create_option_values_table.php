<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('option_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('option_group_id')->constrained()->cascadeOnDelete();
            $table->string('name_ar', 190);
            $table->string('name_en', 190);
            // Added to the product's unit price for each unit of this value
            // chosen. A product priced at zero is priced by its options.
            $table->unsignedInteger('price_fils')->default(0);
            $table->string('image')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->string('overzaki_id', 40)->nullable();
            $table->timestamps();

            $table->index(['option_group_id', 'sort_order']);
            $table->unique(['option_group_id', 'overzaki_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('option_values');
    }
};
