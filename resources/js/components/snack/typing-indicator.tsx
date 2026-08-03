import type { Typist } from '@/hooks/use-typing';
import { cn } from '@/lib/utils';

/**
 * How many people are named before the line collapses to a count. Past two, the
 * names stop being useful and start being a wall of text above the composer.
 */
const NAMED = 2;

/**
 * "Ada is typing…"
 *
 * Occupies a fixed line whether or not anyone is typing. Letting it appear and
 * disappear would push the composer up and down by a line while somebody types
 * at you, which is the kind of movement that makes a text box feel unreliable.
 */
export function TypingIndicator({
    typists,
    className,
}: {
    typists: Typist[];
    className?: string;
}) {
    return (
        <p
            aria-live="polite"
            className={cn(
                'h-4 truncate px-1 text-[0.6875rem] text-muted-foreground',
                className,
            )}
        >
            {typists.length > 0 && (
                <>
                    <TypingDots />
                    {describe(typists)}
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
 */
export function describe(typists: Typist[]): string {
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
