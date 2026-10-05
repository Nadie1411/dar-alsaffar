<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_addons', function (Blueprint $table) {
            $table->id();
            $table->string('name_ar', 190);
            $table->string('name_en', 190);
            $table->string('description_ar', 500)->nullable();
            $table->string('description_en', 500)->nullable();
            $table->unsignedInteger('price_fils')->default(0);
            $table->string('image')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->string('overzaki_id', 40)->nullable()->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_addons');
    }
};
