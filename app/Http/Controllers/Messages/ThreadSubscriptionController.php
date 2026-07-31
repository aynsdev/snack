<?php

namespace App\Http\Controllers\Messages;

use App\Actions\Messages\SubscribeToThread;
use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\Workspace;
use App\Services\ThreadHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Following and unfollowing a thread.
 *
 * Following is normally implicit — replying subscribes you — so these endpoints
 * exist for the two cases where the implicit rule is wrong: someone who wants
 * to watch a thread without answering it, and someone who wants a thread they
 * replied to weeks ago to stop badging them.
 *
 * Authorized as `view` on the message rather than `create`: following is a
 * private preference of the follower's, and a person who may read a thread may
 * decide whether it notifies them. It grants no ability to post.
 */
class ThreadSubscriptionController extends Controller
{
    public function __construct(
        private readonly SubscribeToThread $subscriptions,
        private readonly ThreadHistory $history,
    ) {}

    public function store(
        Workspace $workspace,
        Message $message,
        Request $request,

    ): JsonResponse {
        $this->authorize('view', $message);

        $root = $this->root($message);

        $this->subscriptions->follow($root->id, $request->user()->id);

        return response()->json($this->history->subscription($root, $request->user()));
    }

    public function destroy(
        Workspace $workspace,
        Message $message,
        Request $request,
    ): JsonResponse {
        $this->authorize('view', $message);

        $root = $this->root($message);

        $this->subscriptions->unfollow($root->id, $request->user());

        return response()->json($this->history->subscription($root, $request->user()));
    }

    /**
     * Subscriptions are always against the thread root, so a request naming a
     * reply is normalized rather than rejected — see the same rule in
     * ThreadController.
     */
    private function root(Message $message): Message
    {
        return $message->isThreadReply()
            ? $message->parent()->firstOrFail()
            : $message;
    }
}
