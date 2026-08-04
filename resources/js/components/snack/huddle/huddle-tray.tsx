import { ConnectionState } from 'livekit-client';
import {
    ChevronDown,
    ChevronUp,
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
    const [stage, setStage] = useState(false);

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

    if (stage) {
        return <HuddleStage active={active} onClose={() => setStage(false)} />;
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
                    onClick={() => setStage(true)}
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
 */
function HuddleStage({
    active,
    onClose,
}: {
    active: ActiveHuddle;
    onClose: () => void;
}) {
    const container = useRef<HTMLDivElement>(null);
    const [isFullscreen, setFullscreen] = useState(false);

    /*
     * The browser owns fullscreen state, not this component: the user can leave
     * it with Escape or the system chrome without going through the button, and
     * a local boolean would then be a button labelled the wrong way round.
     */
    useEffect(() => {
        const sync = () => setFullscreen(document.fullscreenElement !== null);

        document.addEventListener('fullscreenchange', sync);
        sync();

        return () => document.removeEventListener('fullscreenchange', sync);
    }, []);

    const toggleFullscreen = useCallback(() => {
        if (document.fullscreenElement !== null) {
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
     * Escape closes the stage — but only once the browser is done with it.
     * While fullscreen, the first Escape is consumed by the browser to leave
     * fullscreen, and closing the overlay on that same press would collapse two
     * steps into one and drop the user back to a corner panel they did not ask
     * for.
     */
    useEffect(() => {
        const onKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Escape' && document.fullscreenElement === null) {
                onClose();
            }
        };

        window.addEventListener('keydown', onKeyDown);

        return () => window.removeEventListener('keydown', onKeyDown);
    }, [onClose]);

    // Leaving the stage must not leave the document fullscreen behind it.
    useEffect(
        () => () => {
            if (document.fullscreenElement !== null) {
                void document.exitFullscreen().catch(() => undefined);
            }
        },
        [],
    );

    const connecting = active.state !== ConnectionState.Connected;
    const people = active.participants;

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

            <div className="min-h-0 flex-1 overflow-y-auto px-4">
                <div
                    className={cn(
                        'mx-auto grid h-full content-center gap-3',
                        // Capped so a call of four does not become four tiles the
                        // width of a wall on an ultrawide display.
                        columnsFor(people.length),
                        people.length === 1 ? 'max-w-4xl' : 'max-w-7xl',
                    )}
                >
                    {people.map((participant) => (
                        <HuddleTile
                            key={participant.identity}
                            participant={participant}
                            size="expanded"
                        />
                    ))}
                </div>
            </div>

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
