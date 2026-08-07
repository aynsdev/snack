<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which release a card goes out in — null for work nobody has scheduled.
 *
 * `nullOnDelete` rather than cascade, for the reason the same choice is made on
 * `project_group_id`: deleting a release means "we are not shipping it that
 * way", not "delete the work". The cards come back unscheduled and every one of
 * them is still on the board.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('release_id')
                ->nullable()
                ->after('sprint_id')
                ->constrained()
                ->nullOnDelete();

            // What the releases page reads: one release's contents.
            $table->index(['release_id']);
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('release_id');
        });
    }
};
