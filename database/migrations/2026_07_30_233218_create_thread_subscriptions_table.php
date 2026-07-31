<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Thread following, and the read pointer that drives the thread badge.
 *
 * # Why this table exists at all
 *
 * Every other read state in Snack hangs off a membership row — `channel_members`
 * and `conversation_participants` each carry a `last_read_message_id`. A thread
 * has no membership row to hang off: you are not "in" a thread the way you are
 * in a channel, you are in it because you started it or replied to it. So the
 * pointer needs somewhere to live, and this is it.
 *
 * The shape is deliberately the same as the existing pointers: **one row per
 * user per thread they follow**, never one per user per message. A thread with
 * two thousand replies costs a follower exactly one row, and marking it read is
 * one UPDATE of one integer.
 *
 * A row existing *is* the subscription. Unfollowing deletes it; replying again
 * re-creates it. There is no `is_subscribed` flag that could fall out of sync
 * with the row's own presence.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * The newest reply on a thread root, denormalized next to the
         * `reply_count` and `last_reply_at` that the messages table already
         * carries.
         *
         * Without it the unread-threads badge would have to find MAX(id) among
         * every followed thread's replies on every page load — a correlated
         * aggregate per followed thread. With it the badge is one integer
         * comparison against the pointer below, which is the same trick the
         * channel badge plays against `last_read_message_id`.
         */
        Schema::table('messages', function (Blueprint $table) {
            $table->unsignedBigInteger('last_reply_id')->nullable()->after('last_reply_at');
        });

        Schema::create('thread_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Always the thread *root*, never a reply. Keying on a reply's own
            // id would give one thread several subscription rows per person.
            $table->foreignId('root_message_id')->constrained('messages')->cascadeOnDelete();

            // Null means "following but has read nothing", which is the state a
            // subscription created by an @-mention starts in.
            $table->unsignedBigInteger('last_read_reply_id')->nullable();
            $table->timestampTz('last_read_at')->nullable();

            $table->timestampsTz();

            // One subscription per person per thread, and the index the badge
            // query walks.
            $table->unique(['user_id', 'root_message_id']);

            // Fan-out in the other direction: who should hear about a new reply.
            $table->index('root_message_id');
        });

        /*
         * Set null rather than cascade: deleting the reply someone happened to
         * have read last must not delete their subscription and silently
         * unfollow them from the thread.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE thread_subscriptions
            ADD CONSTRAINT thread_subscriptions_last_read_reply_id_foreign
            FOREIGN KEY (last_read_reply_id) REFERENCES messages (id) ON DELETE SET NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('thread_subscriptions');

        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('last_reply_id');
        });
    }
};
