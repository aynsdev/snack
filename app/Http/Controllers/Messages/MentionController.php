<?php

namespace App\Http\Controllers\Messages;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\Services\ActivityFeed;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Everything addressed to you in this workspace.
 *
 * A history rather than a queue: rows are stamped read, never deleted, so this
 * still answers "what was I asked about last week" after the badge has cleared.
 *
 * The list itself is built by {@see ActivityFeed}, which the Activity page's
 * Mentions tab reads too — this page is the same feed at its own address, and
 * the two must not be able to disagree about what counts as a mention.
 */
class MentionController extends Controller
{
    public function __construct(private readonly ActivityFeed $feed) {}

    public function index(Workspace $workspace, Request $request): InertiaResponse
    {
        return Inertia::render('mentions/index', [
            'mentions' => $this->feed->mentions($workspace, $request->user(), $request),
        ]);
    }
}
