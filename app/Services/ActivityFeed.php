<?php

namespace App\Services;

use App\Http\Resources\ContainerSummary;
use App\Http\Resources\MessageResource;
use App\Http\Resources\NotificationResource;
use App\Models\Mention;
use App\Models\Message;
use App\Models\Notification;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The three lists behind "Activity": what Snack raised to you, what named you,
 * and what you are following.
 *
 * Each was previously built inside its own controller, which was fine while each
 * had exactly one page. Activity renders all three — together on its "All" tab
 * and singly on the others — so the queries live here and the controllers ask
 * for them. Two implementations of "what counts as a mention" drifting apart is
 * the failure this exists to prevent: the tab and the page it mirrors would
 * disagree, and neither would look wrong on its own.
 *
 * Every method returns resolved arrays rather than models. These are read-only
 * feeds assembled for a page; nothing acts on them afterwards.
 */
class ActivityFeed
{
    public function __construct(
        private readonly MessageContainers $containers,
        private readonly ThreadHistory $history,
    ) {}

    /**
     * Everything Snack has raised to this person in this workspace.
     *
     * Scoping is `notifiable` plus `workspace_id` and nothing else — a row
     * exists because {@see NotificationRecipients} already established that this
     * person could see the message when it was written.
     *
     * @return array<int, array<string, mixed>>
     */
    public function notifications(Workspace $workspace, User $user, Request $request, ?int $limit = null): array
    {
        $notifications = Notification::query()
            ->forWorkspace($workspace, $user)
            ->latest()
            ->limit($limit ?? (int) config('snack.notifications.list_limit', 50))
            ->get();

        return NotificationResource::collection(
            $this->withoutDeletedMessages($notifications),
        )->resolve($request);
    }

    /**
     * Everything addressed to this person in this workspace.
     *
     * Scoped by `mentioned_user_id`, which is the only scoping it needs — a
     * mention row exists precisely because {@see App\Actions\Messages\SyncMentions}
     * already established that this person could see the message when it was
     * written. Messages since deleted are excluded rather than rendered as a gap.
     *
     * @return array<int, array<string, mixed>>
     */
    public function mentions(Workspace $workspace, User $user, Request $request, ?int $limit = null): array
    {
        $mentions = Mention::query()
            ->for($user, $workspace)
            ->whereHas('message')
            ->with([
                'message' => fn ($query) => $query->withTimelineRelations()->with([
                    'channel',
                    'conversation',
                ]),
            ])
            // Newest first, by the mention's own id: it is monotonic, and two
            // mentions written in the same instant would otherwise sort at random.
            ->orderByDesc('id')
            ->limit($limit ?? (int) config('snack.mentions.list_limit', 50))
            ->get();

        // These rows come from anywhere in the workspace, so the policy checks
        // during serialization would otherwise walk each container's workspace
        // and membership one message at a time.
        $this->containers->prime($mentions->pluck('message'), $user);

        /*
         * No null guard on `message`: `whereHas` runs through the relation,
         * which carries Message's soft-delete scope, so a mention whose message
         * was deleted is already excluded from the query.
         */
        return $mentions
            ->map(fn (Mention $mention): array => [
                'id' => $mention->id,
                'type' => $mention->mention_type->value,
                'is_read' => $mention->isRead(),
                'message' => (new MessageResource($mention->message))->resolve($request),
                'container' => ContainerSummary::for($mention->message),
            ])
            ->values()
            ->all();
    }

    /**
     * Every thread this person follows, newest activity first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function threads(Workspace $workspace, User $user, Request $request): array
    {
        return $this->history->followed($workspace, $user, $request);
    }

    /**
     * Drop the notification rows whose message has since been deleted.
     *
     * The payload deliberately snapshots the message body — that is what makes a
     * notification renderable without the message still existing, and what lets
     * an email say what was actually said at the time. The cost is that a
     * deleted message would otherwise keep showing its text here, long after it
     * was withdrawn from the channel it was posted in.
     *
     * So the inbox checks. One `whereIn` for the whole page rather than a
     * relation per row: `data` is JSON and holds the id, so there is no relation
     * to eager-load.
     *
     * @param  Collection<int, Notification>  $notifications
     * @return Collection<int, Notification>
     */
    private function withoutDeletedMessages(Collection $notifications): Collection
    {
        $messageIds = $notifications
            ->map(fn (Notification $notification): mixed => data_get($notification->data, 'message_id'))
            ->filter(fn (mixed $id): bool => is_int($id))
            ->unique()
            ->values();

        if ($messageIds->isEmpty()) {
            return $notifications;
        }

        // Message carries a soft-delete scope, so this returns only the ids of
        // messages that are still live.
        $live = Message::query()->whereIn('id', $messageIds)->pluck('id')->all();

        return $notifications->filter(function (Notification $notification) use ($live): bool {
            $messageId = data_get($notification->data, 'message_id');

            return ! is_int($messageId) || in_array($messageId, $live, true);
        })->values();
    }
}
