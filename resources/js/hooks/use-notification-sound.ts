import { useCallback, useRef } from 'react';

/** Served from public/, so it is not fingerprinted by Vite. */
const SOUND_URL = '/snack.mp3';

/**
 * The arrival sound for an incoming message.
 *
 * One `Audio` element is kept and rewound rather than constructed per message:
 * a busy channel would otherwise leave a trail of elements the browser has to
 * decode and collect, and each new element restarts buffering.
 *
 * Playback is deliberately failure-tolerant. Browsers reject `play()` until the
 * page has been interacted with, and some block it entirely — neither is
 * something the user needs to hear about, and an unhandled rejection here would
 * surface as an error in an app that is otherwise working perfectly.
 */
export function useNotificationSound(enabled = true) {
    const audio = useRef<HTMLAudioElement | null>(null);

    return useCallback(() => {
        if (!enabled || typeof Audio === 'undefined') {
            return;
        }

        // Never interrupt someone who has told the OS not to animate or
        // disturb; the same signal covers "I do not want ambient feedback".
        if (window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) {
            return;
        }

        audio.current ??= new Audio(SOUND_URL);
        audio.current.volume = 0.35;
        audio.current.currentTime = 0;

        void audio.current.play().catch(() => {
            // Autoplay policy, missing output device, or a muted tab.
        });
    }, [enabled]);
}
