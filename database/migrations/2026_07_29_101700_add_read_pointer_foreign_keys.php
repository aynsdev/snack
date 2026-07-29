<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Closes the circular dependency between the membership tables and `messages`.
 *
 * `channel_members` and `conversation_participants` are created before
 * `messages` (because `messages` references `channels` and `conversations`), so
 * their `last_read_message_id` foreign keys are attached here instead.
 *
 * On delete the pointer is nulled rather than cascaded — losing a read position
 * is a far better outcome than losing the membership row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channel_members', function (Blueprint $table) {
            $table->foreign('last_read_message_id')
                ->references('id')
                ->on('messages')
                ->nullOnDelete();
        });

        Schema::table('conversation_participants', function (Blueprint $table) {
            $table->foreign('last_read_message_id')
                ->references('id')
                ->on('messages')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('channel_members', function (Blueprint $table) {
            $table->dropForeign(['last_read_message_id']);
        });

        Schema::table('conversation_participants', function (Blueprint $table) {
            $table->dropForeign(['last_read_message_id']);
        });
    }
};
