import { Loader2, Search, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { UserAvatar } from '@/components/snack/user-avatar';
import { Input } from '@/components/ui/input';
import { api } from '@/lib/api';
import { index as directory } from '@/routes/conversations/directory';
import type { SnackUser } from '@/types';

/** Long enough to stop typing, short enough not to feel laggy. */
const SEARCH_DEBOUNCE_MS = 200;

/**
 * Search-as-you-type multi-select over the workspace's people.
 *
 * # Why the roster is fetched rather than passed in
 *
 * It can be hundreds of people. Shipping that in the page props would put it on
 * every request whether or not anyone opens a picker, and an Inertia visit per
 * keystroke would replace the props of the page underneath. So it reads a small
 * JSON endpoint — one that is itself gated on "may this person start a
 * conversation", because a picker must not become a back door to the member
 * directory for a guest.
 *
 * Shared by the new-message and add-people dialogs so the debouncing, the
 * abort-on-keystroke, and the "leave the old results up on failure" behaviour
 * cannot drift between two copies.
 *
 * Only ever mounted inside open dialog content, so it needs no "is it open?"
 * prop: closing unmounts it, and reopening starts from a blank search.
 */
export function PeoplePicker({
    workspaceSlug,
    selected,
    onToggle,
    excludeIds = [],
    emptyMessage = 'Nobody else is in this workspace yet.',
}: {
    workspaceSlug: string;
    selected: SnackUser[];
    onToggle: (person: SnackUser) => void;
    excludeIds?: number[];
    emptyMessage?: string;
}) {
    const [term, setTerm] = useState('');
    const [people, setPeople] = useState<SnackUser[]>([]);
    const [loading, setLoading] = useState(false);

    useEffect(() => {
        const controller = new AbortController();

        const timer = setTimeout(() => {
            // Set here rather than in the effect body, so a keystroke does not
            // cascade a render — and so the spinner never flashes for a pause
            // shorter than the debounce.
            setLoading(true);

            void api
                .get<{ people: SnackUser[] }>(
                    directory.url(workspaceSlug, { query: { q: term } }),
                    controller.signal,
                )
                .then((payload) => setPeople(payload.people))
                .catch(() => {
                    /*
                     * An aborted request is the ordinary case — the next
                     * keystroke replaced it. A genuinely failed one leaves the
                     * previous results on screen rather than blanking the list
                     * under someone mid-selection.
                     */
                })
                .finally(() => setLoading(false));
        }, SEARCH_DEBOUNCE_MS);

        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [term, workspaceSlug]);

    const candidates = people.filter(
        (person) => !excludeIds.includes(person.id),
    );

    return (
        <>
            {selected.length > 0 && (
                <ul className="flex flex-wrap gap-1.5">
                    {selected.map((person) => (
                        <li key={person.id}>
                            <button
                                type="button"
                                onClick={() => onToggle(person)}
                                className="flex items-center gap-1.5 rounded-full bg-brand-subtle py-1 pr-1.5 pl-1 text-sm font-medium transition-colors hover:bg-accent"
                            >
                                <UserAvatar user={person} size="xs" />
                                {person.name}
                                <X aria-hidden="true" className="size-3.5" />
                                <span className="sr-only">
                                    Remove {person.name}
                                </span>
                            </button>
                        </li>
                    ))}
                </ul>
            )}

            <div className="relative">
                <Search
                    aria-hidden="true"
                    className="absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                />

                <Input
                    autoFocus
                    value={term}
                    onChange={(event) => setTerm(event.target.value)}
                    placeholder="Search by name or username"
                    aria-label="Search people"
                    className="pl-8"
                />

                {loading && (
                    <Loader2
                        aria-hidden="true"
                        className="absolute top-1/2 right-2.5 size-4 -translate-y-1/2 animate-spin text-muted-foreground"
                    />
                )}
            </div>

            <ul className="snack-scroll max-h-64 space-y-0.5 overflow-y-auto">
                {candidates.length === 0 && !loading ? (
                    <li className="px-2 py-6 text-center text-sm text-muted-foreground">
                        {term === ''
                            ? emptyMessage
                            : `No one matching “${term}”.`}
                    </li>
                ) : (
                    candidates.map((person) => {
                        const chosen = selected.some(
                            (candidate) => candidate.id === person.id,
                        );

                        return (
                            <li key={person.id}>
                                <button
                                    type="button"
                                    aria-pressed={chosen}
                                    onClick={() => onToggle(person)}
                                    className="flex w-full items-center gap-2.5 rounded-lg px-2 py-1.5 text-left transition-colors hover:bg-accent aria-pressed:bg-brand-subtle"
                                >
                                    <UserAvatar
                                        user={person}
                                        size="sm"
                                        showPresence
                                    />

                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate text-sm font-medium">
                                            {person.name}
                                        </span>
                                        <span className="block truncate text-xs text-muted-foreground">
                                            @{person.username}
                                            {person.title
                                                ? ` · ${person.title}`
                                                : ''}
                                        </span>
                                    </span>
                                </button>
                            </li>
                        );
                    })
                )}
            </ul>
        </>
    );
}
