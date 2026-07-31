<?php

namespace App\Http\Controllers\Notifications;

use App\Actions\Notifications\MarkNotificationsRead;
use App\Http\Controllers\Controller;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Clearing the notification badge.
 *
 * Two endpoints rather than one that takes an optional id, for the same reason
 * reactions are two idempotent endpoints instead of a toggle: "mark this one
 * read" and "mark everything read" are different intentions, and a request that
 * loses its id must not silently become the destructive one.
 *
 * Neither needs an authorization check of its own. Both delegate to
 * {@see MarkNotificationsRead}, which scopes every statement by workspace and
 * notifiable, so a request naming somebody else's notification updates nothing —
 * and the route is already behind the workspace middleware.
 */
class NotificationReadController extends Controller
{
    /**
     * Mark one notification read.
     *
     * Answers with JSON: the client marks a row read as the reader clicks
     * through to it, and a full Inertia redraw of the page they are leaving
     * would be work nobody sees.
     */
    public function store(
        Workspace $workspace,
        string $notification,
        Request $request,
        MarkNotificationsRead $markRead,
    ): JsonResponse {
        $markRead->one($workspace, $request->user(), $notification);

        return response()->json(['ok' => true]);
    }

    /**
     * Mark every unread notification in this workspace read.
     */
    public function update(
        Workspace $workspace,
        Request $request,
        MarkNotificationsRead $markRead,
    ): RedirectResponse {
        $count = $markRead->all($workspace, $request->user());

        return back()->with(
            'success',
            $count === 0 ? 'Nothing left to catch up on.' : 'Notifications marked as read.',
        );
    }
}
