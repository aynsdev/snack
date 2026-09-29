<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sanctum's personal access tokens, used to connect AI clients to the MCP
 * server at `/mcp`.
 *
 * The table is Sanctum's own, plus one column: `workspace_id`.
 *
 * # A token belongs to one workspace
 *
 * A person may be a member of several workspaces, and an MCP client has no
 * notion of "the workspace you currently have open" — it holds one URL and one
 * bearer token. Binding the token to a workspace on the row, rather than
 * letting a tool call name one, is what keeps an assistant configured for
 * one team from quietly reading another's channels. The same reasoning as
 * `incoming_webhooks.channel_id`: the destination is a column, not a field a
 * caller may supply.
 *
 * Cascading on delete for both owners: a token whose workspace or person is
 * gone authenticates nobody, and leaving it behind would only leave a hash
 * someone might one day be tempted to reuse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->foreignId('workspace_id')->nullable()->constrained()->cascadeOnDelete();
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();

            $table->index(['workspace_id', 'tokenable_type', 'tokenable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
