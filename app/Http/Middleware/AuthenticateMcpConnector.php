<?php

namespace App\Http\Middleware;

use App\Models\PersonalAccessToken;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The door to the MCP server, after Sanctum has said the token is genuine.
 *
 * Three things `auth:sanctum` alone would let through, and this refuses:
 *
 * - **A browser session.** Sanctum's guard falls back to the session cookie for
 *   first-party requests. A page on snack.test that could POST to `/mcp` with
 *   the visitor's cookie would be driving their account through a door meant
 *   for a program they chose to connect, so a real token is required.
 * - **A token minted for something else.** Only tokens carrying the connector
 *   ability, and bound to a workspace, open this server.
 * - **A former member.** A token outlives its owner's membership. Removed or
 *   suspended, the token stops working at once rather than when somebody
 *   remembers to revoke it.
 *
 * {@see App\Mcp\Tools\SnackTool} checks the same things again per tool call.
 */
class AuthenticateMcpConnector
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $this->refuse('A Snack connector token is required.', 401);
        }

        $token = $user->currentAccessToken();

        if (! $token instanceof PersonalAccessToken || ! $token->isMcpConnector()) {
            return $this->refuse('A Snack connector token is required.', 401);
        }

        $workspace = $token->workspace;

        if ($workspace === null || $user->isBot() || ! $user->belongsToWorkspace($workspace)) {
            return $this->refuse('This connector no longer has access to its workspace.', 403);
        }

        return $next($request);
    }

    private function refuse(string $message, int $status): Response
    {
        return response()->json(['message' => $message], $status);
    }
}
