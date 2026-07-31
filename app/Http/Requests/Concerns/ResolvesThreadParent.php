<?php

namespace App\Http\Requests\Concerns;

use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;

/**
 * Resolves the `parent_message_id` a reply names into a real parent message.
 *
 * # The rule this exists to enforce
 *
 * `parent_message_id` is the one field on the send endpoints that names another
 * row, which makes it the one field that could reach outside the container the
 * route already authorized. A reply naming a message in a private channel the
 * sender cannot see would otherwise attach itself there — and, through the
 * thread panel, hand back that message's replies.
 *
 * So the parent is never loaded by id. It is loaded *through the container's own
 * messages relation*, which the route binding already scoped to this workspace
 * and the policy already authorized. A parent that is not in this container does
 * not resolve, and an unresolvable parent is a 404 rather than a 403: confirming
 * "that message exists, just not here" is the leak this is preventing.
 */
trait ResolvesThreadParent
{
    /**
     * The message being replied to, or null for a top-level post.
     *
     * Deleted messages are excluded by the relation's soft-delete scope: a
     * thread whose root was removed accepts no new replies.
     */
    protected function resolveParent(Channel|Conversation $container): ?Message
    {
        if (! $this->filled('parent_message_id')) {
            return null;
        }

        $parent = $container->messages()
            ->whereKey($this->integer('parent_message_id'))
            ->first();

        abort_if($parent === null, 404);

        return $parent;
    }

    /**
     * @return array<int, string>
     */
    protected function parentRules(): array
    {
        // Existence and reachability are settled by resolveParent() against the
        // container, not by an `exists` rule against the whole table — which
        // would happily confirm a message in someone else's workspace.
        return ['nullable', 'integer', 'min:1'];
    }
}
