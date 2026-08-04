import { ConnectionState } from 'livekit-client';
import {
    ChevronDown,
    ChevronUp,
    Mic,
    MicOff,
    MonitorUp,
    PhoneOff,
    Video,
    VideoOff,
} from 'lucide-react';
import { useState } from 'react';
import { HuddleTile } from '@/components/snack/huddle/huddle-tile';
import { Spinner } from '@/components/ui/spinner';
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
 */
export function HuddleTray() {
    const {
        active,
        error,
        leave,
        toggleMic,
        toggleCamera,
        toggleScreenShare,
        dismissError,
    } = useHuddle();

    const [expanded, setExpanded] = useState(true);

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

    const connecting = active.state !== ConnectionState.Connected;

    return (
        <div className="fixed right-4 bottom-4 z-50 w-72 overflow-hidden rounded-xl border border-border bg-card shadow-lg">
            <button
                type="button"
                onClick={() => setExpanded((open) => !open)}
                aria-expanded={expanded}
                className="flex w-full items-center gap-2 px-3 py-2 text-left transition-colors hover:bg-accent"
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
                    {connecting ? 'Connecting…' : `Huddle in ${active.label}`}
                </span>

                <span className="shrink-0 text-muted-foreground">
                    {expanded ? (
                        <ChevronDown aria-hidden="true" className="size-3.5" />
                    ) : (
                        <ChevronUp aria-hidden="true" className="size-3.5" />
                    )}
                </span>
            </button>

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
                <TrayControl
                    label={active.isMicEnabled ? 'Mute' : 'Unmute'}
                    onClick={() => void toggleMic()}
                    // Inverted on purpose: the lit state is the *hazardous* one.
                    // A muted mic is the thing people need to notice, not a
                    // working one.
                    active={!active.isMicEnabled}
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
                >
                    <MonitorUp aria-hidden="true" className="size-4" />
                </TrayControl>

                <span aria-hidden="true" className="mx-1 h-5 w-px bg-border" />

                <TrayControl
                    label="Leave huddle"
                    onClick={() => void leave()}
                    active
                    danger
                >
                    <PhoneOff aria-hidden="true" className="size-4" />
                </TrayControl>
            </div>
        </div>
    );
}

function TrayControl({
    label,
    onClick,
    active,
    danger = false,
    children,
}: {
    label: string;
    onClick: () => void;
    active: boolean;
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
                active
                    ? danger
                        ? 'bg-destructive text-white hover:bg-destructive/90'
                        : 'text-brand-foreground bg-brand hover:bg-brand/90'
                    : 'text-muted-foreground hover:bg-accent hover:text-foreground',
            )}
        >
            {children}
            <span className="sr-only">{label}</span>
        </button>
    );
}
