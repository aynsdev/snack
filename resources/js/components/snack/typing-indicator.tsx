import type { Typist } from '@/hooks/use-typing';
import { cn } from '@/lib/utils';

/**
 * How many people are named before the line collapses to a count. Past two, the
 * names stop being useful and start being a wall of text above the composer.
 */
const NAMED = 2;

/**
 * "Ada is typing…", and "Bite is replying…"
 *
 * Occupies a fixed line whether or not anyone is typing. Letting it appear and
 * disappear would push the composer up and down by a line while somebody types
 * at you, which is the kind of movement that makes a text box feel unreliable.
 *
 * The assistant shares the line rather than getting one of its own, for that
 * same reason: a second line that exists only sometimes moves the composer, and
 * the two states are the same sentence to the reader — somebody is about to say
 * something.
 */
export function TypingIndicator({
    typists,
    replying = null,
    className,
}: {
    typists: Typist[];
    /** The assistant's name while it is working on an answer, else null. */
    replying?: string | null;
    className?: string;
}) {
    const sentence = describe(typists, replying);

    return (
        <p
            aria-live="polite"
            className={cn(
                'h-4 truncate px-1 text-[0.6875rem] text-muted-foreground',
                className,
            )}
        >
            {sentence !== '' && (
                <>
                    <TypingDots />
                    {sentence}
                </>
            )}
        </p>
    );
}

/**
 * The animated ellipsis.
 *
 * `aria-hidden`, because the sentence beside it already says somebody is typing
 * and the surrounding region is `aria-live` — announcing three decorative dots
 * on a loop would make a screen reader unusable while anybody is composing.
 *
 * Rendered only while somebody is actually typing, so the loop is not running
 * against an empty room; it unmounts with the sentence rather than idling.
 */
function TypingDots() {
    return (
        <span
            aria-hidden="true"
            className="mr-1.5 inline-flex items-baseline gap-0.5 align-baseline"
        >
            {[0, 1, 2].map((dot) => (
                <span
                    key={dot}
                    className="snack-typing-dot size-1 rounded-full bg-current"
                />
            ))}
        </span>
    );
}

/**
 * The sentence itself.
 *
 * Exported for testing: it is small, but it is the sort of string that reads
 * fine in the one case anyone checks and ungrammatically in the others.
 *
 * The assistant is a separate clause rather than another name in the list. It is
 * not typing — it is thinking, then posting all at once — and "Ada and Bite are
 * typing…" would describe a colleague's keyboard and a queue worker as the same
 * activity.
 */
export function describe(
    typists: Typist[],
    replying: string | null = null,
): string {
    const clauses = [
        typing(typists),
        replying === null ? '' : `${replying} is replying…`,
    ];

    return clauses.filter((clause) => clause !== '').join(' · ');
}

/**
 * The people half of the sentence.
 */
function typing(typists: Typist[]): string {
    const names = typists.map((typist) => typist.name);

    if (names.length === 0) {
        return '';
    }

    if (names.length === 1) {
        return `${names[0]} is typing…`;
    }

    if (names.length <= NAMED) {
        return `${names.join(' and ')} are typing…`;
    }

    const shown = names.slice(0, NAMED).join(', ');
    const rest = names.length - NAMED;

    return `${shown} and ${rest} ${rest === 1 ? 'other' : 'others'} are typing…`;
}
