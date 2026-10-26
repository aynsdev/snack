<?php

namespace App\Actions\Connectors;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\AuditAction;
use App\Enums\ConnectorKind;
use App\Models\OAuthToken;
use App\Models\User;
use App\Models\Workspace;
use App\Support\PersonalAccessClient;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Server\Registrar;
use Laravel\Passport\PersonalAccessTokenResult;

/**
 * Issue a personal token an AI client uses to reach the MCP server as this
 * person, in this workspace — for clients configured by pasting a bearer
 * token rather than by signing in.
 *
 * The plaintext comes back exactly once, in the returned result. Passport keeps
 * only the token's id, so there is nothing to show again later; losing it means
 * creating another, which is the right trade for a credential that can read
 * private channels.
 *
 * Authorization is the caller's: the person must be an active member of the
 * workspace, which the `workspace` middleware has already established.
 *
 * An agent connector is the same token with a different label — see
 * {@see ConnectorKind} for what that changes, which is attribution and nothing
 * about access.
 */
class CreateConnectorToken
{
    public function __construct(
        private readonly RecordAuditLog $audit,
        private readonly PersonalAccessClient $personalAccessClient,
    ) {}

    /**
     * @return PersonalAccessTokenResult<mixed>
     */
    public function handle(
        Workspace $workspace,
        User $user,
        string $name,
        ConnectorKind $kind = ConnectorKind::Token,
    ): PersonalAccessTokenResult {
        $this->personalAccessClient->ensure();

        return DB::transaction(function () use ($workspace, $user, $name, $kind): PersonalAccessTokenResult {
            $issued = $user->createToken($name, [Registrar::OAUTH_SCOPE]);

            /** @var OAuthToken $token */
            $token = $issued->getToken();
            $token->forceFill(['workspace_id' => $workspace->id, 'connector_kind' => $kind])->save();

            $this->audit->handle(
                workspace: $workspace,
                action: AuditAction::ConnectorCreated,
                actor: $user,
                /*
                 * No subject row: Passport token ids are strings, and the
                 * log's subject column is an integer. The name only — the log
                 * is exported and read by admins, and a credential has no
                 * business being in it.
                 */
                newValues: ['name' => $name, 'kind' => $kind->value],
            );

            return $issued;
        });
    }
}
