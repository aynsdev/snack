<?php

namespace App\Http\Controllers\Mcp;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Models\McpGrant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Bridge\Client;
use Laravel\Passport\Bridge\Scope;
use Laravel\Passport\Http\Controllers\ApproveAuthorizationController;
use League\OAuth2\Server\RequestTypes\AuthorizationRequest;
use Symfony\Component\HttpFoundation\Response;

/**
 * The "Allow" button on Snack's OAuth consent screen.
 *
 * Passport's own approve endpoint knows about clients and scopes, but nothing
 * about workspaces — and a Snack token that does not know its workspace is a
 * token that could act in any of them. So the consent form posts here instead:
 * the chosen workspace is recorded as an {@see McpGrant} for this person and
 * this client, and only then is the approval handed on to Passport, which
 * issues the authorization code exactly as it would have.
 *
 * The client is read from the pending authorization request in the session,
 * never from the form. A form field naming a client could be edited to attach
 * this workspace to some other application the person had approved before.
 */
class AuthorizeWorkspaceController extends Controller
{
    public function __invoke(Request $request, RecordAuditLog $audit): Response
    {
        $request->validate([
            'auth_token' => ['required', 'string'],
            'workspace_id' => ['required', 'integer'],
        ], [
            'workspace_id.required' => 'Choose the workspace this assistant should work in.',
        ]);

        /** @var User $user */
        $user = $request->user();

        $clientId = $this->pendingClientId($request);

        // Active memberships only; anything else reads as "not one of yours".
        $workspace = $user->workspaces()->find($request->integer('workspace_id'))
            ?? throw ValidationException::withMessages([
                'workspace_id' => 'You are not a member of that workspace.',
            ]);

        DB::transaction(function () use ($user, $workspace, $clientId, $audit): void {
            $grant = McpGrant::query()->updateOrCreate(
                ['user_id' => $user->id, 'client_id' => $clientId],
                ['workspace_id' => $workspace->id],
            );

            $audit->handle(
                workspace: $workspace,
                action: AuditAction::ConnectorCreated,
                actor: $user,
                auditable: $grant,
                newValues: ['name' => $grant->client->name, 'kind' => 'app'],
            );
        });

        return app()->call([app(ApproveAuthorizationController::class), 'approve']);
    }

    /**
     * The client the person is approving, from the request Passport parked in
     * the session when it rendered the consent screen.
     *
     * Checked against the screen's one-time token first, the way Passport
     * checks it — but without consuming either value, because Passport's own
     * approve action is about to read both.
     */
    private function pendingClientId(Request $request): string
    {
        $expected = $request->session()->get('authToken');
        $pending = $request->session()->get('authRequest');

        if (! is_string($expected) || ! is_string($pending) || ! hash_equals($expected, (string) $request->input('auth_token'))) {
            abort(403, 'This sign-in request has expired. Start connecting again from your AI client.');
        }

        $authRequest = unserialize($pending, ['allowed_classes' => [
            AuthorizationRequest::class,
            Client::class,
            Scope::class,
            \Laravel\Passport\Bridge\User::class,
        ]]);

        if (! $authRequest instanceof AuthorizationRequest) {
            abort(403, 'This sign-in request has expired. Start connecting again from your AI client.');
        }

        return $authRequest->getClient()->getIdentifier();
    }
}
