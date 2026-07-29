<?php

use App\Enums\NotificationLevel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mirrors `channel_members`, including the last-read pointer whose foreign key
 * is added once `messages` exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->unsignedBigInteger('last_read_message_id')->nullable();
            $table->timestampTz('last_read_at')->nullable();

            $table->string('notification_level', 20)->default(NotificationLevel::All->value);
            $table->boolean('is_muted')->default(false);

            // Set when a participant leaves a group conversation. History stays
            // readable up to this point rather than disappearing.
            $table->timestampTz('left_at')->nullable();

            $table->timestampTz('joined_at');
            $table->timestampsTz();

            $table->unique(['conversation_id', 'user_id']);

            // "Which conversations am I in?" — the DM list.
            $table->index(['user_id', 'conversation_id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE conversation_participants
            ADD CONSTRAINT conversation_participants_notification_level_check
            CHECK (notification_level IN ('all', 'mentions', 'nothing'))
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_participants');
    }
};
