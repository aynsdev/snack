import { useEffect, useRef } from 'react';
import { api } from '@/lib/api';
import { store as presenceStore } from '@/routes/presence';
import type { PresenceStatus } from '@/types';

/**
 * How often an open tab renews its claim. Mirrors
 * `config('snack.presence.heartbeat_seconds')`, which the server's away and
 * offline windows are both expressed as multiples of.
 */
const HEARTBEAT_MS = 60_000;

/**
 * Tells the server this person is still here.
 *
 * # Why a heartbeat rather than a connection
 *
 * The obvious design is to derive presence from the Reverb presence channel —
 * somebody is online exactly while their socket is joined. It is also how this
 * breaks in exactly the way that is hardest to see: when the socket layer is
 * misconfigured or down, presence does not degrade, it inverts. Everyone shows
 * offline, `@here` reaches nobody, and every notification is treated as reaching
 * an absent person and emailed. Presence would then be broken precisely when the
 * app is already broken, and would give no independent signal about it.
 *
 * A plain HTTP beat rides the same path as every other request in the app, so
 * the dot is right whenever the app itself is working.
 *
 * # What it reports
 *
 * Online while the tab is visible, Away the moment it is hidden. The client is
 * the only thing that knows which, and it is the only claim it is allowed to
 * make — Offline is the *absence* of beats, decided by the server's sweep, and
 * do-not-disturb is a deliberate setting a timer must never overwrite.
 *
 * # Failure is silent on purpose
 *
 * A dropped beat is not worth a toast: the next one is a minute away, and the
 * server treats silence as "assume idle, then assume gone" rather than as an
 * error. An unhandled rejection here would surface as a console error once a
 * minute in an app that is otherwise fine.
 */
export function usePresence(enabled: boolean) {
    /*
     * Held in a ref so the effect below can send the current status without
     * listing it as a dependency — a dependency would tear down and rebuild the
     * interval on every visibility change, which is exactly when it matters that
     * the timer keeps running.
     */
    const status = useRef<PresenceStatus>('online');

    useEffect(() => {
        if (!enabled) {
            return;
        }

        const beat = () => {
            void api
                .post(presenceStore.url(), { status: status.current })
                .catch(() => {
                    // See above: silence is already the server's fallback.
                });
        };

        const onVisibilityChange = () => {
            status.current = document.hidden ? 'away' : 'online';

            // Beat immediately rather than waiting out the interval. Coming back
            // to a tab is the moment somebody most wants to be seen as here, and
            // leaving one is the moment a stale green dot starts misleading
            // people.
            beat();
        };

        // Announce arrival straight away, so the dot is right from the first
        // paint rather than a minute into the session.
        beat();

        const timer = window.setInterval(beat, HEARTBEAT_MS);

        document.addEventListener('visibilitychange', onVisibilityChange);

        return () => {
            window.clearInterval(timer);
            document.removeEventListener(
                'visibilitychange',
                onVisibilityChange,
            );
        };
    }, [enabled]);
}
