<?php

namespace App\Actions\Webhooks;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\AuditAction;
use App\Models\Channel;
use App\Models\IncomingWebhook;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

/**
 * Mints a hook and its URL.
 *
 * Authorization is the caller's responsibility, and the channel arrives as a
 * resolved model — the request cannot name one, which is what stops an admin of
 * workspace A from aiming a hook at a channel in workspace B.
 *
 * The bot account is deliberately *not* created here; see {@see WebhookBot} for
 * why a hook that has never fired should not have put an account in a channel's
 * member list.
 */
class CreateIncomingWebhook
{
    public function __construct(private readonly RecordAuditLog $audit) {}

    /**
     * @param  array{name: string, default_username?: string|null, default_icon?: string|null}  $attributes
     */
    public function handle(
        Workspace $workspace,
        Channel $channel,
        User $creator,
        array $attributes,
    ): IncomingWebhook {
        $token = IncomingWebhook::generateToken();

        return DB::transaction(function () use ($workspace, $channel, $creator, $attributes, $token): IncomingWebhook {
            $webhook = IncomingWebhook::create([
                // From the channel, not the request: a hook must never be able
                // to name a workspace other than its channel's.
                'workspace_id' => $channel->workspace_id,
                'channel_id' => $channel->id,
                'created_by' => $creator->id,
                'name' => $attributes['name'],
                'default_username' => $attributes['default_username'] ?? null,
                'default_icon' => $attributes['default_icon'] ?? null,
                'token_hash' => IncomingWebhook::hashToken($token),
                'token' => $token,
            ]);

            $this->audit->handle(
                workspace: $workspace,
                action: AuditAction::WebhookCreated,
                actor: $creator,
                auditable: $webhook,
                /*
                 * The channel is the interesting value and the token is not:
                 * an audit log is a thing admins read and export, and copying a
                 * live credential into it would make the log a second place the
                 * URL leaks from.
                 */
                newValues: [
                    'name' => $webhook->name,
                    'channel' => $channel->slug,
                ],
            );

            return $webhook->setRelation('channel', $channel);
        });
    }
}
