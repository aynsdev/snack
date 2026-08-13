<?php

use App\Ai\BiteKnowledge;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Facts an admin has decided Bite should always know.
 *
 * The release process, what the acronyms mean, which of the two staging
 * environments is the real one — the things a new hire is told in their first
 * week and that are written down nowhere a search would find. Bite can read
 * every message in a channel and still not know them, because nobody has said
 * them recently.
 *
 * # Always in the prompt, never searched
 *
 * Every entry is put into the system prompt of every answer. That is a
 * deliberate limit and not an oversight: a searchable knowledge base is a tool
 * the model has to decide to call, and a model that does not bother produces
 * exactly the confident wrong answer this table exists to prevent. Injecting it
 * costs tokens on every question and means it cannot be skipped.
 *
 * The cost of that choice is that this cannot be a library. It is bounded by
 * `snack.bite.knowledge.max_characters` in total and by a length limit per
 * entry, both enforced in the request and again when the prompt is assembled —
 * see {@see BiteKnowledge}. A workspace that needs hundreds of documents
 * needs the asset library and `read_file`, which is what those are for.
 *
 * # Why the title is separate from the body
 *
 * The title is what the admin scans in the list and what the model gets as the
 * heading of each fact, so a lookup for "release process" surfaces in both
 * places. It is not a key: nothing joins on it, and two entries may share one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bite_memories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();

            /*
             * Who wrote it, kept so the screen can say who to ask about a fact
             * that has gone stale. Nulls on delete rather than cascading: the
             * fact outlives the employment of whoever typed it, and deleting a
             * departing admin must not silently change what the assistant knows.
             */
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('title');
            $table->text('body');

            /*
             * Switched off rather than deleted.
             *
             * The reason is the one every "it started answering wrongly"
             * incident runs into: the fix is to stop a fact being used *now*,
             * and the question ten minutes later is what it used to say. A
             * disabled entry answers both; a deleted row answers neither and
             * makes the admin retype it if they were wrong about the cause.
             */
            $table->boolean('is_enabled')->default(true);

            /*
             * The order they are shown in and fed to the model. Sparse, in
             * hundreds, so a reorder can insert between two neighbours without
             * renumbering the whole list — the same scheme the project boards
             * use for cards.
             */
            $table->integer('position')->default(0);

            $table->timestampsTz();
        });

        /*
         * Both readers want the same thing: this workspace's entries, in the
         * order the admin arranged them. The prompt builder additionally
         * filters on `is_enabled`, which is cheap enough over a list this size
         * that it does not want its own partial index.
         */
        DB::statement('CREATE INDEX bite_memories_workspace_index ON bite_memories (workspace_id, position, id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('bite_memories');
    }
};
