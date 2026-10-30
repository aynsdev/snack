import { useCallback, useEffect, useRef, useState } from 'react';
import type {
    KeyboardEvent as ReactKeyboardEvent,
    PointerEvent as ReactPointerEvent,
    RefObject,
} from 'react';

export const PANEL_MIN_WIDTH = 360;

/** How much of the conversation stays visible beside a widened panel. */
const CONTENT_MIN_WIDTH = 320;
const KEYBOARD_STEP = 32;

type Stored = { width: number | null; expanded: boolean };

/**
 * A side panel's width and whether it covers the content beside it,
 * remembered per `storageKey` (which callers scope to the person).
 *
 * `width` stays null until the panel is first resized, so an untouched panel
 * keeps its responsive default.
 */
export function useResizablePanel(storageKey: string): {
    panelRef: RefObject<HTMLElement | null>;
    width: number | null;
    maxWidth: number;
    expanded: boolean;
    toggleExpanded: () => void;
    handleProps: {
        onPointerDown: (event: ReactPointerEvent<HTMLElement>) => void;
        onPointerMove: (event: ReactPointerEvent<HTMLElement>) => void;
        onPointerUp: (event: ReactPointerEvent<HTMLElement>) => void;
        onKeyDown: (event: ReactKeyboardEvent<HTMLElement>) => void;
    };
} {
    const panelRef = useRef<HTMLElement | null>(null);
    const drag = useRef<{ startX: number; startWidth: number } | null>(null);
    const [state, setState] = useState<Stored>(() => recall(storageKey));
    const [maxWidth, setMaxWidth] = useState(PANEL_MIN_WIDTH * 2);

    const measureMax = useCallback(() => {
        const available =
            panelRef.current?.parentElement?.clientWidth ?? window.innerWidth;
        const max = Math.max(PANEL_MIN_WIDTH, available - CONTENT_MIN_WIDTH);

        setMaxWidth(max);

        return max;
    }, []);

    useEffect(() => {
        window.addEventListener('resize', measureMax);

        return () => window.removeEventListener('resize', measureMax);
    }, [measureMax]);

    const commit = useCallback(
        (next: Stored) => {
            setState(next);
            remember(storageKey, next);
        },
        [storageKey],
    );

    const currentWidth = useCallback(
        () =>
            state.width ??
            panelRef.current?.getBoundingClientRect().width ??
            PANEL_MIN_WIDTH,
        [state.width],
    );

    const toggleExpanded = useCallback(
        () => commit({ ...state, expanded: !state.expanded }),
        [commit, state],
    );

    const onPointerDown = useCallback(
        (event: ReactPointerEvent<HTMLElement>) => {
            if (event.button !== 0) {
                return;
            }

            event.preventDefault();
            event.currentTarget.setPointerCapture(event.pointerId);
            measureMax();
            drag.current = {
                startX: event.clientX,
                startWidth: currentWidth(),
            };
        },
        [currentWidth, measureMax],
    );

    const onPointerMove = useCallback(
        (event: ReactPointerEvent<HTMLElement>) => {
            if (drag.current === null) {
                return;
            }

            const { startX, startWidth } = drag.current;
            const width = clamp(startWidth + startX - event.clientX, maxWidth);

            setState((previous) => ({ ...previous, width }));
        },
        [maxWidth],
    );

    const onPointerUp = useCallback(
        (event: ReactPointerEvent<HTMLElement>) => {
            if (drag.current === null) {
                return;
            }

            drag.current = null;
            event.currentTarget.releasePointerCapture(event.pointerId);
            remember(storageKey, state);
        },
        [state, storageKey],
    );

    const onKeyDown = useCallback(
        (event: ReactKeyboardEvent<HTMLElement>) => {
            const max = measureMax();
            const width = currentWidth();
            const next: Record<string, number> = {
                ArrowLeft: width + KEYBOARD_STEP,
                ArrowRight: width - KEYBOARD_STEP,
                Home: PANEL_MIN_WIDTH,
                End: max,
            };

            if (!(event.key in next)) {
                return;
            }

            event.preventDefault();
            commit({ ...state, width: clamp(next[event.key], max) });
        },
        [commit, currentWidth, measureMax, state],
    );

    return {
        panelRef,
        width: state.width,
        maxWidth,
        expanded: state.expanded,
        toggleExpanded,
        handleProps: { onPointerDown, onPointerMove, onPointerUp, onKeyDown },
    };
}

function clamp(width: number, max: number): number {
    return Math.round(Math.min(Math.max(width, PANEL_MIN_WIDTH), max));
}

function keyFor(storageKey: string): string {
    return `snack:panel:${storageKey}`;
}

function recall(storageKey: string): Stored {
    try {
        const raw = localStorage.getItem(keyFor(storageKey));
        const parsed: unknown = raw === null ? null : JSON.parse(raw);

        if (typeof parsed !== 'object' || parsed === null) {
            return { width: null, expanded: false };
        }

        const { width, expanded } = parsed as Record<string, unknown>;

        return {
            width:
                typeof width === 'number' && Number.isFinite(width)
                    ? Math.max(width, PANEL_MIN_WIDTH)
                    : null,
            expanded: expanded === true,
        };
    } catch {
        return { width: null, expanded: false };
    }
}

function remember(storageKey: string, state: Stored): void {
    try {
        localStorage.setItem(keyFor(storageKey), JSON.stringify(state));
    } catch {
        // Storage can be full or blocked; the panel still works for this visit.
    }
}
