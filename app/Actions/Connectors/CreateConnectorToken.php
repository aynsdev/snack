<?php

namespace App\Actions\Connectors;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\AuditAction;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\NewAccessToken;

/**
 * Issue a token an AI client uses to reach the MCP server as this person, in
 * this workspace.
 *
 * The plaintext comes back exactly once, in the returned {@see NewAccessToken}.
 * Sanctum keeps only its hash, so unlike a webhook URL there is nothing to
 * decrypt and show again later — losing it means creating another, which is
 * the right trade for a credential that can read private channels.
 *
 * Authorization is the caller's: the person must be an active member of the
 * workspace, which the `workspace` middleware has already established.
 */
class CreateConnectorToken
{
    public function __construct(private readonly RecordAuditLog $audit) {}

    public function handle(Workspace $workspace, User $user, string $name): NewAccessToken
    {
        return DB::transaction(function () use ($workspace, $user, $name): NewAccessToken {
            $issued = $user->createToken($name, [PersonalAccessToken::MCP_ABILITY]);

            /** @var PersonalAccessToken $token */
            $token = $issued->accessToken;
            $token->forceFill(['workspace_id' => $workspace->id])->save();

            $this->audit->handle(
                workspace: $workspace,
                action: AuditAction::ConnectorCreated,
                actor: $user,
                auditable: $token,
                // The name only. The log is exported and read by admins, and a
                // credential has no business being in it.
                newValues: ['name' => $name],
            );

            return $issued;
        });
    }
}
