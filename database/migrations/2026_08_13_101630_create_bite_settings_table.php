<?php

use App\Ai\BiteContext;
use App\Models\BiteSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How one workspace has configured its assistant.
 *
 * # Why this is not `workspaces.settings`
 *
 * That column holds two booleans and is read with `data_get`. What is here is a
 * dozen values of five different shapes — an enum that gates who may speak to
 * Bite, a list of channel ids, free text that is fed to a language model, and
 * numbers with real limits — and every one of them wants validation, a cast, and
 * a default that is visible in the schema rather than inferred from whatever
 * happens to be in the JSON. Putting them in a blob would mean the difference
 * between "this workspace has never configured Bite" and "an admin deliberately
 * emptied it" was a null check somebody has to remember.
 *
 * # The row is optional
 *
 * A workspace with no row here uses the installation's own defaults, which is
 * what every existing workspace does the moment this migration runs. The row is
 * created the first time somebody saves the screen — see
 * {@see BiteSetting::forWorkspace()} — so nothing has to be
 * backfilled, and "unconfigured" stays distinguishable from "configured to the
 * same values".
 *
 * # This narrows Bite, it never widens it
 *
 * Nothing here can grant access to anything. Who may read what is decided per
 * question by {@see BiteContext} against the person asking, and these
 * settings are applied on top of that: an admin can stop Bite answering in a
 * channel, and cannot make it answer with something the asker could not have
 * read themselves. That ordering is what keeps this screen from becoming a way
 * to escalate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bite_settings', function (Blueprint $table) {
            $table->id();

            /*
             * One row per workspace, enforced by the database rather than by the
             * find-or-create that reads it. Two admins saving the screen at the
             * same moment is an ordinary race, and the losing insert has to fail
             * on a constraint rather than quietly produce a second row that half
             * the application then reads instead.
             */
            $table->foreignId('workspace_id')->unique()->constrained()->cascadeOnDelete();

            /*
             * The workspace's own switch, ANDed with the installation's
             * `snack.bite.enabled`. Two switches because they answer to
             * different people: whether this deployment talks to an AI provider
             * at all is the operator's decision, and whether this workspace
             * wants an assistant is its admins'. Neither can override the other.
             */
            $table->boolean('is_enabled')->default(true);

            /** Who may summon it. See App\Enums\BiteAudience. */
            $table->string('audience', 16)->default('everyone');

            /** Where it will answer. See App\Enums\BiteChannelMode. */
            $table->string('channel_mode', 16)->default('all');

            /*
             * The channels the mode above refers to, as ids.
             *
             * A JSON array rather than a pivot table: this is a setting read
             * once per summon and written whole by one form, never joined
             * against and never queried from the other end. A channel deleted
             * out from under it leaves a stale id, which is why the read path
             * filters through the workspace's live channels rather than
             * trusting what is stored — the same rule every identifier in this
             * application follows.
             */
            $table->jsonb('channel_ids')->default('[]');

            /*
             * House instructions, appended to Bite's own system prompt.
             *
             * Bounded in the request rather than only here, but the column is
             * text because a workspace that wants a paragraph about how to talk
             * to its customers should get one. What it cannot do is replace the
             * instructions Bite ships with: this is added to them, so the rules
             * about never inventing a channel name survive whatever is typed in.
             */
            $table->text('instructions')->nullable();

            /*
             * Overrides for the two numbers that decide what one answer costs.
             * Null means "use the installation's config", so an operator raising
             * the default lifts every workspace that never touched it.
             */
            $table->unsignedSmallInteger('context_messages')->nullable();
            $table->unsignedSmallInteger('max_steps')->nullable();

            /*
             * The capabilities that are switched on, as the stable strings from
             * App\Enums\BiteCapability. Stored as an allow-list rather than a
             * deny-list on purpose: a capability added to Bite in a later
             * release arrives switched *off* for a workspace that has curated
             * this list, which is the safe direction for a list whose whole
             * point is restricting what an assistant may do.
             */
            $table->jsonb('capabilities')->nullable();

            /*
             * Who last changed it. The audit log is the record of what changed;
             * this is so the screen itself can say "last saved by Ada on
             * Tuesday" without a join to that log.
             */
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bite_settings');
    }
};
