<?php

namespace App\Http\Controllers\Api\V1\Notifications;

use App\Actions\Notifications\MarkNotificationsRead;
use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\Services\ActivityFeed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The notification centre — the mirror of
 * {@see \App\Http\Controllers\Notifications\NotificationController}, built by
 * the same {@see ActivityFeed} so the app and the web cannot disagree about
 * what is in it. Marking one read is the web's own JSON endpoint.
 */
class NotificationController extends Controller
{
    public function __construct(private readonly ActivityFeed $feed) {}

    /**
     * The newest notifications (bounded by `snack.notifications.list_limit`),
     * read and unread alike.
     */
    public function index(Workspace $workspace, Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->feed->notifications($workspace, $request->user(), $request),
        ]);
    }

    /**
     * Mark every unread notification in this workspace read.
     */
    public function markAllRead(Workspace $workspace, Request $request, MarkNotificationsRead $markRead): Response
    {
        $markRead->all($workspace, $request->user());

        return response()->noContent();
    }
}
