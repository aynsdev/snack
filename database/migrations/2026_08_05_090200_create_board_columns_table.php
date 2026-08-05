<?php

use App\Enums\ColumnCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The columns of one project's board — its statuses, in the order they are read.
 *
 * A table rather than an enum because these are the part of the board teams
 * actually differ on: the reference board in the brief runs To do → In progress →
 * To test → To fix → Blocked → To release → Done, and no fixed set would have
 * predicted "To fix". What each column *means* is {@see ColumnCategory}, which
 * is a small fixed set precisely so the sprint machinery has something reliable
 * to ask.
 *
 * `wip_limit` is advisory: the board warns when a column is over it rather than
 * refusing the drop. A limit that blocks a move makes people work around the
 * board instead of on it, and the point of the number is the conversation it
 * starts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('board_columns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('category', 20)->default(ColumnCategory::Unstarted->value);

            // Sparse by design — see App\Support\Positions. Columns are
            // reordered by dragging, which is a midpoint insert, not a renumber.
            $table->integer('position');

            $table->unsignedSmallInteger('wip_limit')->nullable();

            $table->timestampsTz();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE board_columns
            ADD CONSTRAINT board_columns_category_check
            CHECK (category IN ('unstarted', 'started', 'done'))
        SQL);

        // The board itself: every column of one project, left to right.
        DB::statement('CREATE INDEX board_columns_project_order_index ON board_columns (project_id, position)');
    }

    public function down(): void
    {
        Schema::dropIfExists('board_columns');
    }
};
