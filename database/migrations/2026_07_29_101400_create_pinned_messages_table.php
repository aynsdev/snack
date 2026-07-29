<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Unlike a saved message, a pin is shared by everyone in the channel or
 * conversation, so the container is denormalized onto the row to keep the
 * "pinned in here" lookup a single index scan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pinned_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('pinned_by')->constrained('users')->cascadeOnDelete();

            $table->timestampsTz();

            // A message is either pinned or it is not; pinning is not per-user.
            $table->unique(['message_id']);

            $table->index(['channel_id', 'created_at']);
            $table->index(['conversation_id', 'created_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE pinned_messages
            ADD CONSTRAINT pinned_messages_single_container_check
            CHECK (num_nonnulls(channel_id, conversation_id) = 1)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('pinned_messages');
    }
};
