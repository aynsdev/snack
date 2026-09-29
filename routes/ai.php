<?php

use App\Http\Controllers\Mcp\AuthorizationServerMetadataController;
use App\Http\Middleware\AuthenticateMcpConnector;
use App\Mcp\Servers\SnackServer;
use Illuminate\Support\Facades\Route;
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
| `oauthRoutes()` publishes the discovery documents and dynamic client
| registration an OAuth-capable client (Claude, for one) uses to find Passport
| and sign the person in. Tokens pasted by hand from the AI connectors page go
| through the same `auth:api` guard.
|
| AuthenticateMcpConnector then insists the token is an MCP token that still
| resolves to a workspace its owner belongs to.
|
*/

/*
 * Throttled because client registration is open to the internet by design —
 * an MCP client registers itself before anybody has signed in. The redirect
 * domains it may register are limited in config/mcp.php.
 */
Route::middleware('throttle:20,1')->group(fn () => Mcp::oauthRoutes());

/*
 * Registered after oauthRoutes() so these replace the package's version of the
 * same two URLs — see AuthorizationServerMetadataController for what it adds.
 */
Route::get('.well-known/oauth-authorization-server', AuthorizationServerMetadataController::class)
    ->name('mcp.oauth.authorization-server');
Route::get('.well-known/oauth-authorization-server/{path}', AuthorizationServerMetadataController::class)
    ->where('path', '.*')
    ->name('mcp.oauth.authorization-server.nested');

Mcp::web('/mcp', SnackServer::class)
    ->middleware(['auth:api', AuthenticateMcpConnector::class, 'throttle:mcp'])
    ->name('mcp');
