<?php

namespace App\Http\Controllers\Api\V1\Account;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Broadcast;

/**
 * Authorizing the app's Echo subscriptions with its bearer token.
 *
 * The container channels in routes/channels.php (`channel.*`, `conversation.*`,
 * `thread.*`, `workspace.*`, `project.*`) resolve their user from the `web`
 * guard only. That is deliberate, and it stays: adding `api` there would also
 * open them through the framework's own `/broadcasting/auth`, which answers a
 * GET outside CSRF and is not behind EnsureMobileToken, to any Passport token,
 * MCP connectors included.
 *
 * Instead, this endpoint is reachable only with a mobile token (the route group
 * runs `auth:api` and EnsureMobileToken first), and it hands that already
 * verified user to the `web` guard for this one request. The channel callbacks
 * then authorize exactly as they do for the browser. Nothing is written to a
 * session: the API routes have none.
 */
class BroadcastAuthController extends Controller
{
    public function __invoke(Request $request): mixed
    {
        Auth::guard('web')->setUser($request->user());

        return Broadcast::auth($request);
    }
}
