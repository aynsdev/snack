/**
 * The reaction set.
 *
 * Mirrors `snack.reactions.available` in config/snack.php, which is the
 * server's allowlist. Reactions are stored as shortcodes ("tada"), never as the
 * rendered glyph, so the same reaction survives a change of emoji set — this map
 * is the only place the two are connected.
 *
 * Keep the two in step. A shortcode the server accepts but this map does not
 * know renders as a blank pill; one this map knows but the server rejects is a
 * picker button that always fails.
 */
export const REACTIONS: Record<string, string> = {
    thumbsup: '👍',
    heart: '❤️',
    tada: '🎉',
    rocket: '🚀',
    eyes: '👀',
    joy: '😂',
    thinking: '🤔',
    fire: '🔥',
    clap: '👏',
    white_check_mark: '✅',
    pray: '🙏',
    taco: '🌮',
};

/** The picker's contents, in the order they are offered. */
export const REACTION_SHORTCODES = Object.keys(REACTIONS);

/**
 * The glyph for a shortcode.
 *
 * Falls back to the shortcode itself rather than to an empty string: a reaction
 * this build does not recognize should still be visible and removable, not an
 * invisible pill that counts toward a total nobody can account for.
 */
export function emojiGlyph(shortcode: string): string {
    return REACTIONS[shortcode] ?? `:${shortcode}:`;
}

/**
 * The tooltip for a reaction pill: who reacted.
 *
 * The name list is capped server-side, so anyone beyond it is folded into a
 * count — "Ada, Bo and 3 others" — rather than being silently dropped.
 */
export function reactionTooltip(
    names: string[],
    count: number,
    shortcode: string,
): string {
    const label = `:${shortcode}:`;

    if (names.length === 0) {
        return `${count} ${count === 1 ? 'reaction' : 'reactions'} ${label}`;
    }

    const hidden = count - names.length;

    const people =
        names.length === 1
            ? names[0]
            : `${names.slice(0, -1).join(', ')} and ${names[names.length - 1]}`;

    return hidden > 0
        ? `${names.join(', ')} and ${hidden} ${hidden === 1 ? 'other' : 'others'} reacted with ${label}`
        : `${people} reacted with ${label}`;
}
