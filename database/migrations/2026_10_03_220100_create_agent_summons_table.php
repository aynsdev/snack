<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Somebody writing `@agent` in a channel thread: whose agent was asked, by
 * whom, and from which message. One row per thread and target — a later
 * `@agent` in the same thread for the same person is a reply, not a new
 * summons. While `closed_at` is null the thread is exported to the agent
 * dispatcher like an agent channel's threads are.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_summons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('thread_root_id')->constrained('messages')->cascadeOnDelete();
            $table->foreignId('target_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('summoner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('message_id')->constrained('messages')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->unique(['thread_root_id', 'target_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_summons');
    }
};
