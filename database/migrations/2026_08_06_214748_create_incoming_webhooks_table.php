<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A URL an outside system posts to, which becomes a message in one channel.
 *
 * This is the "my deploy script tells #releases it shipped" integration: an
 * admin creates a hook, copies a URL, and pastes it into a CI job or an
 * application's config. Whatever holds that URL speaks into that channel.
 *
 * # The URL is the credential
 *
 * There is no signature, no timestamp check, and no shared secret beyond the
 * token in the path — deliberately, and for the reason Slack's incoming
 * webhooks work the same way: the caller is usually three lines of curl in a
 * deploy script, and every scheme needing a second secret is one those callers
 * implement wrong or skip entirely.
 *
 * What makes that acceptable is how little the credential is worth. A leaked
 * hook can post messages into one channel, as an account that renders as a bot,
 * and nothing else — it cannot read a message, list a member, or reach another
 * channel, because the destination is a column here rather than a field in the
 * request. The blast radius of the URL is the URL.
 *
 * So the token is stored the way a password is: `token_hash` is the only thing
 * lookups touch, and the plaintext lives only in `token`, encrypted at rest —
 * the same pair, for the same reason, as `workspace_invitations.link_token`. A
 * hook has no delivery mechanism other than the admin screen, so "copy URL" has
 * to keep working next month, and a hash cannot be un-hashed.
 *
 * # Why revoking is a column and not a delete
 *
 * A hook that starts posting nonsense at 3am gets turned off by whoever is
 * awake, and the question the next morning is "what was that, and who set it
 * up". A deleted row answers neither, and takes the audit entries' subject with
 * it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incoming_webhooks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();

            /*
             * The destination, fixed at creation.
             *
             * Slack's legacy payload let the caller name a `channel`, which
             * turned every leaked URL into a workspace-wide megaphone. Here the
             * channel is a column and the request has no say: the worst a stolen
             * hook can do is exactly what its owner built it to do, in the one
             * room they pointed it at.
             *
             * Cascades, because a hook aimed at a deleted channel has nowhere to
             * deliver and nothing to recover.
             */
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            /*
             * The bot account this hook speaks as, created lazily on first
             * delivery — see App\Services\WebhookBot. Nullable so an admin who
             * creates a hook and never wires it up leaves no user row behind.
             */
            $table->foreignId('bot_user_id')->nullable()->constrained('users')->nullOnDelete();

            /** What the hook is called, and what its bot is named in the channel. */
            $table->string('name');

            /*
             * Display overrides, applied to every delivery unless the payload
             * names its own. Null means "use the hook's name", which is what
             * nearly every hook wants.
             */
            $table->string('default_username')->nullable();
            $table->string('default_icon', 64)->nullable();

            // SHA-256 of the plaintext, and the only column a delivery looks up
            // by — so a stolen database yields no working URLs.
            $table->string('token_hash', 64)->unique();

            // The plaintext, encrypted at rest, so the admin screen can show the
            // URL again. See the class note.
            $table->text('token');

            /*
             * Operational history, which is the first thing anybody checks when
             * a hook "stopped working". A count rather than a log of deliveries:
             * the messages *are* the log, and they are in the channel.
             */
            $table->timestampTz('last_delivered_at')->nullable();
            $table->unsignedInteger('delivery_count')->default(0);

            $table->timestampTz('revoked_at')->nullable();

            $table->timestampsTz();
        });

        /*
         * The admin screen's query: this workspace's hooks, newest first. Not
         * partial on `revoked_at` — that screen lists revoked hooks too, which
         * is the whole reason they are kept.
         */
        DB::statement('CREATE INDEX incoming_webhooks_workspace_index ON incoming_webhooks (workspace_id, id DESC)');

        DB::statement('CREATE INDEX incoming_webhooks_channel_index ON incoming_webhooks (channel_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('incoming_webhooks');
    }
};
