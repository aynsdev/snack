<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel's database notification table, plus a `workspace_id` so the in-app
 * notification centre can be scoped to the workspace the user is looking at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');

            $table->foreignId('workspace_id')->nullable()->constrained()->cascadeOnDelete();

            $table->jsonb('data');
            $table->timestampTz('read_at')->nullable();
            $table->timestampsTz();
        });

        // The badge: unread notifications for one notifiable in one workspace.
        DB::statement(<<<'SQL'
            CREATE INDEX notifications_unread_index
            ON notifications (notifiable_type, notifiable_id, workspace_id, created_at DESC)
            WHERE read_at IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
