<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * An opaque, externally-facing identifier for a conversation.
 *
 * The primary key stays an auto-incrementing integer — it is what every foreign
 * key, index, and cursor in the schema is built on, and changing that would be a
 * rewrite for no gain. What changes is that the integer stops leaving the server.
 *
 * A sequential id in a URL is an invitation: `/dms/1` says there is a `/dms/2`,
 * tells anyone how many private conversations the deployment holds and how fast
 * that number grows, and turns a shared link into a probe. Authorization already
 * refuses every one of those requests, but the count itself is a fact worth not
 * publishing.
 *
 * ULID rather than UUIDv4 because it sorts by creation time, so the unique index
 * is appended to rather than written across — the same reason the rest of this
 * schema leads its indexes with a monotonic key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->ulid('public_id')->nullable()->after('id');
        });

        // Backfilled before the column is made non-null: conversations created
        // before this migration already exist in people's tabs and history.
        foreach (DB::table('conversations')->whereNull('public_id')->orderBy('id')->pluck('id') as $id) {
            DB::table('conversations')
                ->where('id', $id)
                ->update(['public_id' => (string) Str::ulid()]);
        }

        Schema::table('conversations', function (Blueprint $table) {
            $table->ulid('public_id')->nullable(false)->change();
            $table->unique('public_id');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropUnique(['public_id']);
            $table->dropColumn('public_id');
        });
    }
};
