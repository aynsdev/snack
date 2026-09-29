<?php

namespace App\Http\Middleware;

use App\Mcp\ConnectorAccess;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The door to the MCP server, after Passport has said the token is genuine.
 *
 * `auth:api` proves the bearer token is a live Passport token. This insists it
 * is one meant for MCP — carrying the `mcp:use` scope — and that it still
 * resolves to a workspace its owner belongs to. A former member, a suspended
 * one, or a client whose grant was disconnected is refused here, before any
 * tool runs. See {@see ConnectorAccess} for how the workspace is found.
 *
 * {@see App\Mcp\Tools\SnackTool} checks the same thing again per tool call.
 */
class AuthenticateMcpConnector
{
    public function __construct(private readonly ConnectorAccess $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $this->access->workspaceFor($user) === null) {
            return response()->json([
                'message' => 'This connector no longer has access to a Snack workspace.',
            ], 403);
        }

        return $next($request);
    }
}
