<?php

use App\Http\Middleware\AuthenticateMcpConnector;
use App\Mcp\Servers\SnackServer;
use Laravel\Mcp\Facades\Mcp;

/*
|--------------------------------------------------------------------------
| MCP
|--------------------------------------------------------------------------
|
| The server AI clients connect to. Outside the `web` group for the reason the
| incoming webhooks are: the caller is a program with a bearer token, not a
| browser with a session and a CSRF token.
|
| `auth:sanctum` proves the token is real; AuthenticateMcpConnector then
| insists it is a connector token, for a workspace its owner still belongs to.
| A session cookie alone does not get in — see that middleware.
|
*/

Mcp::web('/mcp', SnackServer::class)
    ->middleware(['auth:sanctum', AuthenticateMcpConnector::class, 'throttle:mcp'])
    ->name('mcp');
