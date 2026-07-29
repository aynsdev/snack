<?php

use App\Enums\ChannelRole;
use App\Enums\NotificationLevel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read state is a per-member pointer (`last_read_message_id`) rather than a row
 * per user per message, which keeps unread counts to a single indexed COUNT.
 *
 * The foreign key onto `messages` is added later, in
 * `2026_07_29_101700_add_read_pointer_foreign_keys`, because `messages`
 * references `channels` and the two cannot both exist first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('role', 20)->default(ChannelRole::Member->value);

            $table->unsignedBigInteger('last_read_message_id')->nullable();
            $table->timestampTz('last_read_at')->nullable();

            $table->string('notification_level', 20)->default(NotificationLevel::All->value);
            $table->boolean('is_muted')->default(false);

            $table->timestampTz('joined_at');
            $table->timestampsTz();

            $table->unique(['channel_id', 'user_id']);

            // "Which channels am I in?" — the sidebar's primary query.
            $table->index(['user_id', 'channel_id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE channel_members
            ADD CONSTRAINT channel_members_role_check
            CHECK (role IN ('admin', 'member'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE channel_members
            ADD CONSTRAINT channel_members_notification_level_check
            CHECK (notification_level IN ('all', 'mentions', 'nothing'))
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_members');
    }
};
