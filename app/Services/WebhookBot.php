<?php

namespace App\Services;

use App\Enums\ChannelRole;
use App\Enums\MemberStatus;
use App\Enums\WorkspaceRole;
use App\Models\ChannelMember;
use App\Models\IncomingWebhook;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The account an incoming webhook speaks as.
 *
 * An ordinary `users` row with `is_bot` set, for every reason set out on
 * {@see StandupBot}: the message pipeline, the channel roster, and the timeline
 * are all built on a sender that exists, and a second kind of sender would mean
 * a branch in each of them.
 *
 * # Why one account per hook, not one per workspace
 *
 * This is the one place it differs from the standup bot, which is a single
 * account for the whole workspace. A hook is a *thing people set up*, and the
 * question a reader has when a message appears in #releases is "what posted
 * this" — the answer being "Deploy notifications", not "Webhooks". With a shared
 * account, every integration in the workspace would render as the same author
 * with a different name written beside it, and the channel's member list — the
 * place you look to find out what is in the room — would say nothing at all.
 *
 * It also puts the tenant boundary where every other check expects it. A shared
 * account would be a user row that is a member of every channel any hook points
 * at, which is precisely the object multi-tenancy exists to make impossible.
 *
 * The cost is a user row per hook, which is a row, and a stale account when a
 * hook is deleted. The account is left behind on purpose: it authored the
 * messages, and deleting it would blank the author of every notification the
 * hook ever posted.
 *
 * # Why the account is created lazily
 *
 * On first delivery rather than at creation. An admin who makes a hook, copies
 * the URL, and never wires it up is common — a hook that has never fired should
 * not have put an account in the channel's member list claiming otherwise.
 */
class WebhookBot
{
    /**
     * The hook's account, created and seated on first use.
     *
     * Idempotent and safe to call on every delivery: after the first it is a
     * relation read plus two `firstOrCreate` checks, which is what keeps a hook
     * working after an admin removes its bot from the channel by hand.
     */
    public function accountFor(IncomingWebhook $webhook): User
    {
        $bot = $webhook->bot_user_id !== null
            ? $webhook->bot()->first()
            : null;

        if ($bot === null) {
            $bot = $this->findOrCreateAccount($webhook);

            /*
             * `saveQuietly` on the hook rather than a full save: this is a write
             * on the delivery path, and the row's `updated_at` should track when
             * an admin changed the hook, not when a build script last fired it.
             * `last_delivered_at` is the column that answers that.
             */
            $webhook->bot_user_id = $bot->id;
            $webhook->saveQuietly();
        }

        $this->ensureWorkspaceMembership($webhook->workspace, $bot);
        $this->ensureChannelMembership($webhook, $bot);

        return $bot;
    }

    /**
     * Keep the bot's name in step with the hook's.
     *
     * Renaming a hook renames what it posts as, which is what an admin means by
     * renaming it. Past messages follow, deliberately: the author of a message
     * is an account, and an account that displayed two different names depending
     * on which message you were looking at would be a worse lie than a rename.
     *
     * The per-message `username` override is the other half of this — a delivery
     * that wants to be attributed to something else says so in its payload, and
     * that string is snapshotted into the message. See {@see WebhookPayload}.
     */
    public function syncName(IncomingWebhook $webhook): void
    {
        $bot = $webhook->bot_user_id !== null ? $webhook->bot()->first() : null;

        if ($bot === null || $bot->name === $webhook->displayName()) {
            return;
        }

        $bot->name = $webhook->displayName();
        $bot->save();
    }

    /**
     * The hook's account if it has ever fired, without creating one.
     *
     * For read paths — an admin screen listing hooks must not bring accounts
     * into being as a side effect of being looked at.
     */
    public function existingAccountFor(IncomingWebhook $webhook): ?User
    {
        return $webhook->bot_user_id !== null ? $webhook->bot()->first() : null;
    }

    /**
     * The address that identifies a hook's account.
     *
     * The hook id rather than its name: a hook gets renamed, and a rename must
     * not orphan the account that authored a year of its messages. In the
     * reserved `.invalid` TLD, which by RFC 2606 can never be registered — so
     * mail to it cannot leave the building even if some future path forgets that
     * bots are not notified.
     */
    private function emailFor(IncomingWebhook $webhook): string
    {
        return "webhook+{$webhook->id}@snack.invalid";
    }

    private function findOrCreateAccount(IncomingWebhook $webhook): User
    {
        $email = $this->emailFor($webhook);

        /*
         * Looked up by email before inserting, not only as a race guard: a hook
         * whose `bot_user_id` was cleared by the `nullOnDelete` on a since-purged
         * user, or by a restore from backup, must find its account again rather
         * than collide with it.
         */
        $existing = User::query()->where('email', $email)->first();

        if ($existing !== null) {
            return $existing;
        }

        $bot = new User([
            'name' => $webhook->displayName(),
            'email' => $email,
            'username' => "hook-{$webhook->id}",
            /*
             * A random password nobody holds, rather than none: the column is
             * not nullable, and an account that could be signed into is one that
             * can read every channel the hook was ever added to.
             */
            'password' => Str::random(64),
            'title' => 'Incoming webhook',
            'timezone' => config('app.timezone', 'UTC'),
        ]);

        $bot->is_bot = true;

        // Bots do not verify their address — nothing is ever sent to it — and
        // an unverified account is refused by the `verified` middleware that the
        // message pipeline sits behind.
        $bot->forceFill(['email_verified_at' => now()]);

        try {
            /*
             * Savepointed, for the reason set out on
             * {@see StandupBot::createAccount()}: PostgreSQL aborts the whole
             * transaction on a constraint violation, so the recovery read below
             * cannot run inside the transaction the insert just poisoned.
             */
            DB::transaction(fn () => $bot->save());
        } catch (UniqueConstraintViolationException $exception) {
            // Two deliveries arriving on a hook's first ever fire. The winner's
            // row is the correct answer — the email is the identity.
            return User::query()->where('email', $email)->first() ?? throw $exception;
        }

        return $bot;
    }

    /**
     * The bot's seat in the workspace it posts into.
     *
     * A plain Member: a hook has no reason to administer anything, and a role it
     * does not need is one an unrelated bug could reach for. Bots are excluded
     * from the seat count, so this does not consume a licence.
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
     * Put the bot in the channel it posts to.
     *
     * Two reasons, the same ones {@see StandupBot::joinChannel()} gives. A
     * message from a non-member renders with an author the channel's own member
     * list does not contain. And it is what makes the integration discoverable:
     * somebody wondering where these messages come from finds the hook's name in
     * the channel's members, which is the first step to getting it turned off.
     */
    private function ensureChannelMembership(IncomingWebhook $webhook, User $bot): void
    {
        ChannelMember::query()->firstOrCreate(
            ['channel_id' => $webhook->channel_id, 'user_id' => $bot->id],
            ['role' => ChannelRole::Member, 'joined_at' => now()],
        );
    }
}
