<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `message_id` is nullable on purpose: a file is uploaded (with progress) while
 * the composer is still open, and is only linked to a message when that message
 * is actually sent. Orphaned rows older than a day are pruned by a scheduled job.
 *
 * `path` is a private-disk key and is never exposed to the client; downloads go
 * through an authorization-checked, signed route.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('message_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->cascadeOnDelete();

            $table->string('disk', 40);
            $table->string('path');
            $table->string('thumbnail_path')->nullable();

            $table->string('original_name');
            $table->string('mime_type', 160);
            $table->unsignedBigInteger('size');

            // Extracted asynchronously: dimensions, duration, page count, and
            // the virus-scan verdict.
            $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));

            $table->timestampsTz();

            $table->index(['message_id']);

            // The "shared files" browser for a workspace.
            $table->index(['workspace_id', 'created_at']);
        });

        // Pruning sweep for uploads that were never attached to a message.
        DB::statement(<<<'SQL'
            CREATE INDEX message_attachments_orphaned_index
            ON message_attachments (created_at)
            WHERE message_id IS NULL
        SQL);

        // Filename search, both fuzzy and as part of the message search vector.
        DB::statement('CREATE INDEX message_attachments_name_trigram_index ON message_attachments USING gin (original_name gin_trgm_ops)');
    }

    public function down(): void
    {
        Schema::dropIfExists('message_attachments');
    }
};
