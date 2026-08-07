<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The discussion on a card.
 *
 * A table of its own rather than messages in a channel. The two look alike and
 * are not: a channel message is addressed to a room and scrolls away, while this
 * is addressed to *the card* and has to still be there when somebody opens
 * PWA-1499 next quarter to ask why it was done that way. Putting them in
 * `messages` would mean every timeline query, search filter, and unread counter
 * in the application growing a "…and not a task comment" clause.
 *
 * `author_id` is nullable and `nullOnDelete` for the reason messages keep their
 * text when a sender is deleted: the argument recorded here is what the next
 * person needs, and losing it because the person who made it left is worse than
 * showing it without a name.
 *
 * `edited_at` is separate from `updated_at`, which any future backfill would
 * move. It is what the UI shows an "(edited)" mark from, so it must mean "a
 * person rewrote this" and nothing else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();

            $table->text('body');
            $table->timestampTz('edited_at')->nullable();
            $table->timestampsTz();

            // The only read there is: one card's comments, oldest first.
            $table->index(['task_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_comments');
    }
};
