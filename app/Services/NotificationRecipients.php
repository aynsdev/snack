<?php

namespace App\Services;

use App\Enums\NotificationReason;
use App\Models\Channel;
use App\Models\ChannelMember;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\ThreadSubscription;
use Illuminate\Support\Collection;

/**
 * Who a message should notify, and why.
 *
 * Resolves to at most one reason per person — the strongest one — so nobody is
 * told the same thing twice under two headings. The order below is the order of
 * how directly the message is aimed at them:
 *
 *  1. **Mention.** They were named. Survives a muted container.
 *  2. **Thread reply.** They chose to follow this particular thread.
 *  3. **Direct message.** They are in the conversation it landed in.
 *
 * # What is deliberately absent
 *
 * An ordinary channel message notifies nobody here, however many members have
 * the channel set to {@see App\Enums\NotificationLevel::All}. That level drives
 * the live alert the reader gets while the app is open, and the email that goes
 * out while they are away, but it writes no inbox row — see
 * {@see NotificationReason} for why one row per member per message is the one
 * shape this application does not store.
 *
 * # Membership is the authority
 *
 * Every candidate is filtered through their own membership row, which is also
 * what proves they can see the container at all. A thread subscription whose
 * member has since left the channel resolves to nothing, so a notification can
 * never point at a message its recipient would be 404'd from.
 */
class NotificationRecipients
{
    /**
     * @return array<int, NotificationReason> Keyed by user id.
     */
    public function for(Message $message): array
    {
        $container = $message->container();

        if ($container === null || ! $message->type->isAuthoredByUser()) {
            return [];
        }

        $memberships = $this->memberships($container);

        $recipients = [];

        /*
         * Assigned weakest first so the stronger reasons below overwrite: a
         * reply that also names you is a mention, and telling you both would be
         * two rows for one event.
         */
        foreach ($this->conversationAudience($message, $container) as $userId) {
            $recipients[$userId] = NotificationReason::DirectMessage;
        }

        foreach ($this->threadAudience($message) as $userId) {
            $recipients[$userId] = NotificationReason::ThreadReply;
        }

        foreach ($this->mentionAudience($message) as $userId) {
            $recipients[$userId] = NotificationReason::Mention;
        }

        // The sender is never notified about their own message, whichever way
        // they managed to address themselves.
        unset($recipients[(int) $message->sender_id]);

        /*
         * Nor is a bot. A bot such as the standup app is a participant in a conversation with
         * everybody it asks, so every answered standup would otherwise write it
         * an inbox row and — once the idle window elapsed — queue an email to an
         * address that exists only because the column is not nullable.
         *
         * Removed here rather than in `accepts()` because it is not a
         * preference: a bot has no inbox to read and no preference to express.
         */
        foreach (array_diff(array_keys($recipients), BotAccount::withoutBots(array_keys($recipients))) as $botId) {
            unset($recipients[$botId]);
        }

        return array_filter(
            $recipients,
            fn (NotificationReason $reason, int $userId): bool => $this->accepts(
                $memberships->get($userId),
                $reason,
            ),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * Everyone the message names.
     *
     * Read from the materialized `mentions` rows rather than re-parsed from the
     * body: {@see App\Actions\Messages\SyncMentions} has already resolved every
     * handle against the people who can see the container and dropped the ones
     * that reach nobody, so re-deriving them here would be a second, subtly
     * different answer to a question already settled.
     *
     * @return array<int, int>
     */
    private function mentionAudience(Message $message): array
    {
        return $message->mentions()
            ->pluck('mentioned_user_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Everyone following the thread this reply landed in.
     *
     * @return array<int, int>
     */
    private function threadAudience(Message $message): array
    {
        $root = $message->parent_message_id;

        if ($root === null) {
            return [];
        }

        return ThreadSubscription::query()
            ->where('root_message_id', $root)
            ->pluck('user_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Everyone in the conversation a root-level message landed in.
     *
     * Replies are excluded: a reply is thread activity, and treating it as
     * conversation activity would notify participants who never opened the
     * thread about a message the DM timeline does not even show them.
     *
     * @return array<int, int>
     */
    private function conversationAudience(Message $message, Channel|Conversation $container): array
    {
        if (! $container instanceof Conversation || $message->parent_message_id !== null) {
            return [];
        }

        return $container->participants()
            ->active()
            ->pluck('user_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Every membership of the container, keyed by user id.
     *
     * One query for the whole audience rather than one per candidate: a busy
     * `@everyone` in a large channel would otherwise be a lookup per member.
     *
     * @return Collection<int, ChannelMember|ConversationParticipant>
     */
    private function memberships(Channel|Conversation $container): Collection
    {
        $rows = $container instanceof Channel
            ? ChannelMember::query()->where('channel_id', $container->id)->get()->all()
            : ConversationParticipant::query()->where('conversation_id', $container->id)->get()->all();

        return (new Collection($rows))
            ->keyBy(fn (ChannelMember|ConversationParticipant $row): int => (int) $row->user_id);
    }

    /**
     * Whether this person's own settings let this reason through.
     *
     * A missing membership is a no: they cannot open the message, so a
     * notification pointing at it would be a dead end.
     */
    private function accepts(
        ChannelMember|ConversationParticipant|null $membership,
        NotificationReason $reason,
    ): bool {
        if ($membership === null) {
            return false;
        }

        return match ($reason) {
            NotificationReason::Mention => $membership->wantsNotificationForMention(),
            NotificationReason::ThreadReply => $membership->wantsNotificationForThreadReply(),
            NotificationReason::DirectMessage => $membership->wantsNotificationForEveryMessage(),
        };
    }
}
