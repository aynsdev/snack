<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which workspace a person let an OAuth client — Claude, say — work in.
 *
 * Written on Snack's consent screen, where the person picks the workspace, and
 * read on every MCP request to decide where that client's token may act. One
 * per person per client: approving the same client again for a different
 * workspace moves it rather than granting both, which keeps "what can this
 * assistant see" a single answer.
 *
 * Deleting the row is disconnecting: the next request from that client finds
 * no workspace and is refused, whatever tokens it still holds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('client_id')->constrained('oauth_clients')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'client_id']);
            $table->index(['workspace_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_grants');
    }
};
