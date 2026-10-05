<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('option_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name_ar', 190);
            $table->string('name_en', 190);
            // radio = pick one; checkbox = pick between min and max. A
            // checkbox group with a max above one is how a "choose 3" package
            // is built.
            $table->string('layout', 12)->default('radio');
            $table->boolean('is_required')->default(false);
            $table->unsignedSmallInteger('min_choices')->default(0);
            $table->unsignedSmallInteger('max_choices')->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            // Overzaki shares one option group between several products, so
            // each product keeps its own copy and the id is only unique
            // within the product.
            $table->string('overzaki_id', 40)->nullable();
            $table->timestamps();

            $table->index(['product_id', 'sort_order']);
            $table->unique(['product_id', 'overzaki_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('option_groups');
    }
};
