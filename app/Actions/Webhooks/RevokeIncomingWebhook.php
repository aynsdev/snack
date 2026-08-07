<?php

namespace App\Actions\Webhooks;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\AuditAction;
use App\Models\IncomingWebhook;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Turns a hook off.
 *
 * The emergency action: an integration posting nonsense into a channel at 3am is
 * stopped by whoever is awake, and the URL stops working on the next request —
 * {@see IncomingWebhook::findLiveByToken()} will not find a revoked row, so
 * there is nothing to remember to also do.
 *
 * # Why the row survives
 *
 * Because the question the next morning is "what was that, and who set it up",
 * and a deleted row answers neither. The hook keeps its name, its channel, its
 * creator, and its delivery count, and the audit entries keep pointing at
 * something that exists.
 *
 * The messages it posted survive too, obviously, and so does its bot account —
 * it authored them, and blanking the author of a year of deploy notifications to
 * tidy up a `users` row would be a bad trade. What stops the account being a
 * loose end is that it can no longer be reached: nothing can sign into it, and
 * the only thing that ever posted as it was the token that no longer resolves.
 */
class RevokeIncomingWebhook
{
    public function __construct(private readonly RecordAuditLog $audit) {}

    public function handle(IncomingWebhook $webhook, User $actor): IncomingWebhook
    {
        if ($webhook->isRevoked()) {
            // Two admins clicking at once, or a stale tab. The desired state is
            // the state — and re-stamping `revoked_at` would move the moment the
            // hook actually stopped working.
            return $webhook;
        }

        return DB::transaction(function () use ($webhook, $actor): IncomingWebhook {
            $webhook->revoked_at = now();
            $webhook->save();

            $this->audit->handle(
                workspace: $webhook->workspace,
                action: AuditAction::WebhookRevoked,
                actor: $actor,
                auditable: $webhook,
                oldValues: [
                    'name' => $webhook->name,
                    // What it managed to send before it was stopped, which is
                    // the first thing anybody investigating wants to know.
                    'delivery_count' => $webhook->delivery_count,
                ],
            );

            return $webhook;
        });
    }
}
