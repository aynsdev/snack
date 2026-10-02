<?php

namespace App\Http\Controllers\Activity;

use App\Enums\ActivityTab;
use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\Services\ActivityFeed;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Everything waiting for you, in one place.
 *
 * Notifications, mentions, and followed threads were three destinations
 * answering one question — "what needs me?" — and made the reader ask it three
 * times. They are tabs here, with All as the merged answer.
 *
 * # Why the tab is in the URL
 *
 * It is where you were, so it has to survive a reload, a shared link, and the
 * back button. It also decides which query runs: only the open tab's list is
 * built, so moving between them costs one small request rather than three
 * lists' worth of work on every visit.
 *
 * The dedicated pages stay reachable at their own routes — the sidebar still
 * links Threads — and read the same {@see ActivityFeed}, so a tab and the page
 * it mirrors cannot drift apart.
 */
class ActivityController extends Controller
{
    public function __construct(private readonly ActivityFeed $feed) {}

    public function index(Workspace $workspace, Request $request): InertiaResponse
    {
        $user = $request->user();

        /*
         * An unknown `?tab=` falls back to All rather than 404ing. This is a
         * hand-editable query parameter on a read-only page, and a reader who
         * mistypes it should land on the feed that contains everything rather
         * than on an error.
         */
        $tab = ActivityTab::tryFrom((string) $request->query('tab')) ?? ActivityTab::All;

        // The merged feed interleaves three lists, so each contributes a slice
        // rather than a full page — fifty of each would bury whichever kind was
        // quietest under whichever was busiest.
        $limit = $tab->isAll() ? (int) config('snack.activity.merged_limit', 20) : null;

        return Inertia::render('activity/index', [
            'tab' => $tab->value,

            /*
             * Each list is present only when its tab is open, so the props say
             * which lists this page is actually showing. `null` means "not this
             * tab", which is a different thing from `[]` — nothing to show — and
             * the empty states depend on the difference.
             */
            'notifications' => $tab->includesNotifications()
                ? $this->feed->notifications($workspace, $user, $request, $limit, unreadOnly: $tab->isUnreadOnly())
                : null,

            'mentions' => $tab->includesMentions()
                ? $this->feed->mentions($workspace, $user, $request, $limit)
                : null,

            'threads' => $tab->includesThreads()
                ? $this->feed->threads($workspace, $user, $request)
                : null,
        ]);
    }
}
