<?php

namespace App\Actions\Messages;

use App\Enums\MentionType;
use App\Enums\PresenceStatus;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Mention;
use App\Models\Message;
use App\Models\User;
use App\Services\MentionParser;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Turns the mentions in a message into rows, and keeps them true after an edit.
 *
 * # Why rows at all
 *
 * The mention badge has to answer "how many unread things are addressed to me"
 * on every page load. Derived from message bodies that would be a full-text
 * search across the workspace; materialized here it is a partial-index scan over
 * this user's unread rows. The cost moves to write time, where it is paid once.
 *
 * # What a mention may reach
 *
 * A handle is a client-supplied identifier like any other, so it is resolved
 * against **the people who can already see the container** rather than against
 * the users table. Mentioning someone who is not in the channel does nothing:
 * they get no row, no badge, and no notification pointing at a message they
 * cannot open. That also stops a mention being used to probe who exists.
 *
 * Broadcast mentions (`@everyone`, `@here`) are gated on
 * {@see App\Enums\WorkspaceRole::canMentionEveryone()}. Someone without the
 * right can type the words — they are just words — but they expand to nobody.
 *
 * # Editing
 *
 * An edit re-syncs rather than appends. Adding a handle in an edit notifies the
 * new person; removing one takes their badge back. Rows that survive the edit
 * keep their `read_at`, so fixing a typo does not mark a message unread again
 * for everyone who had already seen it.
 */
class SyncMentions
{
    public function handle(Message $message, User $author): void
    {
        $container = $message->container();

        if ($container === null || ! $message->type->isAuthoredByUser()) {
            return;
        }

        $parsed = MentionParser::extract($message->body);

        $desired = $this->resolve($message, $container, $author, $parsed);

        $this->persist($message, $desired);
    }

    /**
     * Who this message actually mentions, as `[user_id => MentionType]`.
     *
     * A person named twice — directly and by `@everyone` — gets the direct
     * mention, which is the stronger signal and the one worth showing them.
     *
     * @param  array{usernames: array<int, string>, broadcasts: array<int, MentionType>, channels: array<int, string>}  $parsed
     * @return array<int, MentionType>
     */
    private function resolve(
        Message $message,
        Channel|Conversation $container,
        User $author,
        array $parsed,
    ): array {
        $mentions = [];

        foreach ($parsed['broadcasts'] as $type) {
            if (! $this->mayBroadcast($author, $message)) {
                continue;
            }

            foreach ($this->broadcastAudience($container, $type) as $userId) {
                $mentions[$userId] = $type;
            }
        }

        if ($parsed['usernames'] !== []) {
            $direct = $this->membersByUsername($container, $parsed['usernames']);

            foreach ($direct as $userId) {
                // Overwrites a broadcast: being named is louder than being
                // included, so that is the mention they should see.
                $mentions[$userId] = MentionType::User;
            }
        }

        /*
         * Mentioning yourself is not a notification. It happens constantly by
         * accident — quoting a thread, editing your own message — and a badge
         * for something you just wrote is pure noise.
         */
        unset($mentions[$author->id]);

        return $mentions;
    }

    private function mayBroadcast(User $author, Message $message): bool
    {
        return $author->roleIn($message->workspace)?->canMentionEveryone() === true;
    }

    /**
     * The people a broadcast mention reaches.
     *
     * `@here` is narrowed to whoever is currently online, which is the whole
     * point of it existing alongside `@everyone` — it is the one that respects
     * that most of the room is asleep.
     *
     * @return array<int, int>
     */
    private function broadcastAudience(Channel|Conversation $container, MentionType $type): array
    {
        $members = $this->memberIds($container);

        if ($type === MentionType::Here) {
            return User::query()
                ->whereIn('id', $members)
                // Online only. Including "away" would make @here a synonym for
                // @everyone, which is the distinction it exists to draw.
                ->where('presence_status', PresenceStatus::Online->value)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();
        }

        return $members->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * Everyone who can see the container.
     *
     * @return Collection<int, int>
     */
    private function memberIds(Channel|Conversation $container): Collection
    {
        return $container instanceof Channel
            ? DB::table('channel_members')->where('channel_id', $container->id)->pluck('user_id')
            : $container->participants()->active()->pluck('user_id');
    }

    /**
     * Resolve handles to ids, constrained to people who can see the container.
     *
     * @param  array<int, string>  $usernames
     * @return array<int, int>
     */
    private function membersByUsername(Channel|Conversation $container, array $usernames): array
    {
        return User::query()
            ->whereIn('id', $this->memberIds($container))
            // `username` is citext, so this comparison is already
            // case-insensitive and needs no lower() that would skip the index.
            ->whereIn('username', $usernames)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Write the difference between what exists and what the message now says.
     *
     * A diff rather than delete-and-reinsert: recreating an unchanged row would
     * discard its `read_at` and re-badge a message the reader had already dealt
     * with, every time the author fixed a typo.
     *
     * @param  array<int, MentionType>  $desired
     */
    private function persist(Message $message, array $desired): void
    {
        /** @var Collection<int, Mention> $existing */
        $existing = $message->mentions()->get()->keyBy('mentioned_user_id');

        $obsolete = $existing
            ->reject(fn (Mention $mention, int $userId): bool => isset($desired[$userId])
                && $desired[$userId] === $mention->mention_type)
            ->pluck('id');

        if ($obsolete->isNotEmpty()) {
            Mention::query()->whereIn('id', $obsolete)->delete();
        }

        $rows = [];
        $now = now();

        foreach ($desired as $userId => $type) {
            $unchanged = $existing->has($userId)
                && $existing->get($userId)->mention_type === $type;

            if ($unchanged) {
                continue;
            }

            $rows[] = [
                'workspace_id' => $message->workspace_id,
                'message_id' => $message->id,
                'mentioned_user_id' => $userId,
                'mention_type' => $type->value,
                'read_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            // ON CONFLICT DO NOTHING: the unique index is the authority on one
            // mention per person per type, and a concurrent edit must not turn
            // a duplicate into a 500 on an otherwise fine message.
            Mention::query()->insertOrIgnore($rows);
        }
    }
}
