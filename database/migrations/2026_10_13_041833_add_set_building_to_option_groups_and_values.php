<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('option_groups', function (Blueprint $table) {
            // The shopper builds a set from this group: they must pick its
            // pieces before the product can be bought. Unlike the checkbox
            // group that merely marks a ready-made package.
            $table->boolean('is_builder')->default(false)->after('is_required');
        });

        Schema::table('option_values', function (Blueprint $table) {
            // A piece of a set is one of the shop's own products. The name and
            // picture are read from it, and its stock is what runs down.
            $table->foreignId('product_id')->nullable()->after('option_group_id')
                ->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('option_values', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_id');
        });

        Schema::table('option_groups', function (Blueprint $table) {
            $table->dropColumn('is_builder');
        });
    }
};
