<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 190)->unique();
            $table->string('sku', 100)->nullable();
            $table->string('name_ar', 255);
            $table->string('name_en', 255);
            // Rich text authored in the admin, trusted but sanitised on output.
            $table->longText('description_ar')->nullable();
            $table->longText('description_en')->nullable();

            // Money is stored as whole fils (1 KWD = 1000 fils), never as a
            // float, so a total can never drift by a rounding error.
            $table->unsignedInteger('sell_price_fils')->default(0);
            $table->string('discount_type', 12)->default('none'); // none | fixed | percentage
            $table->unsignedInteger('discount_fils')->default(0);
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->timestamp('discount_starts_at')->nullable();
            $table->timestamp('discount_ends_at')->nullable();

            $table->boolean('track_stock')->default(false);
            $table->unsignedInteger('stock')->default(0);
            $table->unsignedInteger('low_stock_threshold')->default(0);
            $table->unsignedSmallInteger('max_per_order')->nullable();

            $table->string('main_image')->nullable();
            $table->string('video')->nullable();
            $table->json('tags')->nullable();

            $table->boolean('is_active')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_new')->default(false);
            $table->boolean('is_popular')->default(false);
            $table->boolean('cod_enabled')->default(true);
            $table->unsignedInteger('sort_order')->default(0);

            // Units sold on paid orders; best-sellers are ranked by it.
            $table->unsignedInteger('sales_count')->default(0);
            $table->decimal('rating_average', 3, 2)->default(0);
            $table->unsignedInteger('rating_count')->default(0);

            $table->string('overzaki_id', 40)->nullable()->unique();
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
            $table->index('sales_count');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
