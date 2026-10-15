<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->boolean('is_pinned')->default(false);
            $table->timestampTz('archived_at')->nullable();
            $table->string('share_token', 40)->nullable()->unique();
            $table->unsignedBigInteger('shared_through_message_id')->nullable();
            $table->timestampTz('shared_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropUnique(['share_token']);
            $table->dropColumn(['is_pinned', 'archived_at', 'share_token', 'shared_through_message_id', 'shared_at']);
        });
    }
};
