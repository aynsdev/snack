<?php

namespace App\Actions\Connectors;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\AuditAction;
use App\Enums\ConnectorKind;
use App\Enums\OutboundEventType;
use App\Models\McpGrant;
use App\Models\OAuthToken;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Outbound\OutboundEvents;
use Illuminate\Support\Facades\DB;

/**
 * Cut an AI client off, whichever way it was connected.
 *
 * - A personal token is revoked, with its refresh token if it had one.
 * - A signed-in app loses its {@see McpGrant} and every token it holds for this
 *   person. Deleting the grant alone would already refuse its next request —
 *   see App\Mcp\ConnectorAccess — but a revoked token also cannot be refreshed,
 *   so the client finds out at once rather than on its next attempt.
 */
class DisconnectConnector
{
    public function __construct(
        private readonly RecordAuditLog $audit,
        private readonly OutboundEvents $events,
    ) {}

    public function token(Workspace $workspace, OAuthToken $token, User $actor): void
    {
        $this->events->transaction([$workspace->id], function () use ($workspace, $token, $actor): void {
            // Read under the lock, so revoking a token twice records and
            // reports it once.
            if (OAuthToken::query()->lockForUpdate()->find($token->id)?->revoked !== false) {
                return;
            }

            $this->revoke($token);

            $this->audit->handle(
                workspace: $workspace,
                action: AuditAction::ConnectorRevoked,
                actor: $actor,
                // No subject row, for the reason given in CreateConnectorToken.
                oldValues: ['name' => $token->name, 'kind' => $token->connector_kind->value],
            );

            // The dispatcher pairs an agent with its connector, and must stop
            // routing work to one whose credential no longer opens anything.
            if ($token->connector_kind === ConnectorKind::Agent && $token->user_id !== null) {
                $this->events->record(OutboundEventType::AgentConnectorRevoked, $workspace, [
                    'user_id' => $token->user_id,
                    'connector_id' => $token->id,
                ]);
            }
        });
    }

    public function grant(Workspace $workspace, McpGrant $grant, User $actor): void
    {
        DB::transaction(function () use ($workspace, $grant, $actor): void {
            OAuthToken::query()
                ->where('user_id', $grant->user_id)
                ->where('client_id', $grant->client_id)
                ->where('revoked', false)
                ->each(fn (OAuthToken $token) => $this->revoke($token));

            $this->audit->handle(
                workspace: $workspace,
                action: AuditAction::ConnectorRevoked,
                actor: $actor,
                auditable: $grant,
                oldValues: ['name' => $grant->client->name, 'kind' => 'app'],
            );

            $grant->delete();
        });
    }

    private function revoke(OAuthToken $token): void
    {
        $token->revoke();
        $token->refreshToken?->revoke();
    }
}
