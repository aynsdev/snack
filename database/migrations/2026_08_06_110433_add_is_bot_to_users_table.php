<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Marks an account as software rather than a person.
 *
 * Snack's first bot is the standup app, and it has to be a real `users` row:
 * it sends direct messages, and everything that delivers one — the conversation
 * roster, the message pipeline, the sidebar — is built on a sender that exists.
 * Inventing a parallel sender type would have meant a nullable second column on
 * `messages` and a fork in every query that renders an author.
 *
 * What the flag buys is the places a bot must *not* count as a person:
 *
 *  - the seat limit, which bills for colleagues and not for a cron job;
 *  - the admin member roster, where a bot has no role worth changing and no
 *    invitation to revoke;
 *  - notification delivery, which would otherwise queue an email to an address
 *    nobody reads.
 *
 * It deliberately does *not* hide bots from the DM roster. The standup bot's
 * conversation is where it asks its questions, so a person has to be able to
 * find it in the sidebar like anyone else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_bot')->default(false)->after('avatar_path');
        });

        /*
         * Partial, because the interesting set is tiny and its complement is
         * every account in the installation: the queries that need this are all
         * "find the bot", never "find the humans".
         */
        DB::statement(<<<'SQL'
            CREATE INDEX users_bots_index ON users (id) WHERE is_bot = true
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS users_bots_index');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_bot');
        });
    }
};
