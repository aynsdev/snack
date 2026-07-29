<?php

use App\Enums\PresenceStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Extends the starter-kit users table with Snack's profile, status, and
 * presence columns.
 *
 * `email` and `username` become `citext` so that "Ada@snack.test" and
 * "ada@snack.test" collide on the existing unique index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->nullable()->after('email');
            $table->string('avatar_path')->nullable()->after('username');
            $table->string('title')->nullable()->after('avatar_path');
            $table->string('department')->nullable()->after('title');
            $table->string('timezone', 64)->default('UTC')->after('department');

            $table->string('status_message', 120)->nullable()->after('timezone');
            $table->string('status_emoji', 32)->nullable()->after('status_message');
            $table->timestampTz('status_expires_at')->nullable()->after('status_emoji');

            $table->string('presence_status', 20)
                ->default(PresenceStatus::Offline->value)
                ->after('status_expires_at');
            $table->timestampTz('last_seen_at')->nullable()->after('presence_status');
        });

        DB::statement('ALTER TABLE users ALTER COLUMN email TYPE citext');

        // Backfill usernames for any pre-existing accounts before enforcing the
        // NOT NULL constraint, deriving them from the email local part.
        DB::statement(<<<'SQL'
            UPDATE users
            SET username = base.candidate || CASE WHEN base.collisions = 1 THEN '' ELSE base.collisions::text END
            FROM (
                SELECT
                    id,
                    regexp_replace(split_part(email::text, '@', 1), '[^a-zA-Z0-9._-]', '', 'g') AS candidate,
                    row_number() OVER (
                        PARTITION BY regexp_replace(split_part(email::text, '@', 1), '[^a-zA-Z0-9._-]', '', 'g')
                        ORDER BY id
                    ) AS collisions
                FROM users
            ) AS base
            WHERE users.id = base.id AND users.username IS NULL
        SQL);

        DB::statement('ALTER TABLE users ALTER COLUMN username TYPE citext');
        DB::statement('ALTER TABLE users ALTER COLUMN username SET NOT NULL');
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_username_unique UNIQUE (username)');

        // Fuzzy autocomplete for the mention picker and the member directory.
        DB::statement('CREATE INDEX users_name_trigram_index ON users USING gin (name gin_trgm_ops)');
        DB::statement('CREATE INDEX users_username_trigram_index ON users USING gin ((username::text) gin_trgm_ops)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS users_username_trigram_index');
        DB::statement('DROP INDEX IF EXISTS users_name_trigram_index');
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_username_unique');
        DB::statement('ALTER TABLE users ALTER COLUMN email TYPE varchar(255)');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'username',
                'avatar_path',
                'title',
                'department',
                'timezone',
                'status_message',
                'status_emoji',
                'status_expires_at',
                'presence_status',
                'last_seen_at',
            ]);
        });
    }
};
