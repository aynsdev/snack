<?php

namespace App\Actions\Huddles;

use App\Events\HuddleActivity;
use App\Events\HuddleChanged;
use App\Models\Channel;
use App\Models\ChannelMember;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Huddle;
use App\Models\User;
use App\Notifications\HuddleStartedNotification;
use App\Services\BotAccount;
use Illuminate\Support\Facades\Notification;

/**
 * Tells everybody who should know that a huddle changed.
 *
 * Extracted from the three actions that cause the changes, because every one of
 * them has to answer the same awkward question — *which* people — and the answer
 * differs by container type. Getting it wrong in one of three places would be
 * invisible in testing: the events fire, they simply reach the wrong audience.
 *
 * Three audiences, deliberately distinct:
 *
 *  - Everyone with the container open hears {@see HuddleChanged} on its presence
 *    channel. That is the huddle bar, and it moves on every join and leave.
 *  - Everyone who is a *member* of the container hears {@see HuddleActivity} on
 *    their own private channel, but only when a huddle starts or ends. That is
 *    the sidebar dot — and, on the mobile app, the incoming-call screen — and it
 *    would be noise on every roster change.
 *  - Everyone in a *conversation* who is away from Snack gets
 *    {@see HuddleStartedNotification} on their phone, once, when the huddle
 *    starts. Never for a channel: see {@see ring()}.
 */
class AnnounceHuddle
{
    /**
     * The roster moved: somebody joined or left, and the huddle is otherwise as
     * it was.
     */
    public function roster(Huddle $huddle): void
    {
        HuddleChanged::dispatch($huddle);
    }

    /**
     * Somebody started a huddle: the sidebar signal, plus the phones.
     *
     * Its own entry point rather than a flag on {@see lifecycle()}, because
     * "started" is the one moment that rings and the caller is the only one who
     * knows it happened — {@see JoinHuddle} learns it from the database winning
     * the insert, and a huddle is started exactly once however many people then
     * join it, double-click, or reconnect. Keying the ring to anything else
     * (the roster growing from zero, say) would ring again every time the call
     * emptied out and somebody walked back in before it was swept.
     */
    public function started(Huddle $huddle, User $starter): void
    {
        $this->lifecycle($huddle, except: $starter);

        $this->ring($huddle, $starter);
    }

    /**
     * The huddle started or ended.
     *
     * @param  User|null  $except  Somebody to leave out of the sidebar fan-out —
     *                             the person who just started it, whose own client already knows.
     *                             Never applied to the presence broadcast, which their other tabs
     *                             legitimately need.
     */
    public function lifecycle(Huddle $huddle, ?User $except = null): void
    {
        $this->roster($huddle);

        $recipients = $this->containerMemberIds($huddle, $except);

        if ($recipients === []) {
            return;
        }

        HuddleActivity::dispatch(
            $huddle->public_id,
            $huddle->isInChannel()
                ? ['kind' => 'channel', 'id' => $huddle->channel_id]
                : ['kind' => 'conversation', 'id' => $huddle->conversation?->public_id],
            $huddle->isLive(),
            $recipients,
            $huddle->isLive() ? $this->starterSummary($huddle) : null,
        );
    }

    /**
     * Who started a live huddle, in the slice of {@see App\Http\Resources\UserResource}
     * an incoming-call screen needs: a name to show and a face to show it with.
     *
     * Read from the huddle's own `started_by` rather than passed in, so
     * {@see lifecycle()} keeps one signature for both ends and the field can only
     * ever name the person the database recorded as winning the insert. Null
     * when the starter's account has since been deleted — the column is
     * nullable for exactly that — which the client already has to handle for
     * an ended huddle anyway.
     *
     * @return array{id: int, name: string, avatar_url: string|null}|null
     */
    private function starterSummary(Huddle $huddle): ?array
    {
        $starter = $huddle->starter;

        return $starter === null ? null : [
            'id' => $starter->id,
            'name' => $starter->name,
            'avatar_url' => $starter->avatarUrl(),
        ];
    }

    /**
     * Push the start of a conversation huddle to the other participants' phones.
     *
     * # Conversations only
     *
     * A DM or a group DM is a handful of people who chose each other, and a
     * call there is aimed at them the way a message there is — which is why it
     * rings. A channel is a room: a huddle in #design is somebody opening the
     * door, not somebody dialling you, and buzzing every member's phone each
     * time would teach a two-hundred-person channel to turn push off. Channel
     * members still see the live dot through {@see HuddleActivity}.
     *
     * # The same filter as a direct message
     *
     * Eligibility is {@see ConversationParticipant::wantsNotificationForEveryMessage()},
     * the rule {@see App\Services\NotificationRecipients} applies to a DM: a
     * muted conversation, or one set below "every message", does not ring, and
     * neither does one the participant has left. Bots are dropped for the
     * reason given there — no phone, no preference, nobody to answer.
     *
     * Whether the phone then actually buzzes — push enabled, not on
     * do-not-disturb or inside quiet hours, not already looking at Snack — is
     * the notification's own {@see HuddleStartedNotification::via()}, unchanged
     * from every other push.
     *
     * Resolved inline rather than on a queue, unlike message fan-out: a
     * conversation's roster is a few rows, and the sends themselves are queued
     * by the notification.
     */
    private function ring(Huddle $huddle, User $starter): void
    {
        $conversation = $huddle->container();

        if (! $conversation instanceof Conversation) {
            return;
        }

        $candidates = ConversationParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', '!=', $starter->id)
            ->get()
            ->filter(fn (ConversationParticipant $participant): bool => $participant->wantsNotificationForEveryMessage())
            ->map(fn (ConversationParticipant $participant): int => (int) $participant->user_id)
            ->values()
            ->all();

        $recipientIds = BotAccount::withoutBots($candidates);

        if ($recipientIds === []) {
            return;
        }

        $workspace = $huddle->workspace;

        Notification::send(
            User::query()->whereIn('id', $recipientIds)->get(),
            new HuddleStartedNotification($workspace, [
                'huddle_id' => $huddle->public_id,
                'conversation_id' => $conversation->public_id,
                'starter' => [
                    'id' => $starter->id,
                    'name' => $starter->name,
                ],
            ]),
        );
    }

    /**
     * Everyone entitled to know a huddle is happening in this container.
     *
     * Channel members rather than everyone who *could* see the channel: a public
     * channel is visible to the whole workspace, and badging a call in a channel
     * somebody has never joined is noise from a room they are not in. Joining
     * the channel is what opts you into hearing about it.
     *
     * Conversation participants exclude those who left, which the `left_at`
     * filter handles — a former participant keeps read access to the history and
     * has no business being told about live calls in it.
     *
     * @return array<int, int>
     */
    private function containerMemberIds(Huddle $huddle, ?User $except): array
    {
        $container = $huddle->container();

        $query = match (true) {
            $container instanceof Channel => ChannelMember::query()
                ->where('channel_id', $container->id),

            $container instanceof Conversation => ConversationParticipant::query()
                ->where('conversation_id', $container->id)
                ->whereNull('left_at'),

            // The container was deleted out from under the huddle; there is
            // nobody left to tell.
            default => null,
        };

        if ($query === null) {
            return [];
        }

        return $query
            ->when($except !== null, fn ($rows) => $rows->where('user_id', '!=', $except->id))
            ->pluck('user_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }
}
