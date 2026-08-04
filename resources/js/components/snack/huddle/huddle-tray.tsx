import { ConnectionState } from 'livekit-client';
import {
    ChevronDown,
    ChevronUp,
    Grid2x2,
    Maximize2,
    Mic,
    MicOff,
    Minimize,
    Minimize2,
    MonitorUp,
    PhoneOff,
    Video,
    VideoOff,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { HuddleTile } from '@/components/snack/huddle/huddle-tile';
import { Spinner } from '@/components/ui/spinner';
import type { ActiveHuddle } from '@/hooks/use-huddle';
import { useHuddle } from '@/hooks/use-huddle';
import { cn } from '@/lib/utils';

/**
 * The call you are in, floating above whatever page you are on.
 *
 * Mounted in {@see WorkspaceLayout} beside the provider, so it — like the
 * connection itself — survives navigating between channels. Walking from
 * #design to a DM mid-sentence is ordinary, and the controls have to come along.
 *
 * Collapsible, because a grid of faces is worth having while people are on
 * camera and is a waste of the screen during an audio-only call. Collapsed it
 * keeps the one thing that must never be more than a click away: the controls.
 *
 * Expandable for the opposite case — see {@see HuddleStage}. A 72-pixel-wide
 * corner panel is right for an audio call you are half-listening to and useless
 * the moment somebody shares their screen.
 */
export function HuddleTray() {
    const { active, error, dismissError } = useHuddle();

    const [expanded, setExpanded] = useState(true);

    /*
     * The stage, and who it opened onto. `null` is closed.
     *
     * Held as one value rather than as a boolean plus an identity so the two
     * cannot disagree, and so {@see HuddleStage} — which mounts fresh each time
     * this becomes non-null — can safely seed its own focus state from it. That
     * is the one case where `useState(prop)` is correct: the prop is read once,
     * on a mount that only happens when the stage is opened.
     */
    const [stage, setStage] = useState<{ focus: string | null } | null>(null);

    // Stable, so the stage's Escape listener re-subscribes only when the
    // spotlight changes rather than on every re-snapshot of the roster.
    const closeStage = useCallback(() => setStage(null), []);

    /*
     * An error with no call behind it is a join that never landed — most often
     * the insecure-context refusal, which is worth explaining rather than
     * swallowing. Rendered here because this component is already mounted
     * workspace-wide, so it does not matter which page the attempt came from.
     */
    if (active === null) {
        return error === null ? null : (
            <div className="fixed right-4 bottom-4 z-50 flex max-w-sm items-start gap-3 rounded-xl border border-border bg-card p-3 shadow-lg">
                <p className="min-w-0 flex-1 text-xs text-pretty text-muted-foreground">
                    {error}
                </p>

                <button
                    type="button"
                    onClick={dismissError}
                    className="shrink-0 text-xs font-medium text-brand hover:underline"
                >
                    Dismiss
                </button>
            </div>
        );
    }

    if (stage !== null) {
        return (
            <HuddleStage
                active={active}
                initialFocus={stage.focus}
                onClose={closeStage}
            />
        );
    }

    const connecting = active.state !== ConnectionState.Connected;

    return (
        <div className="fixed right-4 bottom-4 z-50 w-72 overflow-hidden rounded-xl border border-border bg-card shadow-lg">
            <div className="flex items-center">
                <button
                    type="button"
                    onClick={() => setExpanded((open) => !open)}
                    aria-expanded={expanded}
                    className="flex min-w-0 flex-1 items-center gap-2 px-3 py-2 text-left transition-colors hover:bg-accent"
                >
                    {connecting ? (
                        <Spinner className="size-3.5 shrink-0 text-brand" />
                    ) : (
                        <span
                            aria-hidden="true"
                            className="size-2 shrink-0 rounded-full bg-brand"
                        />
                    )}

                    <span className="min-w-0 flex-1 truncate text-xs font-semibold">
                        {connecting
                            ? 'Connecting…'
                            : `Huddle in ${active.label}`}
                    </span>

                    <span className="shrink-0 text-muted-foreground">
                        {expanded ? (
                            <ChevronDown
                                aria-hidden="true"
                                className="size-3.5"
                            />
                        ) : (
                            <ChevronUp
                                aria-hidden="true"
                                className="size-3.5"
                            />
                        )}
                    </span>
                </button>

                <button
                    type="button"
                    onClick={() => setStage({ focus: null })}
                    title="Expand huddle"
                    className="shrink-0 px-2.5 py-2 text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                >
                    <Maximize2 aria-hidden="true" className="size-3.5" />
                    <span className="sr-only">Expand huddle</span>
                </button>
            </div>

            {expanded && active.participants.length > 0 && (
                <div
                    className={cn(
                        'grid max-h-64 gap-1.5 overflow-y-auto border-t border-border p-2',
                        // One across while it is just you — a lone tile stranded
                        // in half the panel reads as something having failed.
                        active.participants.length === 1
                            ? 'grid-cols-1'
                            : 'grid-cols-2',
                    )}
                >
                    {active.participants.map((participant) => (
                        <HuddleTile
                            key={participant.identity}
                            participant={participant}
                            // Clicking a face in the corner panel goes straight
                            // to that person on the stage. Tapping a
                            // thumbnail the size of a stamp to see it bigger is
                            // the obvious gesture, and doing nothing would be a
                            // dead end.
                            onSelect={() =>
                                setStage({ focus: participant.identity })
                            }
                        />
                    ))}
                </div>
            )}

            <div className="flex items-center justify-center gap-1 border-t border-border p-2">
                <HuddleControls active={active} />
            </div>
        </div>
    );
}

/**
 * The element the document is fullscreen on, or null.
 *
 * Normalized because `document.fullscreenElement` is **undefined** rather than
 * null wherever the Fullscreen API is absent — jsdom, older Safari, an iframe
 * denied the permission. A bare `!== null` reads all of those as *already
 * fullscreen*, which silently disables the key handling that defers to it.
 */
function fullscreenElement(): Element | null {
    return document.fullscreenElement ?? null;
}

/**
 * The call taking over the window.
 *
 * # Why this is an overlay and not only the Fullscreen API
 *
 * Browser fullscreen is one element and no browser chrome, which is right for
 * watching somebody's screen share and wrong as the only way to get a bigger
 * grid: it hides the tab strip, it is refused outright without a user gesture in
 * some browsers, and on macOS it animates a whole desktop space in and out. The
 * overlay is the size change people actually want; the button below promotes it
 * to true fullscreen for the cases where that is worth the disruption.
 *
 * The tray is *replaced* rather than layered under, so the call has exactly one
 * set of controls on screen — two would raise the question of which one hangs up.
 *
 * # Spotlight
 *
 * One participant can be focused, which drops everyone else into a filmstrip
 * along the bottom. This is what makes a shared screen readable: in an even
 * grid, a colleague's IDE is a thumbnail with unreadable text in it, and the
 * whole reason to look at a share is to read it.
 *
 * `adaptiveStream` on the Room means this also *improves the picture*, not just
 * its size — LiveKit tracks how large each element actually is and subscribes to
 * a higher-resolution layer for the one that grew.
 */
function HuddleStage({
    active,
    initialFocus,
    onClose,
}: {
    active: ActiveHuddle;
    /** Who to open onto, when the stage was opened by clicking a face. */
    initialFocus: string | null;
    onClose: () => void;
}) {
    const container = useRef<HTMLDivElement>(null);
    const [isFullscreen, setFullscreen] = useState(false);

    /*
     * Whose tile is spotlit, by participant identity.
     *
     * Seeded from the prop, which is safe here in a way it usually is not: this
     * component is mounted only when the stage opens, so the prop is read once
     * on a fresh mount rather than frozen at some earlier render's value.
     */
    const [focus, setFocus] = useState<string | null>(initialFocus);

    /*
     * The browser owns fullscreen state, not this component: the user can leave
     * it with Escape or the system chrome without going through the button, and
     * a local boolean would then be a button labelled the wrong way round.
     */
    useEffect(() => {
        const sync = () => setFullscreen(fullscreenElement() !== null);

        document.addEventListener('fullscreenchange', sync);
        sync();

        return () => document.removeEventListener('fullscreenchange', sync);
    }, []);

    const toggleFullscreen = useCallback(() => {
        if (fullscreenElement() !== null) {
            // Rejects in a document that is not fullscreen, which is a race
            // rather than a problem worth surfacing.
            void document.exitFullscreen().catch(() => undefined);

            return;
        }

        // Refused when the browser does not consider this gesture-initiated, or
        // when a permissions policy forbids it. Neither is worth an error: the
        // overlay is already the bigger view, and this only makes it bigger.
        void container.current?.requestFullscreen().catch(() => undefined);
    }, []);

    /*
     * Escape unwinds one layer at a time: browser fullscreen, then the
     * spotlight, then the stage.
     *
     * Collapsing them would drop somebody who pressed Escape to stop looking at
     * a screen share all the way back to a corner panel they did not ask for.
     * The fullscreen step is handled by the browser itself — it consumes that
     * keypress before the page sees it — so this only has to not *also* act on
     * it, which is what the `fullscreenElement` check is for.
     */
    useEffect(() => {
        const onKeyDown = (event: KeyboardEvent) => {
            if (event.key !== 'Escape' || fullscreenElement() !== null) {
                return;
            }

            if (focus !== null) {
                setFocus(null);

                return;
            }

            onClose();
        };

        window.addEventListener('keydown', onKeyDown);

        return () => window.removeEventListener('keydown', onKeyDown);
    }, [focus, onClose]);

    // Leaving the stage must not leave the document fullscreen behind it.
    useEffect(
        () => () => {
            if (fullscreenElement() !== null) {
                void document.exitFullscreen().catch(() => undefined);
            }
        },
        [],
    );

    const connecting = active.state !== ConnectionState.Connected;
    const people = active.participants;

    /*
     * A stable description of who is sharing, so the effect below can fire on a
     * share *starting* rather than on every re-snapshot. `people` is a fresh
     * array several times a second while anybody speaks, and keying on it would
     * make the effect run constantly for nothing.
     */
    const sharing = people
        .filter((person) => person.isScreenSharing)
        .map((person) => person.identity);

    const sharingKey = [...sharing].sort().join(',');
    const previouslySharing = useRef<string[]>(sharing);

    /*
     * Somebody starting to share takes the spotlight.
     *
     * The whole reason to share a screen is that people should look at it, and
     * making everyone find and click the right thumbnail first is a
     * five-second pause on every demo. Only on the *transition* to sharing —
     * clearing the spotlight afterwards has to stick, or a viewer who
     * deliberately went back to the grid would be yanked out of it on the next
     * frame.
     */
    useEffect(() => {
        const current = sharingKey === '' ? [] : sharingKey.split(',');

        const started = current.find(
            (identity) => !previouslySharing.current.includes(identity),
        );

        previouslySharing.current = current;

        if (started !== undefined) {
            setFocus(started);
        }
    }, [sharingKey]);

    /*
     * React's documented way to drop state that a prop has invalidated: compare
     * during render, so the correction lands in the same commit rather than
     * painting a spotlight on somebody who has left first.
     */
    const focused =
        focus === null
            ? null
            : (people.find((person) => person.identity === focus) ?? null);

    if (focus !== null && focused === null && people.length > 0) {
        setFocus(null);
    }

    return (
        <div
            ref={container}
            role="dialog"
            aria-modal="false"
            aria-label={`Huddle in ${active.label}`}
            className="fixed inset-0 z-50 flex flex-col bg-neutral-950"
        >
            <div className="flex items-center gap-2 px-4 py-3">
                {connecting ? (
                    <Spinner className="size-4 shrink-0 text-brand" />
                ) : (
                    <span
                        aria-hidden="true"
                        className="size-2 shrink-0 rounded-full bg-brand"
                    />
                )}

                <p className="min-w-0 flex-1 truncate text-sm font-semibold text-white">
                    {connecting ? 'Connecting…' : `Huddle in ${active.label}`}
                </p>

                <p className="shrink-0 text-xs text-white/60">
                    {people.length === 1
                        ? '1 person'
                        : `${people.length} people`}
                </p>

                {/*
                 * The way out of the spotlight, spelled out. Clicking the big
                 * tile and pressing Escape both do it, and neither announces
                 * itself — a viewer who did not choose the spotlight (a share
                 * starting puts them there) has no reason to guess at either.
                 */}
                {focused !== null && (
                    <button
                        type="button"
                        onClick={() => setFocus(null)}
                        className="shrink-0 rounded-lg bg-white/10 px-2.5 py-1.5 text-xs font-medium text-white/80 transition-colors hover:bg-white/20 hover:text-white"
                    >
                        <Grid2x2
                            aria-hidden="true"
                            className="mr-1.5 inline size-3.5"
                        />
                        Show everyone
                    </button>
                )}

                <button
                    type="button"
                    onClick={toggleFullscreen}
                    title={isFullscreen ? 'Exit full screen' : 'Full screen'}
                    className="shrink-0 rounded-lg p-2 text-white/70 transition-colors hover:bg-white/10 hover:text-white"
                >
                    {isFullscreen ? (
                        <Minimize aria-hidden="true" className="size-4" />
                    ) : (
                        <Maximize2 aria-hidden="true" className="size-4" />
                    )}

                    <span className="sr-only">
                        {isFullscreen ? 'Exit full screen' : 'Full screen'}
                    </span>
                </button>

                <button
                    type="button"
                    onClick={onClose}
                    title="Back to the corner"
                    className="shrink-0 rounded-lg p-2 text-white/70 transition-colors hover:bg-white/10 hover:text-white"
                >
                    <Minimize2 aria-hidden="true" className="size-4" />
                    <span className="sr-only">Back to the corner</span>
                </button>
            </div>

            {focused === null ? (
                <div className="min-h-0 flex-1 overflow-y-auto px-4">
                    <div
                        className={cn(
                            'mx-auto grid h-full content-center gap-3',
                            // Capped so a call of four does not become four tiles
                            // the width of a wall on an ultrawide display.
                            columnsFor(people.length),
                            people.length === 1 ? 'max-w-4xl' : 'max-w-7xl',
                        )}
                    >
                        {people.map((participant) => (
                            <HuddleTile
                                key={participant.identity}
                                participant={participant}
                                size="expanded"
                                onSelect={() => setFocus(participant.identity)}
                            />
                        ))}
                    </div>
                </div>
            ) : (
                <>
                    {/*
                     * `min-h-0` on a flex child is what actually lets this
                     * shrink: a flex item defaults to `min-height: auto`, so
                     * without it the tile refuses to go below its content size
                     * and pushes the filmstrip and controls off the bottom of
                     * the window.
                     */}
                    <div className="flex min-h-0 flex-1 items-center justify-center px-4">
                        <HuddleTile
                            participant={focused}
                            size="focused"
                            selected
                            onSelect={() => setFocus(null)}
                        />
                    </div>

                    {people.length > 1 && (
                        <div className="flex shrink-0 justify-center gap-2 overflow-x-auto px-4 pt-3">
                            {people
                                .filter(
                                    (person) =>
                                        person.identity !== focused.identity,
                                )
                                .map((participant) => (
                                    <button
                                        key={participant.identity}
                                        type="button"
                                        onClick={() =>
                                            setFocus(participant.identity)
                                        }
                                        title={`Focus ${participant.isLocal ? 'yourself' : participant.name}`}
                                        className="w-32 shrink-0 rounded-xl focus-visible:outline-none"
                                    >
                                        {/*
                                         * The tile is inert here — the wrapper
                                         * carries the click, so the filmstrip
                                         * scrolls as one row of fixed-width
                                         * items rather than as tiles that each
                                         * size themselves.
                                         */}
                                        <HuddleTile participant={participant} />
                                    </button>
                                ))}
                        </div>
                    )}
                </>
            )}

            <div className="flex items-center justify-center gap-1.5 px-4 py-4">
                <HuddleControls active={active} tone="dark" />
            </div>
        </div>
    );
}

/**
 * How many across the grid runs.
 *
 * Tile aspect is fixed at 16:9, so the count is what decides whether a call
 * reads as a conversation or as a contact sheet. Two-up until it stops fitting.
 */
function columnsFor(count: number): string {
    if (count <= 1) {
        return 'grid-cols-1';
    }

    if (count <= 4) {
        return 'grid-cols-1 sm:grid-cols-2';
    }

    if (count <= 9) {
        return 'grid-cols-2 sm:grid-cols-3';
    }

    return 'grid-cols-2 sm:grid-cols-3 lg:grid-cols-4';
}

/**
 * Mic, camera, screen share, hang up.
 *
 * Shared by the tray and the stage rather than written twice: these are the
 * controls of one call, and two copies would eventually disagree about which
 * state lights which button.
 */
function HuddleControls({
    active,
    tone = 'light',
}: {
    active: ActiveHuddle;
    tone?: 'light' | 'dark';
}) {
    const { leave, toggleMic, toggleCamera, toggleScreenShare } = useHuddle();

    return (
        <>
            <TrayControl
                label={active.isMicEnabled ? 'Mute' : 'Unmute'}
                onClick={() => void toggleMic()}
                // Inverted on purpose: the lit state is the *hazardous* one.
                // A muted mic is the thing people need to notice, not a
                // working one.
                active={!active.isMicEnabled}
                tone={tone}
                danger
            >
                {active.isMicEnabled ? (
                    <Mic aria-hidden="true" className="size-4" />
                ) : (
                    <MicOff aria-hidden="true" className="size-4" />
                )}
            </TrayControl>

            <TrayControl
                label={
                    active.isCameraEnabled
                        ? 'Turn camera off'
                        : 'Turn camera on'
                }
                onClick={() => void toggleCamera()}
                active={active.isCameraEnabled}
                tone={tone}
            >
                {active.isCameraEnabled ? (
                    <Video aria-hidden="true" className="size-4" />
                ) : (
                    <VideoOff aria-hidden="true" className="size-4" />
                )}
            </TrayControl>

            <TrayControl
                label={
                    active.isScreenSharing
                        ? 'Stop sharing'
                        : 'Share your screen'
                }
                onClick={() => void toggleScreenShare()}
                active={active.isScreenSharing}
                tone={tone}
            >
                <MonitorUp aria-hidden="true" className="size-4" />
            </TrayControl>

            <span
                aria-hidden="true"
                className={cn(
                    'mx-1 h-5 w-px',
                    tone === 'dark' ? 'bg-white/20' : 'bg-border',
                )}
            />

            <TrayControl
                label="Leave huddle"
                onClick={() => void leave()}
                active
                tone={tone}
                danger
            >
                <PhoneOff aria-hidden="true" className="size-4" />
            </TrayControl>
        </>
    );
}

function TrayControl({
    label,
    onClick,
    active,
    tone = 'light',
    danger = false,
    children,
}: {
    label: string;
    onClick: () => void;
    active: boolean;
    /** `dark` is for the stage, which sits on its own near-black surface. */
    tone?: 'light' | 'dark';
    danger?: boolean;
    children: React.ReactNode;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-pressed={active}
            title={label}
            className={cn(
                'rounded-lg p-2 transition-colors',
                tone === 'dark' && 'p-3',
                active
                    ? danger
                        ? 'bg-destructive text-white hover:bg-destructive/90'
                        : 'text-brand-foreground bg-brand hover:bg-brand/90'
                    : tone === 'dark'
                      ? 'bg-white/10 text-white/80 hover:bg-white/20 hover:text-white'
                      : 'text-muted-foreground hover:bg-accent hover:text-foreground',
            )}
        >
            {children}
            <span className="sr-only">{label}</span>
        </button>
    );
}
