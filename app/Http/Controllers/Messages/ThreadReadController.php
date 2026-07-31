<?php

namespace App\Http\Controllers\Messages;

use App\Actions\Messages\SubscribeToThread;
use App\Http\Controllers\Controller;
use App\Http\Requests\Messages\MarkThreadReadRequest;
use App\Models\Message;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;

/**
 * The read pointer inside a thread.
 *
 * Called as the reader actually reaches the bottom of the panel, so it is
 * deliberately cheap: one indexed UPDATE against the subscription row.
 *
 * Reading a thread you do not follow writes nothing. Opening something to look
 * at it is not the same as asking to be told about it forever, and creating a
 * subscription here would make the thread list fill up with everything the user
 * ever glanced at.
 */
class ThreadReadController extends Controller
{
    public function __construct(private readonly SubscribeToThread $subscriptions) {}

    public function store(
        Workspace $workspace,
        Message $message,
        MarkThreadReadRequest $request,
    ): JsonResponse {
        $rootId = $request->rootId();

        $target = $request->lastReadReplyId() ?? $this->newestReplyId($rootId);

        if ($target !== null) {
            $this->subscriptions->markRead($rootId, $request->user(), $target);
        }

        return response()->json([
            'root_id' => $rootId,
            'last_read_reply_id' => $target,
        ]);
    }

    private function newestReplyId(int $rootId): ?int
    {
        $newest = Message::query()->whereKey($rootId)->value('last_reply_id');

        return $newest === null ? null : (int) $newest;
    }
}
