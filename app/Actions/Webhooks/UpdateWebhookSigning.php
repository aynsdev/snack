<?php

namespace App\Actions\Webhooks;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\AuditAction;
use App\Models\IncomingWebhook;
use App\Models\User;
use App\Services\WebhookSignature;
use Illuminate\Support\Facades\DB;

/**
 * Turns signing on or off for a hook, or replaces its secret.
 *
 * Three operations rather than one toggle, because rotating is neither enabling
 * nor disabling: a hook that already requires signatures still requires them
 * afterwards, and the only thing that changed is which secret satisfies it. A
 * boolean would have had no way to express it, and a second "enable" on an
 * already-signed hook silently rotating would be a foot-gun — somebody
 * re-saving a form would break a live integration.
 *
 * # Every one of these breaks the caller, on purpose
 *
 * Enabling makes unsigned deliveries start failing. Rotating makes deliveries
 * signed with the old secret start failing. Disabling is the only harmless one,
 * and it is harmless because it *removes* a check. There is no version of this
 * that is safe to do without also updating the system on the other end, which
 * is why the UI states the consequence and the audit log records who accepted
 * it.
 *
 * Deliberately no grace period where both the old and new secret are accepted.
 * That would make rotation seamless and would mean a leaked secret stays valid
 * for the length of the window — which is precisely the situation rotation
 * exists to end.
 */
class UpdateWebhookSigning
{
    public function __construct(private readonly RecordAuditLog $audit) {}

    /**
     * Require signed deliveries, generating a secret if there is not one.
     *
     * Idempotent: a hook that is already signed keeps the secret it has. See the
     * class note on why re-enabling must not quietly rotate.
     */
    public function enable(IncomingWebhook $webhook, User $actor): IncomingWebhook
    {
        if ($webhook->requiresSignature()) {
            return $webhook;
        }

        return $this->apply(
            $webhook,
            $actor,
            WebhookSignature::generateSecret(),
            AuditAction::WebhookSigningEnabled,
        );
    }

    /**
     * Go back to the URL being the whole credential.
     */
    public function disable(IncomingWebhook $webhook, User $actor): IncomingWebhook
    {
        if (! $webhook->requiresSignature()) {
            return $webhook;
        }

        return $this->apply(
            $webhook,
            $actor,
            null,
            AuditAction::WebhookSigningDisabled,
        );
    }

    /**
     * Replace the secret, keeping signing on.
     *
     * The remedy for a leaked secret, and the reason the whole hook does not
     * have to be recreated for one: the URL is unaffected, so every system
     * holding it keeps its configuration and only the secret line changes.
     */
    public function rotate(IncomingWebhook $webhook, User $actor): IncomingWebhook
    {
        if (! $webhook->requiresSignature()) {
            // Nothing to rotate. Enabling is the operation being asked for, and
            // doing it silently here would hide which one actually happened
            // from the audit log.
            return $this->enable($webhook, $actor);
        }

        return $this->apply(
            $webhook,
            $actor,
            WebhookSignature::generateSecret(),
            AuditAction::WebhookSecretRotated,
        );
    }

    private function apply(
        IncomingWebhook $webhook,
        User $actor,
        ?string $secret,
        AuditAction $action,
    ): IncomingWebhook {
        return DB::transaction(function () use ($webhook, $actor, $secret, $action): IncomingWebhook {
            $webhook->signing_secret = $secret;
            $webhook->save();

            $this->audit->handle(
                workspace: $webhook->workspace,
                action: $action,
                actor: $actor,
                auditable: $webhook,
                /*
                 * The name and the new state, never the secret itself — not even
                 * masked. An audit log is read, exported, and often shipped
                 * somewhere central, and a credential in it would defeat the
                 * encryption on the column it came from.
                 */
                newValues: [
                    'name' => $webhook->name,
                    'signing_required' => $secret !== null,
                ],
            );

            return $webhook;
        });
    }
}
