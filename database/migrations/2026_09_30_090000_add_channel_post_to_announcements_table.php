<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where an announcement was posted, so edits and deletes can follow it there.
 *
 * Both nullable: an announcement can be published in HR only, and the post can
 * be deleted from the channel without the announcement going with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->foreignId('channel_id')->nullable()->after('author_id')->constrained()->nullOnDelete();
            $table->foreignId('message_id')->nullable()->after('channel_id')->constrained()->nullOnDelete();
            $table->boolean('notified_everyone')->default(false)->after('is_pinned');
        });
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('message_id');
            $table->dropConstrainedForeignId('channel_id');
            $table->dropColumn('notified_everyone');
        });
    }
};
