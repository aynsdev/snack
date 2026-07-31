<?php

namespace App\Http\Controllers\Messages;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\Workspace;
use App\Services\ThreadHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * The contents of one thread.
 *
 * JSON rather than an Inertia response, for the same reason the timeline
 * endpoints are: the panel is a live client-owned list merging paginated
 * replies, WebSocket arrivals, and optimistic sends.
 *
 * Replies are *posted* through the container's own message endpoint with a
 * `parent_message_id`, not through here. Threads are not a separate kind of
 * message with a separate pipeline — one send path means a reply cannot end up
 * with different validation, different broadcasting, or a different idea of
 * which workspace it belongs to than the message it answers.
 */
class ThreadController extends Controller
{
    public function __construct(private readonly ThreadHistory $history) {}

    /**
     * The "Threads" view: every thread the user follows in this workspace.
     *
     * An Inertia page rather than JSON — unlike the panel, this is a destination
     * you navigate to, and it has no live list to merge into.
     */
    public function index(Workspace $workspace, Request $request): InertiaResponse
    {
        return Inertia::render('threads/index', [
            'threads' => $this->history->followed($workspace, $request->user(), $request),
        ]);
    }

    /**
     * The thread root and a page of its replies.
     */
    public function show(
        Workspace $workspace,
        Message $message,
        Request $request,
    ): JsonResponse {
        $this->authorize('view', $message);

        /*
         * Opening a reply opens the thread it belongs to. The client addresses
         * threads by root, but a "reply in thread" link out of search or a
         * mention names the reply — and both must land on the same panel rather
         * than one of them producing an empty thread.
         */
        $root = $message->isThreadReply()
            ? $message->parent()->firstOrFail()
            : $message;

        $cursor = $request->integer('after') ?: null;

        return response()->json($this->history->page($root, $request, $cursor));
    }
}
