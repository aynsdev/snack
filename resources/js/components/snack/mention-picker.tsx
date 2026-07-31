import { useEffect, useMemo, useState } from 'react';
import { UserAvatar } from '@/components/snack/user-avatar';
import { api } from '@/lib/api';
import { cn } from '@/lib/utils';
import type { SnackUser } from '@/types';

/**
 * Where the composer looks up the people it can offer.
 *
 * A channel asks the server, because its roster is not in the page. A
 * conversation already has its participants in props, so it filters them in
 * memory and makes no request at all — a round trip per keystroke to re-filter
 * four names would be slower than not having the feature.
 */
export type MentionSource =
    | { kind: 'remote'; url: (term: string) => string }
    | { kind: 'local'; people: SnackUser[] };

/** How many names the picker shows at once. It is a picker, not a directory. */
const LIMIT = 8;

/**
 * The people matching what is being typed after the "@".
 *
 * # Why the composer owns this list and not the picker
 *
 * The composer needs the candidates anyway, to know what Enter and the arrow
 * keys should do. Having the picker own them too meant two components holding
 * the same list and an effect pushing it upward on every keystroke — which is a
 * render cascade, and the reason the local branch was computing derived state
 * inside an effect instead of during render, where it belongs.
 */
export function useMentionCandidates(
    term: string | null,
    source: MentionSource | undefined,
): SnackUser[] {
    /*
     * The local case is a pure function of the props, so it is computed during
     * render. Nothing is being synchronized with anything.
     */
    const local = useMemo(() => {
        if (term === null || source?.kind !== 'local') {
            return null;
        }

        const needle = term.toLowerCase();

        return source.people
            .filter(
                (person) =>
                    needle === '' ||
                    person.name.toLowerCase().includes(needle) ||
                    person.username.toLowerCase().includes(needle),
            )
            .slice(0, LIMIT);
    }, [term, source]);

    const [remote, setRemote] = useState<SnackUser[]>([]);

    /*
     * The effect below is keyed on this string, not on `source`.
     *
     * Both pages build `mentionSource` inline, so the object has a fresh
     * identity on every render. An effect depending on it re-ran each time —
     * and because resolving the lookup sets state, which renders, which
     * rebuilds the object, the picker fetched in a loop for as long as it was
     * open: several requests a second, with nothing erroring to say so.
     * Depending on the resolved URL ties the effect to the only thing that
     * actually changes what to request.
     */
    const url =
        term !== null && source?.kind === 'remote' ? source.url(term) : null;

    useEffect(() => {
        if (url === null) {
            return;
        }

        const controller = new AbortController();

        /*
         * Debounced, because this fires on every keystroke inside a handle. The
         * abort matters more than the delay: without it a slow early response
         * can land after a fast later one and repopulate the list with results
         * for a prefix the author has already typed past.
         */
        const timer = setTimeout(() => {
            api.get<{ people: SnackUser[] }>(url, controller.signal)
                .then((response) => setRemote(response.people.slice(0, LIMIT)))
                .catch(() => {
                    // A failed lookup closes the list rather than freezing a
                    // stale one; the author can still type the handle by hand.
                    if (!controller.signal.aborted) {
                        setRemote([]);
                    }
                });
        }, 120);

        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [url]);

    if (term === null) {
        return [];
    }

    return local ?? remote;
}

/**
 * The @-mention picker.
 *
 * Purely presentational — see {@see useMentionCandidates} for why the list lives
 * in the composer.
 *
 * Offers only people who can actually be mentioned, matching
 * {@see App\Http\Controllers\Channels\MentionableController}. A picker that
 * listed people the server would then ignore is worse than none, because the
 * author walks away believing they notified someone.
 */
export function MentionPicker({
    people,
    activeIndex,
    onChoose,
}: {
    people: SnackUser[];
    activeIndex: number;
    onChoose: (person: SnackUser) => void;
}) {
    if (people.length === 0) {
        return null;
    }

    return (
        <div
            role="listbox"
            aria-label="People you can mention"
            className="absolute bottom-full left-0 z-50 mb-2 max-h-64 w-72 overflow-y-auto rounded-xl border border-border bg-background p-1 shadow-lg"
        >
            {people.map((person, index) => (
                <button
                    key={person.id}
                    type="button"
                    role="option"
                    aria-selected={index === activeIndex}
                    /*
                     * Chosen on mousedown rather than click: a click would first
                     * blur the textarea, and the composer closes the picker on
                     * blur — so the selection would never arrive.
                     */
                    onMouseDown={(event) => {
                        event.preventDefault();
                        onChoose(person);
                    }}
                    className={cn(
                        'flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left transition-colors',
                        index === activeIndex
                            ? 'bg-accent'
                            : 'hover:bg-accent/60',
                    )}
                >
                    <UserAvatar user={person} size="sm" />

                    <span className="min-w-0 flex-1 truncate text-sm font-medium">
                        {person.name}
                    </span>

                    <span className="shrink-0 text-xs text-muted-foreground">
                        @{person.username}
                    </span>
                </button>
            ))}
        </div>
    );
}
