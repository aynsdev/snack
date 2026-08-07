<?php

namespace App\Actions\Webhooks;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\AuditAction;
use App\Models\IncomingWebhook;
use App\Models\User;
use App\Services\WebhookBot;
use Illuminate\Support\Facades\DB;

/**
 * Renames a hook, or changes what it posts as.
 *
 * # What cannot be changed
 *
 * The channel. A hook's URL is already out in the world by the time anybody
 * edits it — in a CI config, in somebody's `.env`, in a Slack-compatible client
 * that was pointed at it a year ago — and re-aiming it would silently redirect a
 * live credential at a room its holder never agreed to write to. A private
 * channel could be filled with build noise by an admin editing a dropdown, and
 * nothing on the calling side would show anything had changed.
 *
 * So a hook that should post somewhere else is a new hook, and the old one is
 * revoked. That is a worse afternoon for the admin and the only version where
 * the URL's meaning is fixed at the moment it is handed out.
 *
 * The token, likewise: rotating it is revoking a hook and issuing another, which
 * is what {@see RevokeIncomingWebhook} and {@see CreateIncomingWebhook} are for.
 * Calling it an update would suggest the old URL still works.
 */
class UpdateIncomingWebhook
{
    public function __construct(
        private readonly RecordAuditLog $audit,
        private readonly WebhookBot $bot,
    ) {}

    /**
     * @param  array{name: string, default_username?: string|null, default_icon?: string|null}  $attributes
     */
    public function handle(
        IncomingWebhook $webhook,
        User $actor,
        array $attributes,
    ): IncomingWebhook {
        $before = [
            'name' => $webhook->name,
            'default_username' => $webhook->default_username,
            'default_icon' => $webhook->default_icon,
        ];

        $webhook = DB::transaction(function () use ($webhook, $actor, $attributes, $before): IncomingWebhook {
            $webhook->fill([
                'name' => $attributes['name'],
                'default_username' => $attributes['default_username'] ?? null,
                'default_icon' => $attributes['default_icon'] ?? null,
            ])->save();

            $after = [
                'name' => $webhook->name,
                'default_username' => $webhook->default_username,
                'default_icon' => $webhook->default_icon,
            ];

            /*
             * Silent when nothing moved. An admin opening the dialog and saving
             * it unchanged is not an event, and a log full of those is a log
             * nobody scrolls through.
             */
            if ($before !== $after) {
                $this->audit->handle(
                    workspace: $webhook->workspace,
                    action: AuditAction::WebhookUpdated,
                    actor: $actor,
                    auditable: $webhook,
                    oldValues: $before,
                    newValues: $after,
                );
            }

            return $webhook;
        });

        /*
         * Outside the transaction, and last: the bot's name lives on `users`,
         * which is not the row being edited, and a failure to rename an account
         * must not roll back the admin's edit to the hook. The next delivery
         * resolves the account again either way.
         */
        $this->bot->syncName($webhook);

        return $webhook;
    }
}
