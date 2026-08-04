<?php

namespace App\Http\Controllers\Notifications;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\Services\ActivityFeed;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Everything Snack has raised to you in this workspace.
 *
 * A history rather than a queue, like the mentions inbox: rows are stamped read
 * and kept, so this still answers "what came in while I was away last week"
 * after the badge has cleared.
 *
 * The list itself is built by {@see ActivityFeed}, which the Activity page's
 * Notifications tab reads too — this page is the same feed at its own address,
 * and the two must not be able to disagree about what is in it.
 */
class NotificationController extends Controller
{
    public function __construct(private readonly ActivityFeed $feed) {}

    public function index(Workspace $workspace, Request $request): InertiaResponse
    {
        return Inertia::render('notifications/index', [
            'notifications' => $this->feed->notifications($workspace, $request->user(), $request),
        ]);
    }
}
