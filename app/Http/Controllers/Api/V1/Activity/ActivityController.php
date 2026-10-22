<?php

namespace App\Http\Controllers\Api\V1\Activity;

use App\Enums\ActivityTab;
use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\Services\ActivityFeed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Notifications, mentions and followed threads as tabs over one feed — the
 * mirror of {@see \App\Http\Controllers\Activity\ActivityController}.
 *
 * `?tab=` picks the list (an unknown value falls back to `all`). Only the open
 * tab's lists are built: a list that is not part of the tab is `null`, which
 * is different from `[]` — nothing to show.
 */
class ActivityController extends Controller
{
    public function __construct(private readonly ActivityFeed $feed) {}

    public function index(Workspace $workspace, Request $request): JsonResponse
    {
        $user = $request->user();

        $tab = ActivityTab::tryFrom((string) $request->query('tab')) ?? ActivityTab::All;

        $limit = $tab->isAll() ? (int) config('snack.activity.merged_limit', 20) : null;

        return response()->json([
            'data' => [
                'tab' => $tab->value,
                'notifications' => $tab->includesNotifications()
                    ? $this->feed->notifications($workspace, $user, $request, $limit, unreadOnly: $tab->isUnreadOnly())
                    : null,
                'mentions' => $tab->includesMentions()
                    ? $this->feed->mentions($workspace, $user, $request, $limit)
                    : null,
                'threads' => $tab->includesThreads()
                    ? $this->feed->threads($workspace, $user, $request)
                    : null,
            ],
        ]);
    }
}
