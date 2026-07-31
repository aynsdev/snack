<?php

namespace App\Http\Controllers\Notifications;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Models\Message;
use App\Models\Notification;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Everything Snack has raised to you in this workspace.
 *
 * A history rather than a queue, like the mentions inbox: rows are stamped read
 * and kept, so this still answers "what came in while I was away last week"
 * after the badge has cleared.
 *
 * Scoping is `notifiable` plus `workspace_id` and nothing else — a row exists
 * because {@see App\Services\NotificationRecipients} already established that
 * this person could see the message when it was written.
 */
class NotificationController extends Controller
{
    public function index(Workspace $workspace, Request $request): InertiaResponse
    {
        $notifications = Notification::query()
            ->forWorkspace($workspace, $request->user())
            ->latest()
            ->limit((int) config('snack.notifications.list_limit', 50))
            ->get();

        return Inertia::render('notifications/index', [
            'notifications' => NotificationResource::collection(
                $this->withoutDeletedMessages($notifications),
            )->resolve($request),
        ]);
    }

    /**
     * Drop the rows whose message has since been deleted.
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
