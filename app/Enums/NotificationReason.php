<?php

namespace App\Enums;

/**
 * Why a notification was raised.
 *
 * This is the notification's `type` column — see {@see App\Notifications\MessageNotification::databaseType()}
 * — rather than a class name, so the inbox can be filtered by reason without
 * the database carrying PHP namespaces that a refactor would invalidate.
 *
 * # Why there is no case for an ordinary channel message
 *
 * A channel set to {@see NotificationLevel::All} alerts the reader who is
 * looking at it and can email them while they are away, but it writes nothing
 * here. An inbox row per member per message is precisely the shape Snack does
 * not store: one busy channel with two hundred members would write two hundred
 * rows a message, and the resulting inbox would be a second, worse copy of the
 * channel itself.
 *
 * Every case below is *directed* — someone named you, wrote to you, or replied
 * in a thread you chose to follow — so the row count is bounded by attention
 * rather than by traffic.
 */
enum NotificationReason: string
{
    /** Someone typed your @handle, or addressed the whole channel. */
    case Mention = 'mention';

    /** A message arrived in a direct or group conversation. */
    case DirectMessage = 'direct_message';

    /** A reply landed in a thread you follow. */
    case ThreadReply = 'thread_reply';

    public function label(): string
    {
        return match ($this) {
            self::Mention => 'Mention',
            self::DirectMessage => 'Direct message',
            self::ThreadReply => 'Thread reply',
        };
    }

    /**
     * How the row reads in the inbox, given who caused it.
     */
    public function headline(string $actorName): string
    {
        return match ($this) {
            self::Mention => "{$actorName} mentioned you",
            self::DirectMessage => "{$actorName} sent you a message",
            self::ThreadReply => "{$actorName} replied in a thread",
        };
    }

    /**
     * Whether this reason survives the recipient having muted the container.
     *
     * Muting says "stop telling me about the traffic here", not "stop telling me
     * when I am addressed by name" — so a direct mention still lands. The other
     * two are traffic and are silenced, which is the whole point of the mute.
     */
    public function outranksMute(): bool
    {
        return $this === self::Mention;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
