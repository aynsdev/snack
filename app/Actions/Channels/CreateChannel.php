<?php

namespace App\Actions\Channels;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\AuditAction;
use App\Enums\ChannelRole;
use App\Enums\ChannelType;
use App\Events\ChannelCreated;
use App\Models\Channel;
use App\Models\ChannelMember;
use App\Models\User;
use App\Models\Workspace;
use App\Services\SlugGenerator;
use Illuminate\Support\Facades\DB;

/**
 * Creates a channel and enrolls its creator as a channel admin.
 *
 * A channel with no members would be invisible even to the person who made it,
 * so the two writes share a transaction.
 */
class CreateChannel
{
    public function __construct(
        private readonly SlugGenerator $slugs,
        private readonly RecordAuditLog $audit,
    ) {}

    /**
     * @param  array{
     *     name: string,
     *     description?: string|null,
     *     topic?: string|null,
     *     type?: ChannelType|string,
     *     is_default?: bool,
     * }  $attributes
     */
    public function handle(Workspace $workspace, User $creator, array $attributes): Channel
    {
        $type = $attributes['type'] ?? ChannelType::Public;
        $type = $type instanceof ChannelType ? $type : ChannelType::from($type);

        $channel = DB::transaction(function () use ($workspace, $creator, $attributes, $type): Channel {
            $channel = Channel::create([
                'workspace_id' => $workspace->id,
                'created_by' => $creator->id,
                'name' => $attributes['name'],
                'slug' => $this->slugs->forChannel($workspace, $attributes['name']),
                'description' => $attributes['description'] ?? null,
                'topic' => $attributes['topic'] ?? null,
                'type' => $type,
                'is_default' => $attributes['is_default'] ?? false,
                'settings' => [],
            ]);

            ChannelMember::create([
                'channel_id' => $channel->id,
                'user_id' => $creator->id,
                'role' => ChannelRole::Admin,
                'joined_at' => now(),
            ]);

            $this->audit->handle(
                workspace: $workspace,
                action: AuditAction::ChannelCreated,
                actor: $creator,
                auditable: $channel,
                newValues: [
                    'name' => $channel->name,
                    'slug' => $channel->slug,
                    'type' => $channel->type->value,
                ],
            );

            return $channel;
        });

        // Private channels are announced only to their members, which at this
        // point is just the creator, so there is nothing useful to broadcast.
        if (! $channel->is_private) {
            ChannelCreated::dispatch($channel);
        }

        return $channel;
    }
}
