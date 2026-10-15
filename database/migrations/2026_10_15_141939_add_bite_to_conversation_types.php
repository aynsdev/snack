<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bite chats: a person's own titled conversations with the workspace's Bite
 * account, listed on the Bite page rather than among the DMs.
 *
 * Only the type check changes. The unique index on participants is partial —
 * `WHERE type = 'direct'` — so a person may hold any number of Bite chats with
 * the same bot, which is the point.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE conversations DROP CONSTRAINT conversations_type_check');

        DB::statement(<<<'SQL'
            ALTER TABLE conversations
            ADD CONSTRAINT conversations_type_check
            CHECK (type IN ('direct', 'group', 'bite'))
        SQL);
    }

    public function down(): void
    {
        DB::statement("DELETE FROM conversations WHERE type = 'bite'");
        DB::statement('ALTER TABLE conversations DROP CONSTRAINT conversations_type_check');

        DB::statement(<<<'SQL'
            ALTER TABLE conversations
            ADD CONSTRAINT conversations_type_check
            CHECK (type IN ('direct', 'group'))
        SQL);
    }
};
