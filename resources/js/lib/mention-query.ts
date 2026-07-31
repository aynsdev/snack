/**
 * Finding the `@handle` the caret is currently inside.
 *
 * Kept pure and apart from the composer because this is the part that is subtly
 * wrong rather than obviously broken: an off-by-one here means the picker opens
 * on the wrong word, or replaces text the author did not mean to replace.
 */

/** Mirrors the handle grammar in lib/message-format.ts and MentionParser. */
const PARTIAL_HANDLE = /(?:^|[^\w@])@([A-Za-z0-9_.-]*)$/;

export type MentionQuery = {
    /** What has been typed after the "@", possibly empty. */
    term: string;
    /** Index of the "@" itself, so the replacement knows what to overwrite. */
    start: number;
};

/**
 * The mention being typed at `caret`, or null if the caret is not in one.
 *
 * Only the text *before* the caret is considered. Someone editing the middle of
 * a finished sentence is not writing a mention, and popping a picker over their
 * work because an "@" happens to sit further along would be an ambush.
 */
export function mentionQueryAt(
    value: string,
    caret: number,
): MentionQuery | null {
    const before = value.slice(0, caret);
    const match = before.match(PARTIAL_HANDLE);

    if (match === null) {
        return null;
    }

    const term = match[1];

    /*
     * A newline between the "@" and the caret means the author moved on. The
     * character class already excludes newlines, so this only guards the
     * degenerate case of an "@" alone at the end of a previous line.
     */
    if (term.includes('\n')) {
        return null;
    }

    return { term, start: caret - term.length - 1 };
}

/**
 * Replace the handle being typed with a chosen one.
 *
 * Returns the new value and where the caret should land — after the inserted
 * handle and the space that follows it, so typing simply continues.
 */
export function applyMention(
    value: string,
    query: MentionQuery,
    username: string,
): { value: string; caret: number } {
    const before = value.slice(0, query.start);
    const after = value.slice(query.start + query.term.length + 1);

    /*
     * A trailing space, so typing simply continues — but only where one belongs.
     * Not before existing whitespace (which would double it), and not before
     * punctuation: "@ada , are you around?" is the tell-tale sign of a composer
     * that inserts blindly.
     */
    const needsSpace = after === '' || /^[A-Za-z0-9_@#]/.test(after);
    const inserted = `@${username}${needsSpace ? ' ' : ''}`;

    return {
        value: before + inserted + after,
        caret: before.length + inserted.length,
    };
}
