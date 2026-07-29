<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A personal bookmark on a message, optionally with a private note and a
 * reminder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();

            $table->text('notes')->nullable();
            $table->timestampTz('reminder_at')->nullable();
            $table->timestampTz('reminded_at')->nullable();

            $table->timestampsTz();

            $table->unique(['user_id', 'message_id']);

            // The "Saved" view, newest first.
            $table->index(['user_id', 'created_at']);
        });

        // Reminder sweep: only rows with a pending reminder are ever scanned.
        DB::statement(<<<'SQL'
            CREATE INDEX saved_messages_due_reminders_index
            ON saved_messages (reminder_at)
            WHERE reminder_at IS NOT NULL AND reminded_at IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_messages');
    }
};
