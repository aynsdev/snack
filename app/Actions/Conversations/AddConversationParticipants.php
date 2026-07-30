<?php

namespace App\Actions\Conversations;

use App\Actions\Messages\PostSystemMessage;
use App\Events\ConversationStarted;
use App\Events\ConversationUpdated;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\User;
use App\Policies\ConversationPolicy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Adds people to a group conversation.
 *
 * Only groups can gain participants: adding a third person to a direct message
 * would break the uniqueness its partial index guarantees, so
 * {@see ConversationPolicy::addParticipants()} refuses it and the
 * UI starts a new group conversation instead. This action trusts that check.
 *
 * # Newcomers start at the present, not the beginning
 *
 * A new participant's read pointer is parked on the newest message rather than
 * left null. Left null they would open the conversation to a badge counting
 * every message ever sent in it — a conversation they were not part of and are
 * not expected to catch up on.
 */
class AddConversationParticipants
{
    public function __construct(private readonly PostSystemMessage $systemMessage) {}

    /**
     * @param  Collection<int, User>  $users  Verified active members of the workspace.
     * @return Collection<int, User> Those actually added, in the order given.
     */
    public function handle(Conversation $conversation, User $actor, Collection $users): Collection
    {
        $existing = $conversation->participants()
            ->pluck('left_at', 'user_id');

        // Someone already in and active is a no-op rather than an error: two
        // people adding the same colleague at once is ordinary, not a conflict.
        $added = $users->reject(
            fn (User $user): bool => $existing->has($user->id) && $existing->get($user->id) === null,
        )->values();

        if ($added->isEmpty()) {
            return $added;
        }

        $newestMessageId = $conversation->messages()->rootLevel()->max('id');

        DB::transaction(function () use ($conversation, $added, $existing, $newestMessageId): void {
            $now = now();

            foreach ($added as $user) {
                // A returning participant keeps their row — and with it their
                // old read position — rather than gaining a second one, which
                // the unique index would refuse anyway.
                if ($existing->has($user->id)) {
                    $conversation->participants()
                        ->where('user_id', $user->id)
                        ->update(['left_at' => null, 'joined_at' => $now, 'updated_at' => $now]);

                    continue;
                }

                ConversationParticipant::create([
                    'conversation_id' => $conversation->id,
                    'user_id' => $user->id,
                    'last_read_message_id' => $newestMessageId,
                    'last_read_at' => $newestMessageId === null ? null : $now,
                    'joined_at' => $now,
                ]);
            }

            /*
             * The hash describes the current participant set, so it is
             * recomputed here. Only direct conversations are covered by the
             * unique index, so a group's hash is free to change; leaving it
             * stale would make "open a group with these people" find the wrong
             * thread.
             */
            $conversation->forceFill([
                'participants_hash' => Conversation::hashParticipants(
                    $conversation->participants()->active()->pluck('user_id')->all(),
                ),
            ])->save();
        });

        $conversation->unsetRelation('participants')->unsetRelation('users');

        $this->systemMessage->handle(
            $conversation,
            sprintf(
                '%s added %s to the conversation.',
                $actor->name,
                $added->map(fn (User $user): string => $user->name)->join(', ', ' and '),
            ),
        );

        ConversationStarted::dispatch(
            $conversation,
            $added->map(fn (User $user): int => $user->id)->all(),
        );

        ConversationUpdated::dispatch($conversation);

        return $added;
    }
}
