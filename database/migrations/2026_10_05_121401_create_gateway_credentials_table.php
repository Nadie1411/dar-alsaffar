<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The payment gateway's keys when an owner has pasted them into the
        // panel rather than the server's .env. The secrets are stored encrypted
        // with the application key, never in the clear, and no page or API
        // ever returns them.
        Schema::create('gateway_credentials', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 30)->unique();
            $table->text('api_key')->nullable();
            $table->text('webhook_secret')->nullable();
            // One of the addresses config/myfatoorah.php lists. Null means the server's own setting.
            $table->string('api_url', 120)->nullable();
            // Null means the server's own setting.
            $table->boolean('enabled')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gateway_credentials');
    }
};
