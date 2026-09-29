<?php

namespace App\Http\Controllers\Workspaces;

use App\Actions\Connectors\CreateConnectorToken;
use App\Actions\Connectors\RevokeConnectorToken;
use App\Http\Controllers\Controller;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The connectors screen: MCP tokens that let an AI client — Claude, Cursor,
 * anything that speaks MCP — work in this workspace as you.
 *
 * Per person rather than admin-gated, unlike webhooks. A connector can do only
 * what its owner can already do by hand, so issuing one grants nothing new, and
 * each member sees and manages only their own. Admins see who has connected
 * what in the audit log.
 *
 * # Showing the token once
 *
 * `store` renders the page rather than redirecting, and hands the plaintext
 * over as Inertia flash data on that one response. A redirect would have to
 * park the credential in the session store to survive the hop — the thing
 * {@see IncomingWebhookController::store()} is careful to avoid — whereas a
 * rendered response carries it straight to the browser and nowhere else.
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
            'name.required' => 'Give the connector a name, like the app you are connecting.',
        ]);

        /** @var User $user */
        $user = $request->user();

        $limit = (int) config('snack.mcp.max_tokens_per_member');

        if ($this->tokens($workspace, $user)->count() >= $limit) {
            throw ValidationException::withMessages([
                'name' => "You can have at most {$limit} connectors in one workspace. Revoke one you no longer use first.",
            ]);
        }

        $issued = $createToken->handle($workspace, $user, trim($validated['name']));

        return $this->page($workspace, $request)->flash('connector', [
            'id' => $issued->accessToken->getKey(),
            'name' => $issued->accessToken->name,
            'token' => $issued->plainTextToken,
        ]);
    }

    public function destroy(
        Workspace $workspace,
        int $token,
        Request $request,
        RevokeConnectorToken $revokeToken,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();

        /*
         * Resolved through the owner and the workspace together, so a token id
         * belonging to someone else — or to this person in another workspace —
         * is a 404 rather than something to authorize and refuse.
         */
        $connector = $this->tokens($workspace, $user)->findOrFail($token);

        $revokeToken->handle($workspace, $connector, $user);

        return to_route('connectors.index', ['workspace' => $workspace->slug])
            ->with('success', "{$connector->name} has been disconnected.");
    }

    private function page(Workspace $workspace, Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('workspace/connectors', [
            'connectors' => $this->tokens($workspace, $user)
                ->latest('id')
                ->get()
                ->map(fn (PersonalAccessToken $token): array => [
                    'id' => $token->id,
                    'name' => $token->name,
                    'last_used_at' => $token->last_used_at?->toIso8601String(),
                    'created_at' => $token->created_at?->toIso8601String(),
                ])
                ->all(),

            // Where clients connect. Absolute, because it is pasted into
            // software running somewhere else.
            'server_url' => url('/mcp'),
            'limit' => (int) config('snack.mcp.max_tokens_per_member'),
        ]);
    }

    /**
     * This person's connector tokens for this workspace.
     *
     * @return Builder<PersonalAccessToken>
     */
    private function tokens(Workspace $workspace, User $user): Builder
    {
        return PersonalAccessToken::query()
            ->whereMorphedTo('tokenable', $user)
            ->where('workspace_id', $workspace->id);
    }
}
