<?php

namespace App\Enums;

/**
 * Where in a workspace Bite will answer when it is named.
 *
 * # Why a list of channels rather than a channel property
 *
 * The question an admin arrives with is "where is this thing allowed to speak",
 * which is one answer about one assistant — not a checkbox to remember on every
 * channel anybody ever creates. A per-channel flag would also default new
 * channels to whatever the migration chose, which is the wrong way round: a
 * workspace that has restricted Bite to two channels means a channel made
 * tomorrow is not one of them.
 *
 * Direct messages are never covered by any of these. A DM with Bite is a room
 * with one person in it who deliberately opened it, and confining an assistant
 * to certain channels is about what it overhears in front of other people.
 */
enum BiteChannelMode: string
{
    /** Anywhere it is named. */
    case All = 'all';

    /** Only in the listed channels. */
    case Allow = 'allow';

    /** Anywhere except the listed channels. */
    case Deny = 'deny';

    public function label(): string
    {
        return match ($this) {
            self::All => 'Every channel',
            self::Allow => 'Only these channels',
            self::Deny => 'Every channel except these',
        };
    }

    /**
     * Whether the listed channels mean anything in this mode.
     *
     * The list is kept when the mode is All rather than cleared, so that an
     * admin who switches away and back has not silently lost the selection they
     * spent a minute assembling.
     */
    public function usesList(): bool
    {
        return $this !== self::All;
    }

    /**
     * Whether Bite may answer in the channel with this id.
     *
     * @param  array<int, int>  $channelIds
     */
    public function admits(int $channelId, array $channelIds): bool
    {
        return match ($this) {
            self::All => true,
            self::Allow => in_array($channelId, $channelIds, true),
            self::Deny => ! in_array($channelId, $channelIds, true),
        };
    }
}
