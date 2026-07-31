<?php

namespace App\Actions\Channels;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\AuditAction;
use App\Enums\ChannelType;
use App\Models\Channel;
use App\Models\User;
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
    ) {}

    /**
     * @param  array{name?: string, description?: string|null, topic?: string|null, type?: ChannelType}  $attributes
     */
    public function handle(Channel $channel, User $actor, array $attributes): Channel
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
            return $channel;
        }

        $channel->save();

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

        return $channel;
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
