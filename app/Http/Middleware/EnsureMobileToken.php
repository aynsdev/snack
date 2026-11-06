<?php

namespace App\Http\Middleware;

use App\Actions\Api\IssueMobileToken;
use App\Models\User;
use App\Support\PersonalAccessClient;
use Closure;
use Illuminate\Http\Request;
use Laravel\Passport\AccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * The door to the mobile API, after Passport has said the token is genuine.
 *
 * `auth:api` admits every live Passport token, and the same guard serves the
 * MCP server. This insists the token is one the mobile app signed in for: it
 * carries the `mobile` scope *and* was issued through the personal access
 * client by {@see IssueMobileToken}.
 *
 * The client check matters as much as the scope. An OAuth client registers
 * itself at the MCP discovery endpoint and could ask for any registered scope
 * on the consent screen; only Snack itself issues personal tokens.
 */
class EnsureMobileToken
{
    public const SCOPE = 'mobile';

    public function __construct(private readonly PersonalAccessClient $personalAccessClient) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $this->admits($user)) {
            return response()->json([
                'message' => 'This token cannot be used with the Snack API.',
            ], 403);
        }

        return $next($request);
    }

    /**
     * Whether the person's current bearer token is one the mobile app signed in
     * for.
     *
     * Public because {@see App\Services\ActorContext} asks the same question to
     * attribute a change to the phone rather than to an MCP connector: the two
     * must never disagree about which tokens are the app's.
     */
    public function admits(User $user): bool
    {
        $token = $user->token();

        return $token instanceof AccessToken
            && ! $user->isBot()
            && $token->can(self::SCOPE)
            && $this->personalAccessClient->issued($token->oauth_client_id ?? null);
    }
}
