<?php

namespace App\Actions\Connectors;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\AuditAction;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

/**
 * Cut an AI client off. The token row is deleted outright — there is no
 * "turn it back on", because the plaintext was never kept and nobody could
 * paste it anywhere again anyway.
 */
class RevokeConnectorToken
{
    public function __construct(private readonly RecordAuditLog $audit) {}

    public function handle(Workspace $workspace, PersonalAccessToken $token, User $actor): void
    {
        DB::transaction(function () use ($workspace, $token, $actor): void {
            $this->audit->handle(
                workspace: $workspace,
                action: AuditAction::ConnectorRevoked,
                actor: $actor,
                auditable: $token,
                oldValues: ['name' => $token->name],
            );

            $token->delete();
        });
    }
}
