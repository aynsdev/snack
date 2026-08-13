<?php

namespace App\Services;

use App\Models\BiteSetting;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;

/**
 * Whether a message was addressed to Bite, and the account to answer as.
 *
 * # Why this does not read the mentions table
 *
 * `@bite` looks like a mention and mostly behaves like one, but resolving it
 * through {@see App\Actions\Messages\SyncMentions} would tie Bite's name to
 * `users.username`, which is unique across the whole installation. Only one
 * workspace on a multi-tenant instance can hold the bare handle; the rest get
 * `bite-{id}`, and their members would be typing a name that summons nothing.
 *
 * So the word is matched here, against the same parsed handles the mention
 * pipeline uses — {@see MentionParser} strips code spans and fenced blocks
 * first, so `@bite` inside a snippet summons nobody, exactly as it notifies
 * nobody.
 *
 * # Three switches, not one
 *
 * A summon has to get past the installation's own `snack.bite.enabled`, the
 * workspace's {@see App\Models\BiteSetting}, and — in a channel — that
 * workspace's channel guard. They answer to different people and none of them
 * overrides another: an operator decides whether this deployment talks to an AI
 * provider at all, and a workspace's admins decide whether it has an assistant
 * and where it may speak.
 *
 * Every refusal here is silent. An assistant that announces "I am not allowed to
 * answer in this channel" is an assistant that is still posting in that channel,
 * which is exactly what the admin turned off.
 *
 * # Loop prevention
 *
 * A bot never summons Bite. Without that, Bite answering in a channel that a
 * webhook also posts into is one badly-worded reply away from two bots talking
 * to each other until the queue falls over — and Bite's own answers mention
 * people, so it could summon itself.
 */
class BiteSummons
{
    public function __construct(private readonly BiteBot $bot) {}

    /**
     * The account to answer as, or null if this message was not for Bite.
     *
     * Returns the resolved bot rather than a boolean because deciding is most
     * of the work of finding it: the caller would otherwise immediately look up
     * the same account again.
     */
    public function summonedBy(Message $message): ?User
    {
        if (! $this->available()) {
            return null;
        }

        /*
         * Cheap rejections first, in the order that discards the most messages
         * for the least work. This runs on every message the application sends,
         * so the common case — an ordinary message in an ordinary channel — has
         * to cost a config read and an array lookup, not a query.
         */
        if (! $message->type->isAuthoredByUser()) {
            return null;
        }

        $sender = $message->sender;

        if ($sender === null || $sender->isBot()) {
            return null;
        }

        $container = $message->container();

        if ($container === null) {
            return null;
        }

        $settings = BiteSetting::forWorkspace($message->workspace);

        /*
         * The workspace's own switch and its audience rule, checked before the
         * container branches because they apply to both. A workspace that has
         * turned Bite off has turned it off in DMs too — the switch is about
         * whether this workspace has an assistant, not about where.
         */
        if (! $settings->is_enabled) {
            return null;
        }

        if (! $settings->audience->admits($sender->roleIn($message->workspace))) {
            return null;
        }

        return $container instanceof Channel
            ? $this->summonedInChannel($message, $container, $settings)
            : $this->summonedInConversation($container);
    }

    /**
     * Bite answers in a channel only when named.
     *
     * A channel has an audience, and an assistant that replied to everything
     * said in one would be unusable within a day. Being named is the whole
     * consent mechanism.
     */
    private function summonedInChannel(Message $message, Channel $channel, BiteSetting $settings): ?User
    {
        $handles = MentionParser::extract($message->body)['usernames'];

        if (! $this->namesBite($handles, $channel->workspace_id)) {
            return null;
        }

        /*
         * The channel guard, checked after the name and before the account is
         * resolved. After the name because a workspace that has confined Bite to
         * two channels still sends thousands of messages that do not mention it,
         * and this is the cheaper of the two rejections to reach last. Before
         * the account because `accountFor` creates one, and a summon in a
         * forbidden channel must leave nothing behind at all.
         *
         * Silence rather than a refusal message: an assistant that answers "I am
         * not allowed to speak here" every time somebody types its name in the
         * wrong channel is one that is still speaking in that channel.
         */
        if (! $settings->admitsChannel($channel->id)) {
            return null;
        }

        $bot = $this->bot->accountFor($channel->workspace);

        /*
         * Joined on first summon rather than in advance. It is what makes the
         * author render with a member of the channel, and it is how a team
         * wondering what Bite is finds it in the member list — which is the
         * first step to getting it removed.
         */
        $this->bot->joinChannel($channel, $bot);

        return $bot;
    }

    /**
     * In a direct message, being there is being addressed.
     *
     * Nobody opens a DM with an assistant to talk past it, so requiring `@bite`
     * in a two-person conversation would only be a way to get no answer. The
     * membership check is what keeps this from firing on every DM in the
     * workspace: Bite is a participant only in conversations somebody started
     * with it.
     */
    private function summonedInConversation(Conversation $conversation): ?User
    {
        $bot = $this->bot->existingAccountFor($conversation->workspace);

        if ($bot === null) {
            return null;
        }

        return $conversation->participants()
            ->where('user_id', $bot->id)
            ->whereNull('left_at')
            ->exists()
            ? $bot
            : null;
    }

    /**
     * Whether any of the handles in a message is Bite's.
     *
     * Both spellings, because which one this workspace's account actually holds
     * depends on whether another workspace claimed the bare handle first, and
     * the person typing has no way to know that. See
     * {@see BiteBot::usernameCandidates()}.
     *
     * @param  array<int, string>  $handles  Already lowercased by the parser.
     */
    private function namesBite(array $handles, int $workspaceId): bool
    {
        $handle = strtolower((string) config('snack.bite.handle', 'bite'));

        return in_array($handle, $handles, true)
            || in_array("{$handle}-{$workspaceId}", $handles, true);
    }

    /**
     * Whether this installation has Bite switched on and configured.
     *
     * The key is checked as well as the flag because the failure without one is
     * a queued job that throws on every message anybody addresses — turning a
     * missing environment variable into a failed-jobs table rather than into
     * nothing happening.
     */
    public function available(): bool
    {
        if (config('snack.bite.enabled') !== true) {
            return false;
        }

        $provider = (string) config('ai.default', 'mistral');

        return ! in_array(config("ai.providers.{$provider}.key"), [null, '', false], true);
    }
}
