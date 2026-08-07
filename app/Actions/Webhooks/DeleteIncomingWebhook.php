<?php

namespace App\Actions\Webhooks;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\AuditAction;
use App\Models\IncomingWebhook;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Removes a hook from the list for good.
 *
 * Distinct from revoking, and the second step rather than an alternative to it:
 * revoking is what stops the URL, and this is what tidies the screen afterwards.
 * A hook that is deleted while still live is revoked on the way out, so there is
 * no order of operations an admin can get wrong.
 *
 * The bot account and every message it posted are left alone. It authored them,
 * and a channel's history should not lose its authors because somebody cleaned
 * up an integrations list — see {@see RevokeIncomingWebhook} for why the account
 * being unreachable is what makes that safe.
 *
 * The audit entry outlives the row it points at. `auditable_id` is left dangling
 * on purpose: the log is append-only, and an entry that quietly rewrote itself
 * when its subject was removed would be a log that cannot be trusted.
 */
class DeleteIncomingWebhook
{
    public function __construct(private readonly RecordAuditLog $audit) {}

    public function handle(IncomingWebhook $webhook, User $actor): void
    {
        DB::transaction(function () use ($webhook, $actor): void {
            $this->audit->handle(
                workspace: $webhook->workspace,
                action: AuditAction::WebhookDeleted,
                actor: $actor,
                auditable: $webhook,
                oldValues: [
                    'name' => $webhook->name,
                    'channel' => $webhook->channel->slug,
                    'delivery_count' => $webhook->delivery_count,
                    // Whether it was still live when it went, which is the
                    // difference between housekeeping and an incident.
                    'was_live' => ! $webhook->isRevoked(),
                ],
            );

            $webhook->delete();
        });
    }
}
