import { useEchoPresence } from '@laravel/echo-react';
import { useEffect, useRef, useState } from 'react';
import { events } from '@/lib/echo';
import type { Huddle, HuddleChangedEvent } from '@/types';

/**
 * The huddle currently running in one channel or conversation.
 *
 * This is what the huddle bar renders, and it is deliberately separate from
 * {@see useHuddle}: that one tracks the single call *this browser* is in,
 * anywhere in the workspace, while this tracks what is happening in the
 * container on screen. They are usually different — most huddles you can see are
 * ones you are not in — and conflating them is what would make the bar disappear
 * from a channel the moment you joined a call somewhere else.
 *
 * # Why the whole huddle is replaced on every frame
 *
 * `huddle.changed` carries full state rather than a delta, so applying one is
 * an assignment. See App\Events\HuddleChanged for why: dropped or reordered
 * frames are ordinary on a WebSocket, and a roster rebuilt from deltas would
 * strand somebody in a call they left with nothing to correct it.
 */
export function useContainerHuddle({
    broadcastChannel,
    containerKey,
    initial,
}: {
    /** The container's presence channel, from `channels.channel()` / `channels.conversation()`. */
    broadcastChannel: string;
    /**
     * Identifies the container the `initial` prop describes. Navigating between
     * two channels re-renders this hook with a different server answer, and
     * without something to compare it against the first channel's huddle would
     * stay on screen — `useState` keeps its first argument forever.
     */
    containerKey: string;
    /** What the page was rendered with, so the bar is right on first paint. */
    initial: Huddle | null;
}) {
    const [huddle, setHuddle] = useState<Huddle | null>(initial);

    const renderedFor = useRef(containerKey);

    /*
     * Re-seed from the server whenever the page moves to a different container.
     *
     * Keyed on `containerKey` rather than on `initial` itself: Inertia hands
     * over a freshly parsed object on every visit, so depending on the prop
     * would reset the state on renders where nothing actually changed — undoing
     * any broadcast that had arrived in between.
     */
    useEffect(() => {
        if (renderedFor.current !== containerKey) {
            renderedFor.current = containerKey;
            setHuddle(initial);
        }
    }, [containerKey, initial]);

    useEchoPresence<HuddleChangedEvent>(
        broadcastChannel,
        events.huddleChanged,
        (payload) => {
            /*
             * A finished huddle is dropped rather than kept with `is_live`
             * false. The bar has nothing to say about a call that is over, and
             * holding the row would make every consumer check the flag.
             */
            setHuddle(
                payload.huddle !== null && payload.huddle.is_live
                    ? payload.huddle
                    : null,
            );
        },
        [broadcastChannel],
    );

    return huddle;
}
