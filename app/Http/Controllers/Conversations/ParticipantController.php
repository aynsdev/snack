<?php

namespace App\Http\Controllers\Conversations;

use App\Actions\Conversations\AddConversationParticipants;
use App\Actions\Conversations\LeaveConversation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Conversations\StoreConversationParticipantRequest;
use App\Models\Conversation;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Who is in a group conversation.
 *
 * Adding and leaving are the same resource seen from two directions, so they
 * share a controller. Neither is available on a direct message: the policy
 * refuses both, because a two-person thread that could gain a third person would
 * break the uniqueness its index guarantees, and one that could be left would
 * leave nothing to return to.
 */
class ParticipantController extends Controller
{
    public function store(
        Workspace $workspace,
        Conversation $conversation,
        StoreConversationParticipantRequest $request,
        AddConversationParticipants $addParticipants,
    ): RedirectResponse {
        $added = $addParticipants->handle(
            conversation: $conversation,
            actor: $request->user(),
            users: $request->participants(),
        );

        if ($added->isEmpty()) {
            return back()->with('error', 'Everyone you chose is already in this conversation.');
        }

        return back()->with('success', sprintf(
            'Added %s.',
            $added->map(fn (User $user): string => $user->name)->join(', ', ' and '),
        ));
    }

    /**
     * Leave the conversation.
     *
     * Sends the user back to the workspace rather than to the conversation they
     * just left: they keep read access to the history, but landing back in a
     * thread you have chosen to leave is not what "leave" means.
     */
    public function destroy(
        Workspace $workspace,
        Conversation $conversation,
        Request $request,
        LeaveConversation $leave,
    ): RedirectResponse {
        $this->authorize('leave', $conversation);

        $leave->handle($conversation, $request->user());

        return to_route('workspaces.show', ['workspace' => $workspace->slug])
            ->with('success', 'You left the conversation.');
    }
}
