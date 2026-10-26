<?php

namespace App\Actions\Channels;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\AuditAction;
use App\Enums\ChannelType;
use App\Models\Channel;
use App\Models\Project;
use App\Models\User;
use App\Services\Outbound\AgentSettingsEvents;
use App\Services\Outbound\OutboundEvents;
use App\Services\SlugGenerator;

/**
 * Renames, re-describes, and re-types a channel.
 *
 * Authorization is the caller's responsibility — see
 * {@see App\Http\Requests\Channels\UpdateChannelRequest}, which gates on
 * ChannelPolicy::update.
 *
 * # The slug follows the name
 *
 * A channel is addressed as `#{slug}` in its URL and rendered as `#{slug}`
 * everywhere it is referred to out of context, so a channel renamed to "Product
 * Design" while still answering to `#design` would be permanently inconsistent
 * with what the interface says it is called.
 *
 * The cost is that a link pasted before the rename stops resolving. That is the
 * right trade for something that happens rarely and deliberately, and the
 * controller redirects to the new slug so the person doing it is never left on
 * a dead URL.
 *
 * `ignoreId` is what keeps a no-op rename from walking the channel to
 * `design-2`: without it the channel's own row counts as the collision.
 */
class UpdateChannel
{
    public function __construct(
        private readonly SlugGenerator $slugs,
        private readonly RecordAuditLog $audit,
        private readonly AgentSettingsEvents $agentSettings,
        private readonly OutboundEvents $events,
    ) {}

    /**
     * @param  array{name?: string, description?: string|null, topic?: string|null, type?: ChannelType}  $attributes
     */
    public function handle(Channel $channel, User $actor, array $attributes): Channel
    {
        /*
         * Read again under the workspace lock, and the edit applied to that
         * read: another edit committed while this one waited must not be
         * undone by it, nor described wrongly in the audit log or to the
         * agent dispatcher.
         */
        $fresh = $this->events->transaction([$channel->workspace_id], function () use ($channel, $actor, $attributes): Channel {
            $fresh = Channel::query()->lock('for no key update')->findOrFail($channel->id);
            $fresh->setRelation('workspace', $channel->workspace);

            $this->apply($fresh, $actor, $attributes);

            return $fresh;
        });

        // The caller's instance is brought up to date rather than replaced, so
        // anything it already holds — loaded relations, a route binding — stays.
        $channel->setRawAttributes($fresh->getAttributes(), sync: true);

        return $channel;
    }

    /**
     * @param  array{name?: string, description?: string|null, topic?: string|null, type?: ChannelType}  $attributes
     */
    private function apply(Channel $channel, User $actor, array $attributes): void
    {
        $before = $this->snapshot($channel);

        if (array_key_exists('name', $attributes) && $attributes['name'] !== $channel->name) {
            $channel->name = $attributes['name'];
            $channel->slug = $this->slugs->forChannel(
                $channel->workspace,
                $attributes['name'],
                ignoreId: $channel->id,
            );
        }

        foreach (['description', 'topic'] as $field) {
            if (array_key_exists($field, $attributes)) {
                $channel->{$field} = $attributes[$field];
            }
        }

        if (array_key_exists('type', $attributes)) {
            $channel->type = $attributes['type'];
        }

        /*
         * Nothing actually changed — somebody opened the form and saved it. An
         * audit entry for that is noise in the one log that has to stay
         * readable.
         */
        if (! $channel->isDirty()) {
            return;
        }

        $save = function (array $projectIds) use ($channel, $actor, $before): void {
            $channel->save();

            /*
             * An announcement channel is one only admins may post in, so it can
             * no longer hold an agent's threads: projects using it lose it, the
             * same as if a lead had cleared the setting.
             */
            if ($channel->type === ChannelType::Announcement && $projectIds !== []) {
                Project::query()->whereKey($projectIds)->update(['agent_channel_id' => null]);
            }

            $this->audit->handle(
                workspace: $channel->workspace,
                action: AuditAction::ChannelUpdated,
                actor: $actor,
                auditable: $channel,
                // Both sides, because "who renamed #general and what was it called
                // before?" is the question this entry exists to answer.
                oldValues: $before,
                newValues: $this->snapshot($channel),
            );
        };

        // A rename, or a change of who can see or post in it, changes what
        // agents using this channel can do — see AgentSettingsEvents.
        if ($channel->isDirty(['name', 'type'])) {
            $this->agentSettings->aroundChannelChange($channel, $save, visibility: $channel->isDirty('type'), actor: $actor);
        } else {
            $save([]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Channel $channel): array
    {
        return [
            'name' => $channel->name,
            'slug' => $channel->slug,
            'description' => $channel->description,
            'topic' => $channel->topic,
            'type' => $channel->type->value,
        ];
    }
}
