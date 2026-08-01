<?php

namespace App\Services;

use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Hydrate everything {@see App\Policies\MessagePolicy} consults, for a list of
 * messages that spans more than one container.
 *
 * # Why this exists separately from MessageHistory
 *
 * Serializing a message asks the policy what the viewer may do with it, and the
 * policy answers by consulting the message's container, that container's
 * workspace, and the viewer's membership of it. A timeline has exactly one
 * container, so {@see MessageHistory::shareContainer()} can hand the same
 * already-hydrated instance to every row and spend no queries at all.
 *
 * The views that list messages *out of context* — Mentions, Saved, and anything
 * added later with the same shape — cannot do that: their rows come from
 * anywhere in the workspace. What they can do is hydrate the distinct set once,
 * which is what this does. Eloquent hands every message sharing a container the
 * same model instance, so priming the deduplicated set primes all of them.
 *
 * # The trap this closes
 *
 * Both callers previously eager-loaded the container with a column subset —
 * `channel:id,name,slug,type,workspace_id`. That looks like a saving and is not:
 * ContainerSummary picks its own fields, so the subset never made the payload
 * smaller, while ChannelPolicy reads `is_archived`, `is_private` and
 * `is_default`, none of which were selected. Unselected columns read back as
 * null rather than raising, so an archived channel silently reported itself as
 * live and the Mentions view offered an Edit action the server would refuse.
 *
 * Load the whole container row. The policy needs it, and nothing was being
 * saved by not having it.
 */
class MessageContainers
{
    /**
     * @param  Collection<int, Message>  $messages
     */
    public function prime(Collection $messages, ?User $viewer = null): void
    {
        $messages = $messages->filter()->values();

        if ($messages->isEmpty()) {
            return;
        }

        $viewer ??= request()->user();

        $rows = new EloquentCollection($messages->all());

        /*
         * The message's own workspace as well as the container's: the admin
         * override in MessagePolicy::delete() reads it directly off the message
         * rather than going through the container.
         */
        $rows->loadMissing(['workspace', 'channel', 'conversation']);

        $this->hydrate($rows, 'channel', 'members', $viewer);
        $this->hydrate($rows, 'conversation', 'participants', $viewer);
    }

    /**
     * Prime one container kind across the whole page.
     *
     * @param  EloquentCollection<int, Message>  $messages
     */
    private function hydrate(
        EloquentCollection $messages,
        string $relation,
        string $membership,
        ?User $viewer,
    ): void {
        /** @var EloquentCollection<int, Model> $containers */
        $containers = new EloquentCollection(
            $messages->pluck($relation)->filter()->unique('id')->values()->all(),
        );

        if ($containers->isEmpty()) {
            return;
        }

        $containers->loadMissing([
            'workspace',
            /*
             * Loaded *empty* rather than skipped for an unauthenticated
             * request. An unloaded relation is re-queried by every policy
             * check, which is the N+1 this method exists to prevent.
             */
            $membership => fn ($query) => $viewer === null
                ? $query->whereRaw('1 = 0')
                : $query->where('user_id', $viewer->id),
        ]);
    }
}
