<?php

namespace App\Http\Controllers\Workspaces;

use App\Actions\Connectors\CreateConnectorToken;
use App\Actions\Connectors\DisconnectConnector;
use App\Http\Controllers\Controller;
use App\Models\McpGrant;
use App\Models\OAuthToken;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The AI connectors screen: every assistant working in this workspace as you.
 *
 * Two kinds, listed together because to the person they are the same thing —
 * "Claude can see my stuff":
 *
 * - **Apps** that connected by signing in (Claude's organization connectors,
 *   anything else speaking MCP OAuth). They appear here once the person
 *   approved them on the consent screen; see App\Models\McpGrant.
 * - **Tokens** created on this page for clients configured by hand.
 *
 * Per person rather than admin-gated, unlike webhooks. A connector can do only
 * what its owner can already do by hand, so issuing one grants nothing new, and
 * each member sees and manages only their own. Admins see who connected what
 * in the audit log.
 *
 * # Showing a token once
 *
 * `store` renders the page rather than redirecting, and hands the plaintext
 * over as Inertia flash data on that one response. A redirect would have to
 * park the credential in the session store to survive the hop — the thing
 * {@see IncomingWebhookController::store()} is careful to avoid.
 */
class ConnectorController extends Controller
{
    public function index(Workspace $workspace, Request $request): Response
    {
        return $this->page($workspace, $request);
    }

    public function store(Workspace $workspace, Request $request, CreateConnectorToken $createToken): Response
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
        ], [
            'name.required' => 'Give the token a name, like the app you are connecting.',
        ]);

        /** @var User $user */
        $user = $request->user();

        $limit = (int) config('snack.mcp.max_tokens_per_member');

        if ($this->tokens($workspace, $user)->count() >= $limit) {
            throw ValidationException::withMessages([
                'name' => "You can have at most {$limit} tokens in one workspace. Revoke one you no longer use first.",
            ]);
        }

        $issued = $createToken->handle($workspace, $user, trim($validated['name']));

        return $this->page($workspace, $request)->flash('connector', [
            'id' => $issued->getToken()?->getKey(),
            'name' => trim($validated['name']),
            'token' => $issued->accessToken,
        ]);
    }

    /**
     * Revoke a personal token.
     *
     * Resolved through the owner and the workspace together, so a token id
     * belonging to someone else — or to this person in another workspace — is
     * a 404 rather than something to authorize and refuse.
     */
    public function destroyToken(
        Workspace $workspace,
        string $token,
        Request $request,
        DisconnectConnector $disconnect,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();

        $connector = $this->tokens($workspace, $user)->findOrFail($token);

        $disconnect->token($workspace, $connector, $user);

        return to_route('connectors.index', ['workspace' => $workspace->slug])
            ->with('success', "{$connector->name} has been revoked.");
    }

    /**
     * Disconnect an app that signed in. Resolved the same way as a token.
     */
    public function destroyApp(
        Workspace $workspace,
        int $grant,
        Request $request,
        DisconnectConnector $disconnect,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();

        $connection = $this->grants($workspace, $user)->with('client')->findOrFail($grant);

        $disconnect->grant($workspace, $connection, $user);

        return to_route('connectors.index', ['workspace' => $workspace->slug])
            ->with('success', "{$connection->client->name} has been disconnected.");
    }

    private function page(Workspace $workspace, Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('workspace/connectors', [
            'apps' => $this->grants($workspace, $user)
                ->with('client:id,name')
                ->latest('id')
                ->get()
                ->map(fn (McpGrant $grant): array => [
                    'id' => $grant->id,
                    'name' => $grant->client->name,
                    'connected_at' => $grant->updated_at?->toIso8601String(),
                ])
                ->all(),

            'tokens' => $this->tokens($workspace, $user)
                ->latest()
                ->get()
                ->map(fn (OAuthToken $token): array => [
                    'id' => $token->id,
                    'name' => $token->name ?? 'Untitled token',
                    'created_at' => $token->created_at?->toIso8601String(),
                    'expires_at' => $token->expires_at?->toIso8601String(),
                ])
                ->all(),

            // Where clients connect. Absolute, because it is pasted into
            // software running somewhere else.
            'server_url' => url('/mcp'),
            'limit' => (int) config('snack.mcp.max_tokens_per_member'),
        ]);
    }

    /**
     * This person's live personal tokens for this workspace.
     *
     * @return Builder<OAuthToken>
     */
    private function tokens(Workspace $workspace, User $user): Builder
    {
        return OAuthToken::query()
            ->where('user_id', $user->id)
            ->where('workspace_id', $workspace->id)
            ->where('revoked', false)
            ->where(fn (Builder $query) => $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>', now()));
    }

    /**
     * The apps this person connected to this workspace by signing in.
     *
     * @return Builder<McpGrant>
     */
    private function grants(Workspace $workspace, User $user): Builder
    {
        return McpGrant::query()
            ->where('user_id', $user->id)
            ->where('workspace_id', $workspace->id);
    }
}
