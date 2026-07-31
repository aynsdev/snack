<?php

namespace App\Http\Controllers\Messages;

use App\Actions\Messages\TogglePin;
use App\Http\Controllers\Controller;
use App\Http\Resources\MessageResource;
use App\Models\Message;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pinning a message to its channel or conversation.
 *
 * Authorized as `pin`, which {@see App\Policies\MessagePolicy} resolves to the
 * container's `pinMessages` — posting rights, not read access. A pin changes
 * what everyone in the room sees, so it takes the same standing as saying
 * something there.
 *
 * Anyone who could pin can also unpin: a pin belongs to the conversation rather
 * than to whoever clicked, so requiring the original pinner would leave stale
 * pins nobody present could clear.
 */
class PinController extends Controller
{
    public function __construct(private readonly TogglePin $pins) {}

    public function store(
        Workspace $workspace,
        Message $message,
        Request $request,
    ): JsonResponse {
        $this->authorize('pin', $message);

        $this->pins->pin($message, $request->user());

        return $this->summary($message, $request);
    }

    public function destroy(
        Workspace $workspace,
        Message $message,
        Request $request,
    ): JsonResponse {
        $this->authorize('pin', $message);

        $this->pins->unpin($message);

        return $this->summary($message, $request);
    }

    /**
     * Everything pinned in this message's container.
     *
     * Returned by both endpoints rather than just the changed message, because
     * the caller is rendering a bar of *all* pins and would otherwise have to
     * follow up with a second request to redraw it.
     */
    private function summary(Message $message, Request $request): JsonResponse
    {
        $container = $message->container();

        return response()->json([
            'pinned' => $container === null ? [] : $this->pins->pinnedIn($container)
                ->map(fn (Message $pinned): array => (new MessageResource($pinned))->resolve($request))
                ->all(),
        ]);
    }
}
