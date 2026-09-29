<?php

namespace App\Mcp;

use App\Models\McpGrant;
use App\Models\OAuthToken;
use App\Models\User;
use App\Models\Workspace;
use Laravel\Mcp\Server\Registrar;
use Laravel\Passport\AccessToken;

/**
 * Which workspace an MCP request may act in, decided from its token alone.
 *
 * Two kinds of token reach the MCP server, and both end up here:
 *
 * - **Personal tokens** from a workspace's AI connectors page. The workspace is
 *   a column on the token row, set when it was created.
 * - **OAuth tokens** an AI client got by sending the person through Snack's
 *   consent screen. The workspace is the one they picked there, stored in
 *   `mcp_grants` against the client — so every refreshed token inherits it,
 *   and deleting the grant disconnects the client on its very next request.
 *
 * Either way the person must still be an active member when the request
 * arrives. A token outlives its owner's membership; this is what makes that
 * irrelevant.
 */
class ConnectorAccess
{
    public function workspaceFor(?User $user): ?Workspace
    {
        if ($user === null || $user->isBot()) {
            return null;
        }

        $token = $user->token();

        if (! $token instanceof AccessToken || ! $token->can(Registrar::OAUTH_SCOPE)) {
            return null;
        }

        $workspace = $this->personalTokenWorkspace($token) ?? $this->grantedWorkspace($user, $token);

        return $workspace !== null && $user->belongsToWorkspace($workspace) ? $workspace : null;
    }

    /**
     * @param  AccessToken<mixed>  $token
     */
    private function personalTokenWorkspace(AccessToken $token): ?Workspace
    {
        $id = $token->oauth_access_token_id ?? null;

        if (! is_string($id)) {
            return null;
        }

        return OAuthToken::query()->with('workspace')->find($id)?->workspace;
    }

    /**
     * @param  AccessToken<mixed>  $token
     */
    private function grantedWorkspace(User $user, AccessToken $token): ?Workspace
    {
        $clientId = $token->oauth_client_id ?? null;

        if (! is_string($clientId)) {
            return null;
        }

        return McpGrant::query()
            ->with('workspace')
            ->where('user_id', $user->id)
            ->where('client_id', $clientId)
            ->first()
            ?->workspace;
    }
}
