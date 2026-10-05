<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The shop's own signing key for push messages. One row; the private half is encrypted.
        Schema::create('push_keys', function (Blueprint $table) {
            $table->id();
            $table->string('public_key');
            $table->text('private_key');
            $table->timestamps();
        });

        // A phone or computer a member of staff has allowed to be notified. Only the
        // push service's address for it is kept: a message carries no content, and
        // the device fetches what to show from the panel.
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('endpoint');
            $table->string('endpoint_hash', 64)->unique();
            $table->string('device', 120)->nullable();
            $table->unsignedSmallInteger('failures')->default(0);
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
        Schema::dropIfExists('push_keys');
    }
};
