/**
 * Query keys, in one place.
 *
 * A cache is only useful if two callers asking for the same thing spell it the
 * same way, and only safe if two callers asking for different things cannot
 * collide. Building keys here rather than inline is what makes both true.
 */

/**
 * One container's message history.
 *
 * Keyed by the broadcast channel rather than by the container's id, because that
 * string already distinguishes a channel from a conversation — `channel.12` and
 * `conversation.01H…` cannot collide, where a bare id could.
 */
export function timelineKey(broadcastChannel: string) {
    return ['timeline', broadcastChannel] as const;
}
