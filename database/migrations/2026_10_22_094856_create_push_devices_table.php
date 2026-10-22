<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phones that receive push notifications.
     *
     * `token` is unique across users, not per user: an FCM token identifies an
     * app install, and when a second person signs in on the same phone the row
     * moves to them rather than leaving the first person's notifications
     * arriving on a device they no longer use.
     *
     * `oauth_access_token_id` is the mobile sign-in that registered the device,
     * so signing out of the app stops its pushes without touching the person's
     * other phones.
     */
    public function up(): void
    {
        Schema::create('push_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token', 512)->unique();
            $table->string('platform', 16);
            $table->string('device_name')->nullable();
            $table->string('oauth_access_token_id', 100)->nullable()->index();
            $table->timestampTz('last_registered_at');
            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('push_devices');
    }
};
