import type { Message, PendingMessage } from '@/types';

/**
 * How long a run of messages from one person stays a single visual block.
 *
 * Five minutes is short enough that a reply an hour later reads as a new
 * thought, and long enough that someone typing three sentences in a row does
 * not get their name and avatar stamped on each one.
 */
const GROUP_WINDOW_MS = 5 * 60 * 1000;

type Groupable = Pick<Message, 'created_at'> & {
    sender: { id: number } | null;
};

/**
 * Whether a message continues the one before it.
 *
 * Kept out of the component because it is the rule that decides what the
 * timeline actually looks like, and it is far easier to pin down here than
 * through rendered output.
 */
export function continuesGroup(
    previous: Groupable | null,
    current: Groupable,
): boolean {
    if (
        previous === null ||
        previous.sender === null ||
        current.sender === null
    ) {
        return false;
    }

    if (previous.sender.id !== current.sender.id) {
        return false;
    }

    if (previous.created_at === null || current.created_at === null) {
        return false;
    }

    const gap =
        new Date(current.created_at).getTime() -
        new Date(previous.created_at).getTime();

    // A day divider is rendered between calendar days, so a group must never
    // straddle one — the header would end up orphaned above the divider.
    if (!isSameDay(previous.created_at, current.created_at)) {
        return false;
    }

    return gap >= 0 && gap < GROUP_WINDOW_MS;
}

/** A pending message continues a group on the same terms as a sent one. */
export function pendingContinuesGroup(
    previous: Groupable | null,
    pending: PendingMessage,
): boolean {
    return continuesGroup(previous, {
        created_at: pending.created_at,
        sender: pending.sender,
    });
}

export function isSameDay(a: string, b: string): boolean {
    const first = new Date(a);
    const second = new Date(b);

    return (
        first.getFullYear() === second.getFullYear() &&
        first.getMonth() === second.getMonth() &&
        first.getDate() === second.getDate()
    );
}

const DAY = new Intl.DateTimeFormat(undefined, {
    weekday: 'long',
    month: 'long',
    day: 'numeric',
});

/**
 * The label on a day divider.
 *
 * "Today" and "Yesterday" rather than a date, because in a chat those are the
 * two days anyone is actually orienting against.
 */
export function dayLabel(iso: string): string {
    const date = new Date(iso);
    const now = new Date();

    if (isSameDay(iso, now.toISOString())) {
        return 'Today';
    }

    const yesterday = new Date(now);
    yesterday.setDate(yesterday.getDate() - 1);

    if (isSameDay(iso, yesterday.toISOString())) {
        return 'Yesterday';
    }

    return DAY.format(date);
}
