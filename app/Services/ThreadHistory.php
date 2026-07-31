<?php

namespace App\Services;

use App\Http\Resources\ContainerSummary;
use App\Http\Resources\MessageResource;
use App\Models\Message;
use App\Models\ThreadSubscription;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The contents of one thread: its root, and a page of replies.
 *
 * # Why replies page forwards and the timeline pages backwards
 *
 * A channel is entered at the bottom and read upwards, so its history walks
 * *back* from the newest message. A thread is entered at the top — you open it
 * to read the answer to something — so it walks *forward* from the root. Same
 * cursor mechanics on the same monotonic id, opposite direction, and the
 * `messages_thread_index` partial index is built ascending for exactly this.
 *
 * The root is returned alongside rather than as the first reply. It is not part
 * of the reply list — it has no place in the count, and the panel renders it
 * differently — and pretending otherwise would make every consumer slice it
 * back off.
 */
class ThreadHistory
{
    /**
     * The root plus a page of replies newer than `$after`.
     *
     * @return array{
     *     root: array<string, mixed>,
     *     replies: array<int, array<string, mixed>>,
     *     has_more: bool,
     *     next_cursor: int|null,
     *     subscription: array{is_subscribed: bool, last_read_reply_id: int|null},
     * }
     */
    public function page(Message $root, Request $request, ?int $after = null): array
    {
        $size = (int) config('snack.messages.thread_page_size', 50);

        // One row past the page purely to answer "is there more?" without a
        // second COUNT over the whole thread.
        $rows = $root->replies()
            ->after($after)
            ->withTimelineRelations()
            ->orderBy('id')
            ->limit($size + 1)
            ->get();

        $hasMore = $rows->count() > $size;
        $replies = $rows->take($size)->values();

        $this->shareContainer($root, $replies);

        return [
            'root' => (new MessageResource($root))->resolve($request),
            'replies' => $replies
                ->map(fn (Message $reply): array => (new MessageResource($reply))->resolve($request))
                ->all(),
            'has_more' => $hasMore,
            'next_cursor' => $hasMore ? $replies->last()?->id : null,
            'subscription' => $this->subscription($root, $request->user()),
        ];
    }

    /**
     * Every thread the user follows in this workspace, newest activity first.
     *
     * Backs the "Threads" view, which is a digest rather than a feed: it shows
     * each thread once with its reply count, not every reply. Bounded by
     * `snack.threads.list_limit` — someone who has followed hundreds of threads
     * navigates by the ones that just moved, not by scrolling to the bottom.
     *
     * The unread flag is computed from the two pointers already selected, so no
     * row here costs an extra query.
     *
     * @return array<int, array<string, mixed>>
     */
    public function followed(Workspace $workspace, User $user, Request $request): array
    {
        $limit = (int) config('snack.threads.list_limit', 50);

        $subscriptions = ThreadSubscription::query()
            ->for($user)
            ->whereHas('root', fn ($query) => $query->where('workspace_id', $workspace->id))
            ->with([
                'root' => fn ($query) => $query->withTimelineRelations()->with([
                    'channel:id,name,slug,type,workspace_id',
                    'conversation',
                ]),
            ])
            ->get()
            /*
             * Ordered in PHP rather than SQL: the sort key lives on the joined
             * root, and the set is already bounded by how many threads one
             * person follows.
             *
             * Keyed on `last_reply_id` rather than `last_reply_at` for the same
             * reason the timeline is: ids are monotonic and assigned by one
             * authority, while two replies posted in the same second carry the
             * same timestamp and would sort arbitrarily. A thread nobody has
             * answered yet falls back to its root's id, so it sorts by when it
             * was posted instead of vanishing to the bottom.
             */
            ->sortByDesc(fn (ThreadSubscription $subscription): int => $subscription->root->last_reply_id
                ?? $subscription->root_message_id)
            ->take($limit);

        return $subscriptions
            ->filter(fn (ThreadSubscription $subscription): bool => $subscription->root !== null)
            ->map(fn (ThreadSubscription $subscription): array => [
                'root' => (new MessageResource($subscription->root))->resolve($request),
                'container' => ContainerSummary::for($subscription->root),
                'is_unread' => $subscription->hasUnread($subscription->root->last_reply_id),
                'last_read_reply_id' => $subscription->last_read_reply_id,
            ])
            ->values()
            ->all();
    }

    /**
     * Where this viewer stands in the thread.
     *
     * @return array{is_subscribed: bool, last_read_reply_id: int|null}
     */
    public function subscription(Message $root, ?User $user): array
    {
        if ($user === null) {
            return ['is_subscribed' => false, 'last_read_reply_id' => null];
        }

        $subscription = ThreadSubscription::query()
            ->where('root_message_id', $root->id)
            ->where('user_id', $user->id)
            ->first();

        return [
            'is_subscribed' => $subscription !== null,
            'last_read_reply_id' => $subscription?->last_read_reply_id,
        ];
    }

    /**
     * Give the root and every reply the one container instance they share.
     *
     * MessageResource asks the policy what this viewer may do with each message,
     * and MessagePolicy answers by consulting the message's container. Left
     * alone that lazy-loads the channel or conversation, its workspace, and its
     * membership rows *per reply* — the same N+1 MessageHistory::shareContainer
     * exists to prevent, and just as invisible until a thread gets long.
     *
     * @param  Collection<int, Message>  $replies
     */
    private function shareContainer(Message $root, Collection $replies): void
    {
        $container = $root->container();

        if ($container === null) {
            return;
        }

        $viewer = request()->user();
        $isChannel = $root->isInChannel();

        $container->loadMissing([
            'workspace',
            ($isChannel ? 'members' : 'participants') => fn ($query) => $viewer === null
                ? $query->whereRaw('1 = 0')
                : $query->where('user_id', $viewer->id),
        ]);

        $relation = $isChannel ? 'channel' : 'conversation';

        $root->setRelation($relation, $container);
        $replies->each(fn (Message $reply) => $reply->setRelation($relation, $container));
    }
}
