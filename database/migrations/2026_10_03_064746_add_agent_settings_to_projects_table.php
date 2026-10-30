<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a project's agents talk, and which columns they move cards into.
 *
 * Agents are on for a project exactly while `agent_channel_id` is set. Every
 * column is `nullOnDelete` so that removing the channel or a column switches the
 * feature off (or leaves the card where it is) rather than pointing an agent at
 * a row that no longer exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('agent_channel_id')->nullable()->constrained('channels')->nullOnDelete();
            $table->foreignId('agent_in_progress_column_id')->nullable()->constrained('board_columns')->nullOnDelete();
            $table->foreignId('agent_review_column_id')->nullable()->constrained('board_columns')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('agent_review_column_id');
            $table->dropConstrainedForeignId('agent_in_progress_column_id');
            $table->dropConstrainedForeignId('agent_channel_id');
        });
    }
};
