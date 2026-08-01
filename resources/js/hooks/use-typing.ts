import { useChannel } from '@laravel/echo-react';
import { useCallback, useEffect, useRef, useState } from 'react';
/*
 * Configures the transport as a side effect. Imported here rather than left to
 * the entry file: subscribing without it produces no error, just a socket that
 * never connects, so every module that subscribes states the dependency itself.
 */
import '@/lib/echo';

/**
 * How long a typing signal stays valid, in milliseconds.
 *
 * Mirrors `snack.presence.typing_ttl_seconds`. Kept in step by hand because
 * nothing on the server reads it — typing never reaches the server at all.
 */
const TTL = 5000;

/**
 * How often a keystroke may re-announce. Comfortably under the TTL, so a
 * continuous typist never flickers, while a burst of typing still costs one
 * frame every couple of seconds rather than one per character.
 */
const THROTTLE = 2000;

/** How often expired typists are swept out. */
const SWEEP = 1000;

export type Typist = {
    id: number;
    name: string;
};

/**
 * Who is currently typing in a channel, conversation, or thread.
 *
 * # Why this never touches the server
 *
 * Typing is the highest-frequency and least valuable event in a chat app: it is
 * worthless a few seconds after it happens, nobody may audit it, and a busy
 * channel would generate more writes for it than for actual messages. So it
 * rides on client events — whispers — which the WebSocket server forwards
 * between subscribers without the application ever seeing them. No controller,
 * no queue job, no row.
 *
 * Reverb accepts client events from channel members only
 * (`accept_client_events_from`), which is what keeps this from being an open
 * relay: the same subscription authorization that gates reading the channel
 * gates whispering into it, and a non-member has no connection to whisper from.
 *
 * # Why it expires rather than being cleared
 *
 * A "stopped typing" signal is sent on send and on blur, but it cannot be relied
 * on: tabs close, connections drop, and laptops shut mid-word. Every signal
 * therefore carries its own expiry and is swept out on a timer, so the worst
 * failure is an indicator that lingers a few seconds — not one that stays up
 * forever pointing at somebody who left.
 */
export function useTyping({
    channelName,
    visibility = 'presence',
    currentUserId,
    currentUserName,
}: {
    channelName: string;
    /** Threads are private channels; containers are presence channels. */
    visibility?: 'presence' | 'private';
    currentUserId: number | null;
    currentUserName: string | null;
}) {
    const { channel } = useChannel(channelName, visibility);

    const [typists, setTypists] = useState<Typist[]>([]);

    /** Expiry per user id, kept in a ref so the sweep does not re-subscribe. */
    const expiries = useRef(new Map<number, number>());
    const lastAnnounced = useRef(0);

    const forget = useCallback((id: number) => {
        expiries.current.delete(id);
        setTypists((current) => current.filter((typist) => typist.id !== id));
    }, []);

    // -- Receiving -----------------------------------------------------------

    useEffect(() => {
        const subscription = channel();

        if (subscription === null) {
            return;
        }

        const started = (payload: Typist) => {
            /*
             * Your own whisper does not come back to you on the same connection,
             * but it does reach your other tabs — and "you are typing" is not
             * information you need about yourself.
             */
            if (payload.id === currentUserId) {
                return;
            }

            expiries.current.set(payload.id, Date.now() + TTL);

            setTypists((current) =>
                current.some((typist) => typist.id === payload.id)
                    ? current
                    : [...current, { id: payload.id, name: payload.name }],
            );
        };

        const stopped = (payload: { id: number }) => forget(payload.id);

        subscription.listenForWhisper('typing', started);
        subscription.listenForWhisper('typing-stopped', stopped);

        return () => {
            subscription.stopListeningForWhisper('typing', started);
            subscription.stopListeningForWhisper('typing-stopped', stopped);
        };
    }, [channel, currentUserId, forget]);

    // -- Expiry --------------------------------------------------------------

    useEffect(() => {
        const timer = setInterval(() => {
            const now = Date.now();

            const expired = [...expiries.current.entries()]
                .filter(([, at]) => at <= now)
                .map(([id]) => id);

            if (expired.length === 0) {
                return;
            }

            expired.forEach((id) => expiries.current.delete(id));

            setTypists((current) =>
                current.filter((typist) => !expired.includes(typist.id)),
            );
        }, SWEEP);

        return () => clearInterval(timer);
    }, []);

    // -- Sending -------------------------------------------------------------

    /**
     * Announce that the current user is typing. Safe to call per keystroke;
     * throttled internally.
     */
    const startTyping = useCallback(() => {
        if (currentUserId === null || currentUserName === null) {
            return;
        }

        const now = Date.now();

        if (now - lastAnnounced.current < THROTTLE) {
            return;
        }

        lastAnnounced.current = now;

        channel()?.whisper('typing', {
            id: currentUserId,
            name: currentUserName,
        });
    }, [channel, currentUserId, currentUserName]);

    /**
     * Announce that they have stopped — on send, or on leaving the composer.
     *
     * Best-effort: the receiver expires the signal on its own regardless, which
     * is what makes a dropped connection or a closed tab a few seconds of stale
     * indicator rather than a permanent one.
     */
    const stopTyping = useCallback(() => {
        if (currentUserId === null) {
            return;
        }

        // Reset the throttle so the next keystroke announces immediately rather
        // than staying silent for the remainder of the window.
        lastAnnounced.current = 0;

        channel()?.whisper('typing-stopped', { id: currentUserId });
    }, [channel, currentUserId]);

    return { typists, startTyping, stopTyping };
}
