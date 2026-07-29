<?php

use App\Enums\ConversationType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Direct and group messages share one table.
 *
 * `participants_hash` is a deterministic digest of the sorted participant ids.
 * A partial unique index over it is what actually guarantees the product rule
 * "no two direct conversations between the same pair in the same workspace" —
 * enforcing that in application code alone would race under concurrency.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('type', 20)->default(ConversationType::Direct->value);

            // Group conversations may be named; direct conversations never are.
            $table->string('name')->nullable();

            $table->char('participants_hash', 64);

            $table->timestampTz('last_message_at')->nullable();

            $table->timestampsTz();

            $table->index(['workspace_id', 'last_message_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE conversations
            ADD CONSTRAINT conversations_type_check
            CHECK (type IN ('direct', 'group'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE conversations
            ADD CONSTRAINT conversations_direct_is_unnamed_check
            CHECK (type <> 'direct' OR name IS NULL)
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX conversations_direct_participants_unique
            ON conversations (workspace_id, participants_hash)
            WHERE type = 'direct'
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
