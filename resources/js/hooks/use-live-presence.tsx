import { useEchoPresence } from '@laravel/echo-react';
import type { ReactNode } from 'react';
import {
    createContext,
    useCallback,
    useContext,
    useMemo,
    useState,
} from 'react';
import { channels, events } from '@/lib/echo';
import type { PresenceChangedEvent, PresenceStatus } from '@/types';

/**
 * Statuses that have changed since the page was rendered, by user id.
 *
 * Deliberately an *overlay* rather than a complete picture. Every payload the
 * server sends already carries `presence_status` on the people it names, and
 * that is the right answer at render time; this only records the changes that
 * have arrived since. A store that tried to hold everyone would need seeding
 * from every payload in the app and would be wrong for anybody it had not seen.
 */
const LivePresenceContext = createContext<ReadonlyMap<number, PresenceStatus>>(
    new Map(),
);

/**
 * Keeps presence dots current without a reload.
 *
 * # Why this is not a prop refresh
 *
 * The sibling signal hooks answer an arrival by reloading the `snack` prop —
 * correct there, because the sidebar's ordering and unread counts are things
 * only the server can compute. Presence is not: the broadcast carries the whole
 * new value, and the client can apply it directly. Reloading a prop once per
 * person per status change would put a request on every colleague's coffee
 * break.
 *
 * # Why it listens on the workspace channel
 *
 * That channel's membership is already exactly "people entitled to see each
 * other" — see routes/channels.php — so one broadcast per change reaches
 * precisely the right audience without a fan-out per recipient.
 */
export function LivePresenceProvider({
    workspaceId,
    children,
}: {
    workspaceId: number;
    children: ReactNode;
}) {
    const [statuses, setStatuses] = useState<
        ReadonlyMap<number, PresenceStatus>
    >(new Map());

    const apply = useCallback((payload: PresenceChangedEvent) => {
        setStatuses((current) => {
            if (current.get(payload.user_id) === payload.presence_status) {
                // Nothing moved. Returning the same map keeps every consumer
                // from re-rendering on a repeat of what they already show.
                return current;
            }

            const next = new Map(current);
            next.set(payload.user_id, payload.presence_status);

            return next;
        });
    }, []);

    useEchoPresence<PresenceChangedEvent>(
        channels.workspace(workspaceId),
        events.presenceChanged,
        apply,
        [workspaceId, apply],
    );

    return (
        <LivePresenceContext.Provider value={statuses}>
            {children}
        </LivePresenceContext.Provider>
    );
}

/**
 * The current status for one person.
 *
 * `served` is what the server rendered — used until a broadcast says otherwise,
 * which means a dot is right on first paint rather than blank until somebody
 * happens to change status.
 */
export function useLivePresence(
    userId: number | null | undefined,
    served: PresenceStatus,
): PresenceStatus {
    const statuses = useContext(LivePresenceContext);

    return useMemo(() => {
        if (userId === null || userId === undefined) {
            return served;
        }

        return statuses.get(userId) ?? served;
    }, [statuses, userId, served]);
}
