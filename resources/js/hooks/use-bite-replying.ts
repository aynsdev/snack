import { useEcho } from '@laravel/echo-react';
import { useEffect, useRef, useState } from 'react';
import { events } from '@/lib/echo';
/*
 * Configures the transport as a side effect, for the reason use-typing.ts gives:
 * subscribing without it produces no error, just a socket that never connects.
 */
import '@/lib/echo';

export type BiteReplyingEvent = {
    id: number;
    name: string;
    replying: boolean;
    /** Seconds this signal is good for. Zero when the work is over. */
    expires_in: number;
};

/**
 * Whether Bite is currently working on an answer here.
 *
 * # Why this is not part of useTyping
 *
 * They look like one feature and are opposites underneath. Typing is a client
 * whisper the server never sees; this is a server broadcast, because Bite is a
 * queue worker with no browser and nothing to whisper from. Folding it into that
 * hook would have put a socket listener inside a hook whose entire design note is
 * that it never touches the server.
 *
 * Echo hands out one subscription per channel name and reference-counts it, so
 * listening here as well as there costs no second connection.
 *
 * # Why it expires
 *
 * The server sends an explicit "finished", but a worker killed mid-answer never
 * gets to. Every signal therefore carries its own lifetime — see
 * {@see App\Events\BiteReplying} — so a lost ending costs a minute of stale
 * indicator rather than an assistant that is forever about to speak.
 *
 * @returns the bot's name while it is working, otherwise null.
 */
export function useBiteReplying({
    channelName,
    visibility = 'presence',
}: {
    channelName: string;
    /** Threads are private channels; containers are presence channels. */
    visibility?: 'presence' | 'private';
}): string | null {
    const [name, setName] = useState<string | null>(null);

    /*
     * The pending expiry lives in a ref rather than in state: re-arming it must
     * not re-run the subscription effect, and a second "started" — an answer
     * that outlives its own lifetime, two people asking at once — has to replace
     * the timer already in flight instead of racing it.
     */
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

    /*
     * The generics are spelled out because visibility defaults to "private" on
     * the payload overload of `useEcho`, and taking that default would type-error
     * every container — containers are presence channels. The driver in the
     * middle slot is the one this application configures; see @/lib/echo.
     */
    useEcho<BiteReplyingEvent, 'reverb', 'presence' | 'private'>(
        channelName,
        events.biteReplying,
        (payload) => {
            if (timer.current !== null) {
                clearTimeout(timer.current);
                timer.current = null;
            }

            if (!payload.replying) {
                setName(null);

                return;
            }

            setName(payload.name);

            timer.current = setTimeout(
                () => setName(null),
                payload.expires_in * 1000,
            );
        },
        [channelName, visibility],
        visibility,
    );

    // A pending expiry outlives the component otherwise, and fires setState on
    // something that has unmounted.
    useEffect(
        () => () => {
            if (timer.current !== null) {
                clearTimeout(timer.current);
            }
        },
        [],
    );

    return name;
}
