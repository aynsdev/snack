<?php

namespace App\Http\Controllers\Api\V1\Workspaces;

use App\Actions\Connectors\CreateConnectorToken;
use App\Actions\Connectors\DisconnectConnector;
use App\Http\Controllers\Controller;
use App\Models\McpGrant;
use App\Models\OAuthToken;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * The AI connectors working in this workspace as you — the mirror of
 * {@see \App\Http\Controllers\Workspaces\ConnectorController}. Per person, not
 * admin-gated: everything resolves through the caller and this workspace, so
 * another person's token or app is a 404.
 *
 * The app's own mobile token is not a connector and never appears here: it is
 * bound to no workspace.
 */
class ConnectorController extends Controller
{
    public function index(Workspace $workspace, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'data' => [
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
                'server_url' => url('/mcp'),
                'limit' => (int) config('snack.mcp.max_tokens_per_member'),
            ],
        ]);
    }

    /**
     * Issue a personal token. The plaintext `token` is in this response and
     * nowhere else, ever — only its hash is kept.
     */
    public function store(Workspace $workspace, Request $request, CreateConnectorToken $createToken): JsonResponse
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

        return response()->json([
            'data' => [
                'id' => $issued->getToken()?->getKey(),
                'name' => trim($validated['name']),
                'token' => $issued->accessToken,
                'server_url' => url('/mcp'),
            ],
        ], 201);
    }

    public function destroyToken(
        Workspace $workspace,
        string $token,
        Request $request,
        DisconnectConnector $disconnect,
    ): Response {
        /** @var User $user */
        $user = $request->user();

        $disconnect->token($workspace, $this->tokens($workspace, $user)->findOrFail($token), $user);

        return response()->noContent();
    }

    public function destroyApp(
        Workspace $workspace,
        int $grant,
        Request $request,
        DisconnectConnector $disconnect,
    ): Response {
        /** @var User $user */
        $user = $request->user();

        $disconnect->grant($workspace, $this->grants($workspace, $user)->with('client')->findOrFail($grant), $user);

        return response()->noContent();
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
     * @return Builder<McpGrant>
     */
    private function grants(Workspace $workspace, User $user): Builder
    {
        return McpGrant::query()
            ->where('user_id', $user->id)
            ->where('workspace_id', $workspace->id);
    }
}
