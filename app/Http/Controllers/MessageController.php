<?php

namespace App\Http\Controllers;

use App\Actions\Messages\DeleteMessage;
use App\Actions\Messages\UpdateMessage;
use App\Http\Requests\Messages\UpdateMessageRequest;
use App\Http\Resources\MessageResource;
use App\Models\Message;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Editing and deleting a message.
 *
 * These live on the workspace rather than under a channel: a message id already
 * identifies its container, and routing edits through a channel segment would
 * invite a request that names one channel and a message from another.
 *
 * The route binding is scoped to the workspace, so a message id from a
 * different tenant resolves to a 404 before any policy runs.
 */
class MessageController extends Controller
{
    public function update(
        Workspace $workspace,
        Message $message,
        UpdateMessageRequest $request,
        UpdateMessage $updateMessage,
    ): JsonResponse {
        $updated = $updateMessage->handle($message, $request->messageBody());

        return response()->json([
            'message' => (new MessageResource($updated))->resolve($request),
        ]);
    }

    public function destroy(
        Workspace $workspace,
        Message $message,
        Request $request,
        DeleteMessage $deleteMessage,
    ): JsonResponse {
        $this->authorize('delete', $message);

        $deleteMessage->handle($message, $request->user());

        return response()->json(['id' => $message->id]);
    }
}
