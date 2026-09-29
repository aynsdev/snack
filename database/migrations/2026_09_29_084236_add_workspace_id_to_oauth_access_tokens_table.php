<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The workspace a personal connector token was created for.
 *
 * A person may belong to several workspaces, and an MCP client holds one URL
 * and one token — it has no idea of "the workspace you have open". Binding the
 * token to a workspace on the row, rather than letting a tool call name one, is
 * what keeps an assistant set up for one team from reading another's channels.
 *
 * Null for tokens obtained through the OAuth sign-in: those resolve their
 * workspace through `mcp_grants`, keyed by the client, because a refresh mints
 * a new token row and the choice has to outlive every one of them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oauth_access_tokens', function (Blueprint $table) {
            $table->foreignId('workspace_id')->nullable()->after('client_id')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('oauth_access_tokens', function (Blueprint $table) {
            $table->dropConstrainedForeignId('workspace_id');
        });
    }
};
