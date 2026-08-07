<?php

namespace App\Actions\Webhooks;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\AuditAction;
use App\Models\IncomingWebhook;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Turns a hook back on.
 *
 * The undo for {@see RevokeIncomingWebhook}, which is a single click on a screen
 * full of them — and the thing an admin who clicked the wrong row needs is for
 * the deploy script on the other side to keep working, not a fresh URL to go and
 * paste into it.
 *
 * # Why the original token comes back
 *
 * Because minting a new one would make this button a lie. "Turn back on" that
 * silently invalidates every config already holding the URL is not an undo; it
 * is a revoke with extra steps, and the admin would discover the difference when
 * the next build failed. The token was never deleted — `token` is an encrypted
 * column and `token_hash` is still on the row — so restoring is genuinely just
 * clearing `revoked_at`.
 *
 * # When this is the wrong action
 *
 * A hook turned off *because its URL leaked* must never come back. There is no
 * way for this action to tell that case from an accidental click — nothing on
 * the row records why somebody revoked it — so the guard is not here. It is in
 * the UI, which says plainly that the original URL starts working again and
 * points at Delete for the leak case, and in the audit log, where a revoke
 * followed by a restore is two entries with two names against them.
 *
 * Deleting and recreating remains the way to rotate a token. That is deliberate:
 * rotation should cost the same as it costs the systems that hold the URL.
 */
class RestoreIncomingWebhook
{
    public function __construct(private readonly RecordAuditLog $audit) {}

    public function handle(IncomingWebhook $webhook, User $actor): IncomingWebhook
    {
        if (! $webhook->isRevoked()) {
            // Two admins clicking at once, or a stale tab. The desired state is
            // the state, and writing an audit entry for a no-op would put a
            // "turned back on" in the log for a hook that never went off.
            return $webhook;
        }

        return DB::transaction(function () use ($webhook, $actor): IncomingWebhook {
            $revokedAt = $webhook->revoked_at;

            $webhook->revoked_at = null;
            $webhook->save();

            $this->audit->handle(
                workspace: $webhook->workspace,
                action: AuditAction::WebhookRestored,
                actor: $actor,
                auditable: $webhook,
                /*
                 * How long the URL was dead. The question after an incident is
                 * "was anything missed, and for how long" — and the gap between
                 * these two is the window where deliveries were being refused.
                 */
                oldValues: [
                    'name' => $webhook->name,
                    'revoked_at' => $revokedAt?->toIso8601String(),
                ],
                newValues: ['revoked_at' => null],
            );

            return $webhook;
        });
    }
}
