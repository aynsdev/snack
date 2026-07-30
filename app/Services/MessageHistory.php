<?php

namespace App\Services;

use App\Http\Resources\MessageResource;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Collection;

/**
 * Cursor-paginated history for a channel or a conversation.
 *
 * # Why a cursor and not a page number
 *
 * Offset pagination assumes the list holds still. A channel does not: by the
 * time someone scrolls up, new messages have shifted every row down, so
 * `OFFSET 50` returns rows the reader has already seen and skips ones they have
 * not. Anchoring on the last id they actually hold makes the query independent
 * of anything posted since — and `WHERE id < ? ORDER BY id DESC LIMIT n` is a
 * backwards walk along the partial index created for exactly this query, so
 * page 100 costs the same as page 1.
 *
 * The full history is never loaded. Each request reads one page and stops.
 *
 * # One implementation for both containers
 *
 * Channels and conversations differ in *who may read them*, not in how their
 * history is shaped: both are a stream of root-level messages keyed by a
 * monotonic id, and both have their own partial index supporting exactly this
 * walk. Sharing the implementation is what keeps a DM from quietly drifting
 * into loading its history differently from a channel. The difference that does
 * matter — authorization — is settled by the caller before this is reached.
 */
class MessageHistory
{
    /**
     * A page of root-level messages, oldest-first, ending just before `$before`.
     *
     * Shaped for the client's timeline store:
     *
     *  - `messages`    ascending by id, so it renders or prepends as-is
     *  - `has_more`    whether an older page exists
     *  - `next_cursor` the id to pass back as `before` to fetch that page
     *
     * @return array{messages: array<int, array<string, mixed>>, has_more: bool, next_cursor: int|null}
     */
    public function page(Channel|Conversation $container, ?int $before = null): array
    {
        $size = (int) config('snack.messages.page_size', 50);

        /*
         * One row more than the page size, purely to answer "is there another
         * page?" without a second COUNT over the whole history — which is the
         * expensive question, not the page itself.
         */
        $rows = $container->messages()
            ->rootLevel()
            ->before($before)
            ->withTimelineRelations()
            ->orderByDesc('id')
            ->limit($size + 1)
            ->get();

        $hasMore = $rows->count() > $size;

        // Queried descending to walk the index, then flipped: the timeline
        // renders oldest at the top.
        $messages = $rows->take($size)->reverse()->values();

        $this->shareContainer($container, $messages);

        return [
            'messages' => $messages
                ->map(fn (Message $message): array => (new MessageResource($message))->resolve(request()))
                ->all(),
            'has_more' => $hasMore,
            'next_cursor' => $hasMore ? $messages->first()?->id : null,
        ];
    }

    /**
     * Give every message the one container instance they all belong to.
     *
     * MessageResource asks the policy what this viewer may do with each
     * message, and MessagePolicy answers by consulting the message's container.
     * Left alone, that lazy-loads the channel or conversation, its workspace,
     * and its membership rows *per message* — an N+1 that scales with page size
     * and is invisible until there is real history to page through.
     *
     * Sharing one already-hydrated instance turns every one of those lookups
     * into an in-memory read. The membership is constrained to the viewer,
     * which is the only one any of these checks asks about.
     *
     * @param  Collection<int, Message>  $messages
     */
    private function shareContainer(Channel|Conversation $container, Collection $messages): void
    {
        $viewer = request()->user();

        $isChannel = $container instanceof Channel;

        /*
         * The relation is loaded *empty* rather than skipped for an
         * unauthenticated request. An unloaded relation is re-queried by every
         * policy check, which is the N+1 this method exists to prevent.
         */
        $container->loadMissing([
            'workspace',
            ($isChannel ? 'members' : 'participants') => fn ($query) => $viewer === null
                ? $query->whereRaw('1 = 0')
                : $query->where('user_id', $viewer->id),
        ]);

        $relation = $isChannel ? 'channel' : 'conversation';

        $messages->each(fn (Message $message) => $message->setRelation($relation, $container));
    }
}
