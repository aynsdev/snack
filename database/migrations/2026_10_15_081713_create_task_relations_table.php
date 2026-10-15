<?php

use App\Enums\TaskRelationType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One card's relationship to another: blocks, relates to, duplicates.
 *
 * Stored once, from the side it was said from — "PWA-1 blocks PWA-2" is
 * `task_id` PWA-1, `related_task_id` PWA-2 — and read from both, so PWA-2
 * shows "blocked by PWA-1" without a second row that could disagree with the
 * first. See {@see TaskRelationType} for the wording each side gets.
 *
 * The two cards may be in different projects of the same workspace: a mobile
 * fix blocked by an API change is the ordinary case.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_relations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('related_task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('type', 20);
            $table->timestampsTz();

            $table->unique(['task_id', 'related_task_id', 'type']);
            $table->index('related_task_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_relations');
    }
};
