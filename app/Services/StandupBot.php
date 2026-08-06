<?php

namespace App\Services;

use App\Actions\Conversations\FindOrCreateConversation;
use App\Actions\Messages\SendMessage;
use App\Enums\ChannelRole;
use App\Enums\MemberStatus;
use App\Enums\WorkspaceRole;
use App\Models\Channel;
use App\Models\ChannelMember;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The account the standup app speaks as, and the two ways it speaks.
 *
 * # Why the bot is an ordinary user row
 *
 * Because everything that delivers a message is built on a sender that exists.
 * A direct message needs a conversation, a conversation needs participants, and
 * a participant is a user; the sidebar renders an author, the timeline groups by
 * one, and the policies ask whether the sender is in the workspace. Modelling
 * the bot as a second kind of sender would have meant a nullable column on
 * `messages` and a branch in every one of those places, all to avoid a single
 * row.
 *
 * What keeps it from being mistaken for a colleague is `users.is_bot`: it is
 * skipped by the seat count, the admin member roster, and notification
 * delivery. See that column's migration for the full list.
 *
 * # One bot per workspace, not one per installation
 *
 * A shared bot would be a user row that is a member of every workspace on the
 * instance, which is precisely the object multi-tenancy exists to make
 * impossible: one leaked conversation binding, and a standup answer written in
 * one company is readable in another. A row per workspace costs nothing and
 * keeps the tenant boundary exactly where every other check expects it.
 *
 * # Why it posts through SendMessage
 *
 * The bot's messages are ordinary messages — they paginate, broadcast, render,
 * and search like anything else, and a member reading one should not be able to
 * tell it took a different path. Authorization is the caller's business here as
 * everywhere: the bot is handed resolved models, never identifiers from a
 * request.
 */
class StandupBot
{
    public function __construct(
        private readonly FindOrCreateConversation $conversations,
        private readonly SendMessage $messages,
    ) {}

    /**
     * The workspace's standup account, created on first use.
     *
     * # Why the email carries the workspace id
     *
     * `users.email` is unique across the installation, so a bot per workspace
     * needs an address per workspace. The id rather than the slug: a workspace
     * can be renamed, and a rename must not orphan the account that authored
     * every standup summary in its history.
     *
     * The address is in the reserved `.invalid` TLD, which by RFC 2606 can never
     * be registered. Mail to it cannot leave the building even if some future
     * code path forgets that bots are not notified.
     */
    public function accountFor(Workspace $workspace): User
    {
        $email = $this->emailFor($workspace);

        $bot = User::query()->where('email', $email)->first();

        if ($bot === null) {
            $bot = $this->createAccount($workspace, $email);
        }

        $this->ensureWorkspaceMembership($workspace, $bot);

        return $bot;
    }

    /**
     * Put the bot in the channel it reports to.
     *
     * A summary posted by a non-member would render with an author the channel's
     * own member list does not contain, which is the sort of thing people file
     * bugs about. It is also what makes the app discoverable: a team wondering
     * where the questions come from finds Standup in the channel's members.
     */
    public function joinChannel(Channel $channel, User $bot): void
    {
        ChannelMember::query()->firstOrCreate(
            ['channel_id' => $channel->id, 'user_id' => $bot->id],
            ['role' => ChannelRole::Member, 'joined_at' => now()],
        );
    }

    /**
     * Send a direct message from the bot to one person.
     *
     * The conversation is found rather than created where one already exists, so
     * every standup the bot has ever asked this person sits in one thread — the
     * same reason a DM with a colleague does.
     *
     * @param  array<string, mixed>  $metadata  Structured payload the client
     *                                          renders as a card. See {@see App\Actions\Standups\AskStandup}.
     */
    public function directMessage(
        Workspace $workspace,
        User $recipient,
        string $body,
        array $metadata = [],
    ): Message {
        $bot = $this->accountFor($workspace);

        $conversation = $this->conversations->handle(
            workspace: $workspace,
            creator: $bot,
            others: new Collection([$recipient]),
        );

        return $this->post($conversation, $bot, $body, $metadata);
    }

    /**
     * Post into a channel as the bot.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function post(
        Channel|Conversation $container,
        User $bot,
        string $body,
        array $metadata = [],
    ): Message {
        $message = $this->messages->handle(
            container: $container,
            sender: $bot,
            body: $body,
        );

        if ($metadata !== []) {
            /*
             * Written after the send rather than through it.
             *
             * SendMessage owns the message pipeline and takes no metadata — the
             * one thing every caller must not be able to do is inject arbitrary
             * structure into a message row. The bot is a caller like any other,
             * so it writes its own payload afterwards, on a row it has just
             * created and therefore already owns.
             *
             * `saveQuietly` because the broadcast has already gone out with the
             * body, which is what the timeline renders; the card fills itself in
             * from the metadata on the next load, and re-broadcasting to add it
             * would deliver the same message twice.
             */
            $message->metadata = [...$message->metadata ?? [], ...$metadata];
            $message->saveQuietly();
        }

        return $message;
    }

    /**
     * The workspace's bot account if it has ever been used, without creating
     * one.
     *
     * For read paths — an admin screen listing standups should not bring an
     * account into being as a side effect of being looked at.
     */
    public function existingAccountFor(Workspace $workspace): ?User
    {
        return User::query()->where('email', $this->emailFor($workspace))->first();
    }

    private function emailFor(Workspace $workspace): string
    {
        return "standup-bot+{$workspace->id}@snack.invalid";
    }

    private function createAccount(Workspace $workspace, string $email): User
    {
        $bot = new User([
            'name' => 'Standup',
            'email' => $email,
            'username' => $this->usernameFor($workspace),
            /*
             * A random password nobody holds, rather than none at all: the
             * column is not nullable, and a bot that could be signed into is a
             * bot that can read every standup in the workspace.
             */
            'password' => Str::random(64),
            'title' => 'Standup app',
            'timezone' => config('app.timezone', 'UTC'),
        ]);

        $bot->is_bot = true;

        // Bots do not verify their address — nothing will ever be sent to it —
        // and an unverified account is refused by the `verified` middleware,
        // which the message pipeline sits behind.
        $bot->forceFill(['email_verified_at' => now()]);

        try {
            /*
             * Savepointed, for the reason set out in
             * {@see App\Actions\Standups\AskStandup::openRun()}: PostgreSQL
             * aborts the whole transaction on a constraint violation, so the
             * recovery read below cannot run inside the transaction the insert
             * just poisoned.
             */
            DB::transaction(fn () => $bot->save());
        } catch (UniqueConstraintViolationException $exception) {
            /*
             * Two schedulers reaching a workspace's first standup at the same
             * moment. The row the winner inserted is the correct answer — the
             * email is the identity — so re-read rather than re-throwing for
             * something that in fact succeeded. The same shape as
             * {@see FindOrCreateConversation}.
             */
            return User::query()->where('email', $email)->first() ?? throw $exception;
        }

        return $bot;
    }

    /**
     * A username that reads as a handle and cannot collide with a person's.
     *
     * `standup` alone would be claimable by whoever registered first; suffixing
     * the workspace id keeps every installation's bots distinct without a
     * uniqueness loop.
     */
    private function usernameFor(Workspace $workspace): string
    {
        return "standup-{$workspace->id}";
    }

    /**
     * The bot's membership of the workspace it serves.
     *
     * A plain Member: the bot has no reason to administer anything, and a role
     * it does not need is one an unrelated bug could use. `firstOrCreate` rather
     * than a check because two runs can race here as easily as anywhere else.
     */
    private function ensureWorkspaceMembership(Workspace $workspace, User $bot): void
    {
        WorkspaceMember::query()->firstOrCreate(
            ['workspace_id' => $workspace->id, 'user_id' => $bot->id],
            [
                'role' => WorkspaceRole::Member,
                'status' => MemberStatus::Active,
                'joined_at' => now(),
                'notification_preferences' => [],
            ],
        );
    }

    /**
     * Whether a user id belongs to a bot, without loading the row.
     *
     * Used by the notification fan-out, which walks recipient ids and would
     * otherwise queue an email per bot per message.
     *
     * @param  array<int, int>  $userIds
     * @return array<int, int>
     */
    public static function withoutBots(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        return DB::table('users')
            ->whereIn('id', $userIds)
            ->where('is_bot', false)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }
}
