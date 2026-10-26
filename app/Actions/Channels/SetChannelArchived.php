<?php

namespace App\Actions\Channels;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\AuditAction;
use App\Models\Channel;
use App\Models\User;
use App\Services\Outbound\AgentSettingsEvents;

/**
 * Archives or restores a channel.
 *
 * One action taking a boolean rather than two, because archiving and restoring
 * are the same write to the same column and differ only in which audit entry
 * they leave. Two classes would be two copies of one line.
 *
 * # Archiving keeps everything
 *
 * No membership is dropped and no message is deleted. `is_archived` is read by
 * `Channel::scopeNotArchived()` — so the channel leaves the sidebar and the
 * browser, and ChannelPolicy refuses posts to it — but its history stays
 * searchable and every link to it keeps resolving. That is the whole difference
 * between archiving a channel and deleting one, and it is why Snack offers the
 * first and not the second.
 *
 * Idempotent: archiving an archived channel writes nothing and logs nothing, so
 * a double-submitted form does not put two entries in the audit trail.
 */
class SetChannelArchived
{
    public function __construct(
        private readonly RecordAuditLog $audit,
        private readonly AgentSettingsEvents $agentSettings,
    ) {}

    public function handle(Channel $channel, User $actor, bool $archived): Channel
    {
        if ($channel->is_archived === $archived) {
            return $channel;
        }

        // A project's agents stop with their channel, and start again with it.
        $this->agentSettings->aroundChannelChange($channel, function () use ($channel, $actor, $archived): void {
            $channel->forceFill(['is_archived' => $archived])->save();

            $this->audit->handle(
                workspace: $channel->workspace,
                action: $archived
                    ? AuditAction::ChannelArchived
                    : AuditAction::ChannelUnarchived,
                actor: $actor,
                auditable: $channel,
                newValues: ['name' => $channel->name, 'slug' => $channel->slug],
            );
        });

        return $channel;
    }
}
