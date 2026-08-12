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
use App\Models\MessageAttachment;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * An account an app speaks as, and the ways it speaks.
 *
 * # Why a bot is an ordinary user row
 *
 * Because everything that delivers a message is built on a sender that exists.
 * A direct message needs a conversation, a conversation needs participants, and
 * a participant is a user; the sidebar renders an author, the timeline groups by
 * one, and the policies ask whether the sender is in the workspace. Modelling a
 * bot as a second kind of sender would have meant a nullable column on
 * `messages` and a branch in every one of those places, all to avoid a row.
 *
 * What keeps one from being mistaken for a colleague is `users.is_bot`: it is
 * skipped by the seat count, the admin member roster, and notification
 * delivery. See that column's migration for the full list.
 *
 * # One account per workspace, not one per installation
 *
 * A shared account would be a user row that is a member of every workspace on
 * the instance, which is precisely the object multi-tenancy exists to make
 * impossible: one leaked conversation binding, and something written in one
 * company is readable in another. A row per workspace costs nothing and keeps
 * the tenant boundary exactly where every other check expects it.
 *
 * # Why it posts through SendMessage
 *
 * A bot's messages are ordinary messages — they paginate, broadcast, render,
 * and search like anything else, and a member reading one should not be able to
 * tell it took a different path. Authorization is the caller's business here as
 * everywhere: a bot is handed resolved models, never identifiers from a request.
 *
 * # Why this is a base class
 *
 * Standup and Bite differ in four things — what they are called, what address
 * identifies them, what handle they answer to, and what the profile card says
 * they are. Everything else, including every rule above, was written twice
 * before this existed and would have drifted apart the first time one of them
 * was fixed and the other was not.
 *
 * {@see WebhookBot} deliberately does not extend this. Its account is keyed on a
 * webhook rather than a workspace, its display name is rewritten whenever the
 * hook is renamed, and it stores its own `bot_user_id` back on the hook row —
 * three differences that would turn every method here into a branch.
 */
abstract class BotAccount
{
    public function __construct(
        private readonly FindOrCreateConversation $conversations,
        private readonly SendMessage $messages,
    ) {}

    /**
     * The name shown as the message author.
     */
    abstract protected function displayName(): string;

    /**
     * The line under the name on the profile card.
     */
    abstract protected function title(): string;

    /**
     * The address that identifies this workspace's account.
     *
     * # Why the address carries the workspace id
     *
     * `users.email` is unique across the installation, so an account per
     * workspace needs an address per workspace. The id rather than the slug: a
     * workspace can be renamed, and a rename must not orphan the account that
     * authored every message in its history.
     *
     * Implementations must use the reserved `.invalid` TLD, which by RFC 2606
     * can never be registered. Mail to it cannot leave the building even if some
     * future code path forgets that bots are not notified.
     */
    abstract protected function emailFor(Workspace $workspace): string;

    /**
     * The handles to try for this workspace's account, best first.
     *
     * A list rather than one name because `users.username` is unique across the
     * installation while these accounts are per workspace. An app that wants a
     * short, memorable handle can ask for it and fall back to a qualified one
     * when another workspace got there first.
     *
     * @return non-empty-list<string>
     */
    abstract protected function usernameCandidates(Workspace $workspace): array;

    /**
     * An image file shipped with the application to use as the bot's face, if
     * it has one.
     *
     * A path on the local filesystem rather than a URL or a stored key: this is
     * a design asset that lives in the repository and is deployed with the
     * code, so it is versioned alongside the app rather than being something an
     * installation has to remember to upload.
     *
     * Bots that return null keep the monogram every account falls back to.
     */
    protected function avatarSource(): ?string
    {
        return null;
    }

    /**
     * The workspace's account, created on first use.
     */
    public function accountFor(Workspace $workspace): User
    {
        $email = $this->emailFor($workspace);

        $bot = User::query()->where('email', $email)->first();

        if ($bot === null) {
            $bot = $this->createAccount($workspace, $email);
        }

        $this->ensureWorkspaceMembership($workspace, $bot);

        /*
         * Checked on every resolve rather than only at creation, so that an
         * installation whose bot accounts predate the artwork picks it up on
         * the next thing the bot does. The guard is an in-memory read of a
         * column already loaded, so the common case — a bot that has its
         * picture — costs nothing.
         */
        if ($bot->avatar_path === null) {
            $this->applyAvatar($bot);
        }

        return $bot;
    }

    /**
     * The workspace's account if it has ever been used, without creating one.
     *
     * For read paths — an admin screen listing this app's configuration should
     * not bring an account into being as a side effect of being looked at.
     */
    public function existingAccountFor(Workspace $workspace): ?User
    {
        return User::query()->where('email', $this->emailFor($workspace))->first();
    }

    /**
     * Put the bot in a channel it speaks in.
     *
     * A message posted by a non-member would render with an author the channel's
     * own member list does not contain, which is the sort of thing people file
     * bugs about. It is also what makes the app discoverable: a team wondering
     * where these messages come from finds it in the channel's members, which is
     * the first step to getting it turned off.
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
     * every exchange the bot has ever had with this person sits in one thread —
     * the same reason a DM with a colleague does.
     *
     * @param  array<string, mixed>  $metadata  Structured payload the client
     *                                          renders as a card.
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
     * Post into a channel or conversation as the bot.
     *
     * @param  array<string, mixed>  $metadata
     * @param  Collection<int, MessageAttachment>|null  $attachments
     *                                                                Unclaimed uploads to deliver with this message. Handed to
     *                                                                SendMessage rather than claimed afterwards so that the message
     *                                                                and its files commit together — see the transaction there, and
     *                                                                what happens to an attachment left unclaimed by a failure
     *                                                                between the two.
     */
    public function post(
        Channel|Conversation $container,
        User $bot,
        string $body,
        array $metadata = [],
        ?Message $parent = null,
        ?Collection $attachments = null,
    ): Message {
        $message = $this->messages->handle(
            container: $container,
            sender: $bot,
            body: $body,
            parent: $parent,
            attachments: $attachments,
        );

        if ($metadata !== []) {
            /*
             * Written after the send rather than through it.
             *
             * SendMessage owns the message pipeline and takes no metadata — the
             * one thing every caller must not be able to do is inject arbitrary
             * structure into a message row. A bot is a caller like any other, so
             * it writes its own payload afterwards, on a row it has just created
             * and therefore already owns.
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

    /**
     * Insert the account, taking the first handle nobody else holds.
     */
    private function createAccount(Workspace $workspace, string $email): User
    {
        $candidates = $this->usernameCandidates($workspace);
        $last = array_key_last($candidates);

        foreach ($candidates as $index => $username) {
            $bot = new User([
                'name' => $this->displayName(),
                'email' => $email,
                'username' => $username,
                /*
                 * A random password nobody holds, rather than none at all: the
                 * column is not nullable, and a bot that could be signed into is
                 * a bot that can read every message in the workspace.
                 */
                'password' => Str::random(64),
                'title' => $this->title(),
                'timezone' => config('app.timezone', 'UTC'),
            ]);

            $bot->is_bot = true;

            // Bots do not verify their address — nothing will ever be sent to it
            // — and an unverified account is refused by the `verified`
            // middleware, which the message pipeline sits behind.
            $bot->forceFill(['email_verified_at' => now()]);

            try {
                /*
                 * Savepointed, for the reason set out in
                 * {@see App\Actions\Standups\AskStandup::openRun()}: PostgreSQL
                 * aborts the whole transaction on a constraint violation, so the
                 * recovery read below cannot run inside the transaction the
                 * insert just poisoned.
                 */
                DB::transaction(fn () => $bot->save());

                return $bot;
            } catch (UniqueConstraintViolationException $exception) {
                /*
                 * Two schedulers reaching a workspace's first use at the same
                 * moment. The row the winner inserted is the correct answer —
                 * the email is the identity — so re-read rather than re-throwing
                 * for something that in fact succeeded. The same shape as
                 * {@see FindOrCreateConversation}.
                 */
                $existing = User::query()->where('email', $email)->first();

                if ($existing !== null) {
                    return $existing;
                }

                /*
                 * The email was free, so it was the handle that collided —
                 * another workspace's account already holds it. Fall through to
                 * the next candidate, and rethrow if that was the last one,
                 * because at that point the failure is not one we predicted.
                 */
                if ($index === $last) {
                    throw $exception;
                }
            }
        }

        throw new \LogicException('Bot username candidates must not be empty.');
    }

    /**
     * Give the bot its face.
     *
     * The bundled file is put through {@see ImageProcessor} exactly as an
     * uploaded avatar is, rather than copied to the public disk as-is. That
     * keeps one definition of what an avatar is — square, bounded, re-encoded,
     * stripped of metadata — so the bot's picture cannot be the one image in
     * the product that is a 4 MB original at some other aspect ratio.
     *
     * Written to the avatars disk under the same `avatars/{id}/` prefix people's
     * pictures use, because {@see User::avatarUrl()} builds every URL the same
     * way and a special case here would be a special case in the accessor too.
     *
     * Failures are swallowed deliberately. This runs inside the path that
     * answers a message; a missing file or an unreadable disk should cost the
     * bot its picture, not the reply — and the monogram fallback is already
     * what every account without one renders.
     */
    private function applyAvatar(User $bot): void
    {
        $source = $this->avatarSource();

        if ($source === null || ! is_file($source)) {
            return;
        }

        try {
            $contents = @file_get_contents($source);

            if ($contents === false) {
                return;
            }

            $avatar = app(ImageProcessor::class)->square(
                $contents,
                (int) config('snack.avatars.dimension', 512),
            );

            if ($avatar === null) {
                return;
            }

            $disk = (string) config('snack.avatars.disk', 'public');

            /*
             * Keyed on a hash of the bytes rather than a fresh ULID. A person
             * changing their avatar wants a new URL so caches let go of the old
             * one; a bot's artwork is fixed, and a stable key means re-running
             * this — after a restore, or on a second installation — writes the
             * same object instead of accumulating identical copies.
             */
            $path = sprintf(
                'avatars/%d/%s.%s',
                $bot->id,
                substr(hash('xxh128', $contents), 0, 16),
                $avatar->extension,
            );

            Storage::disk($disk)->put($path, $avatar->contents, 'public');

            $bot->forceFill(['avatar_path' => $path])->save();
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * The bot's seat in the workspace it serves.
     *
     * A plain Member: a bot has no reason to administer anything, and a role it
     * does not need is one an unrelated bug could use. Bots are excluded from
     * the seat count, so this does not consume a licence. `firstOrCreate` rather
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
}
