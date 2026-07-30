<?php

namespace App\Actions\Conversations;

use App\Enums\ConversationType;
use App\Events\ConversationStarted;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Opens a conversation with a set of people, reusing one where that is what the
 * user means.
 *
 * # Why "find or create" and not just "create"
 *
 * Messaging the same colleague twice must land in the same thread. If it did
 * not, history would fragment across near-identical rows and the sidebar would
 * fill with duplicates of one person.
 *
 * # Why the database decides, not this class
 *
 * The obvious implementation — look for an existing conversation, create one if
 * absent — is a race. Two people opening a DM with each other at the same
 * moment both find nothing and both insert, and the loser is not detected
 * because nothing in the application saw the other request. The partial unique
 * index on `(workspace_id, participants_hash) WHERE type = 'direct'` is the only
 * thing that can actually rule this out, so the insert is allowed to fail and
 * the violation is treated as "someone else just created it" rather than as an
 * error.
 *
 * Callers must have verified that every participant is an active member of the
 * workspace. Participants arrive as resolved models for that reason: an id from
 * the request cannot reach this far.
 */
class FindOrCreateConversation
{
    /**
     * @param  Collection<int, User>  $others  Everyone besides the creator.
     * @param  string|null  $name  A group title. Always produces a new group
     *                             conversation; direct messages are never named.
     */
    public function handle(
        Workspace $workspace,
        User $creator,
        Collection $others,
        ?string $name = null,
    ): Conversation {
        $participantIds = $others
            ->map(fn (User $user): int => $user->id)
            ->push($creator->id)
            ->unique()
            ->sort()
            ->values();

        $type = $participantIds->count() > 2
            ? ConversationType::Group
            : ConversationType::Direct;

        $hash = Conversation::hashParticipants($participantIds->all());

        /*
         * A named group is always a new conversation: the name is the thing
         * distinguishing it from whatever else those same people share.
         */
        $reusable = $name === null
            ? $this->findExisting($workspace, $hash, $type)
            : null;

        if ($reusable !== null) {
            $this->reinstate($reusable, $participantIds);

            return $reusable;
        }

        try {
            return $this->create($workspace, $creator, $participantIds, $type, $name);
        } catch (UniqueConstraintViolationException $exception) {
            /*
             * Lost the race for a direct conversation. The row the winner
             * inserted is the correct answer, so re-read it — re-throwing would
             * surface an error for something that in fact succeeded.
             *
             * The re-read runs after the failed transaction has rolled back;
             * Postgres refuses further statements on an aborted transaction.
             */
            return $this->findExisting($workspace, $hash, $type) ?? throw $exception;
        }
    }

    private function findExisting(Workspace $workspace, string $hash, ConversationType $type): ?Conversation
    {
        return $workspace->conversations()
            ->where('participants_hash', $hash)
            ->where('type', $type)
            ->when($type->isDirect(), fn ($query) => $query, fn ($query) => $query->whereNull('name'))
            ->with('participants')
            ->first();
    }

    /**
     * @param  Collection<int, int>  $participantIds
     */
    private function create(
        Workspace $workspace,
        User $creator,
        Collection $participantIds,
        ConversationType $type,
        ?string $name,
    ): Conversation {
        $conversation = DB::transaction(function () use ($workspace, $creator, $participantIds, $type, $name): Conversation {
            $conversation = Conversation::create([
                'workspace_id' => $workspace->id,
                'created_by' => $creator->id,
                'type' => $type,
                // The database also refuses a named direct conversation; this
                // keeps the two from ever disagreeing.
                'name' => $type->isDirect() ? null : $name,
                'participants_hash' => Conversation::hashParticipants($participantIds->all()),
            ]);

            $now = now();

            ConversationParticipant::insert(
                $participantIds
                    ->map(fn (int $userId): array => [
                        'conversation_id' => $conversation->id,
                        'user_id' => $userId,
                        'joined_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])
                    ->all(),
            );

            return $conversation;
        });

        ConversationStarted::dispatch($conversation, $participantIds->all());

        return $conversation->load('participants');
    }

    /**
     * Bring back anyone who had left the group being reused.
     *
     * Starting a conversation with someone is an unambiguous request to have
     * them in it. Silently returning a thread they cannot post to would look
     * like the message simply never arrived.
     *
     * @param  Collection<int, int>  $participantIds
     */
    private function reinstate(Conversation $conversation, Collection $participantIds): void
    {
        $returning = $conversation->participants()
            ->whereIn('user_id', $participantIds->all())
            ->whereNotNull('left_at')
            ->get();

        if ($returning->isEmpty()) {
            return;
        }

        $conversation->participants()
            ->whereKey($returning->modelKeys())
            ->update(['left_at' => null, 'joined_at' => now()]);

        $conversation->unsetRelation('participants');

        ConversationStarted::dispatch(
            $conversation,
            $returning->map(fn (ConversationParticipant $participant): int => $participant->user_id)->all(),
        );
    }
}
