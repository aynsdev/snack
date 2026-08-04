import { Room, RoomEvent, Track } from 'livekit-client';
import type { ConnectionState, Participant } from 'livekit-client';
import type { ReactNode } from 'react';
import {
    createContext,
    useCallback,
    useContext,
    useEffect,
    useMemo,
    useRef,
    useState,
} from 'react';
import { api, ApiError } from '@/lib/api';
import {
    destroy as leaveChannelHuddle,
    store as joinChannelHuddle,
} from '@/routes/channels/huddle';
import {
    destroy as leaveConversationHuddle,
    store as joinConversationHuddle,
} from '@/routes/conversations/huddle';
import type { HuddleContainerRef, HuddleJoinResponse } from '@/types';

/**
 * Which container a huddle is being joined in, plus what to call it on screen.
 *
 * Carries the route parameters rather than a built URL so the provider owns
 * every huddle endpoint in one place, and the label because only the caller
 * knows it — a DM's title is different for each participant, so the server
 * cannot supply one.
 */
export type HuddleTarget = {
    workspace: string;
    /** "#design", or the other person's name. */
    label: string;
} & (
    | { kind: 'channel'; channel: string; id: number }
    | { kind: 'conversation'; conversation: string; id: string }
);

/**
 * One person in the call, as the tiles render them.
 *
 * Flattened from LiveKit's `Participant`, which is a mutable object that
 * mutates in place — reading it during render would give React no reason to
 * re-render when somebody unmutes. The object itself is carried along because
 * attaching a media track needs it.
 */
export type HuddleParticipantView = {
    identity: string;
    /** Parsed out of the token metadata the server signed; null if absent. */
    userId: number | null;
    name: string;
    avatarUrl: string | null;
    isLocal: boolean;
    isSpeaking: boolean;
    isMicEnabled: boolean;
    isCameraEnabled: boolean;
    isScreenSharing: boolean;
    participant: Participant;
};

export type ActiveHuddle = {
    huddleId: string;
    container: HuddleContainerRef;
    label: string;
    state: ConnectionState;
    participants: HuddleParticipantView[];
    isMicEnabled: boolean;
    isCameraEnabled: boolean;
    isScreenSharing: boolean;
};

type HuddleContextValue = {
    /** The call this browser is in, anywhere in the workspace. */
    active: ActiveHuddle | null;
    isJoining: boolean;
    /** A message worth showing the user; cleared on the next attempt. */
    error: string | null;
    join: (target: HuddleTarget) => Promise<void>;
    leave: () => Promise<void>;
    toggleMic: () => Promise<void>;
    toggleCamera: () => Promise<void>;
    toggleScreenShare: () => Promise<void>;
    dismissError: () => void;
};

const HuddleContext = createContext<HuddleContextValue | null>(null);

/**
 * Whether this browser can capture a microphone at all.
 *
 * `navigator.mediaDevices` is undefined outside a secure context — HTTPS, or
 * localhost, which browsers exempt. A deployment served over plain HTTP on a
 * real hostname therefore has no microphone API whatsoever, and every call to it
 * throws a TypeError that reads like a bug in this code rather than like the
 * browser policy it is. Checked up front so the failure can say what is actually
 * wrong.
 */
function canCaptureMedia(): boolean {
    return (
        typeof navigator !== 'undefined' &&
        navigator.mediaDevices !== undefined &&
        typeof navigator.mediaDevices.getUserMedia === 'function'
    );
}

const INSECURE_CONTEXT_MESSAGE =
    'Huddles need a secure connection. Open Snack over HTTPS (or localhost) — browsers hide the microphone everywhere else.';

/**
 * Read the `metadata` claim the server signed into the join token.
 *
 * This arrives from the media server, so it arrives from outside the
 * application: it is parsed defensively and used only for drawing a tile. The
 * roster that anything is *decided* from comes from Snack's own API.
 */
function metadataOf(participant: Participant): {
    user_id?: number;
    avatar_url?: string | null;
} {
    if (!participant.metadata) {
        return {};
    }

    try {
        return JSON.parse(participant.metadata);
    } catch {
        return {};
    }
}

function viewOf(
    participant: Participant,
    isLocal: boolean,
): HuddleParticipantView {
    const metadata = metadataOf(participant);

    return {
        identity: participant.identity,
        userId: typeof metadata.user_id === 'number' ? metadata.user_id : null,
        name: participant.name || participant.identity,
        avatarUrl: metadata.avatar_url ?? null,
        isLocal,
        isSpeaking: participant.isSpeaking,
        isMicEnabled: participant.isMicrophoneEnabled,
        isCameraEnabled: participant.isCameraEnabled,
        isScreenSharing: participant.isScreenShareEnabled,
        participant,
    };
}

/**
 * Holds the one call this browser is in.
 *
 * # Why this lives in the layout rather than in a page
 *
 * A huddle has to survive navigation. Walking from #design to a DM while talking
 * is completely ordinary, and a `Room` owned by the channel page would be torn
 * down and rebuilt on that click — dropping the call, renegotiating WebRTC, and
 * making everyone else watch you leave and rejoin. Mounted once in
 * {@see WorkspaceLayout}, the connection outlives every page under it.
 *
 * That is also why there is exactly one: a person can only be in one huddle, the
 * server enforces it in {@see App\Actions\Huddles\JoinHuddle}, and LiveKit
 * enforces it again by disconnecting a duplicate identity.
 *
 * # Why the participant list is copied out of LiveKit
 *
 * `Room` is an event emitter full of objects that mutate in place. Nothing about
 * a participant unmuting changes an identity React can compare, so reading the
 * room during render produces a UI that updates only when something else happens
 * to re-render it. Every relevant event therefore re-snapshots the room into
 * plain state, which is what the tiles read.
 */
export function HuddleProvider({ children }: { children: ReactNode }) {
    const [active, setActive] = useState<ActiveHuddle | null>(null);
    const [isJoining, setJoining] = useState(false);
    const [error, setError] = useState<string | null>(null);

    /*
     * The live Room and the target it belongs to, in refs rather than state.
     * They are needed by event handlers and by the unload listener, none of
     * which should re-subscribe when the roster changes — and the Room is a
     * mutable object that is not React state in any meaningful sense.
     */
    const room = useRef<Room | null>(null);
    const target = useRef<HuddleTarget | null>(null);

    const snapshot = useCallback(() => {
        const current = room.current;
        const meta = target.current;

        if (current === null || meta === null) {
            return;
        }

        const local = current.localParticipant;

        setActive({
            huddleId: current.name,
            container:
                meta.kind === 'channel'
                    ? { kind: 'channel', id: meta.id }
                    : { kind: 'conversation', id: meta.id },
            label: meta.label,
            state: current.state,
            participants: [
                viewOf(local, true),
                ...[...current.remoteParticipants.values()].map((participant) =>
                    viewOf(participant, false),
                ),
            ],
            isMicEnabled: local.isMicrophoneEnabled,
            isCameraEnabled: local.isCameraEnabled,
            isScreenSharing: local.isScreenShareEnabled,
        });
    }, []);

    /** Tear down the local Room without telling the server. */
    const teardown = useCallback(async () => {
        const current = room.current;

        room.current = null;
        target.current = null;
        setActive(null);

        if (current !== null) {
            current.removeAllListeners();
            await current.disconnect();
        }
    }, []);

    const leaveUrl = useCallback(
        (meta: HuddleTarget) =>
            meta.kind === 'channel'
                ? leaveChannelHuddle.url({
                      workspace: meta.workspace,
                      channel: meta.channel,
                  })
                : leaveConversationHuddle.url({
                      workspace: meta.workspace,
                      conversation: meta.conversation,
                  }),
        [],
    );

    const leave = useCallback(async () => {
        const meta = target.current;

        await teardown();

        if (meta === null) {
            return;
        }

        /*
         * Best-effort. The server sweep closes huddles whose participants never
         * said goodbye, so a failed hang-up costs a stale roster for up to a
         * minute rather than forever — and surfacing an error for a call the
         * user has already visibly left would be worse than useless.
         */
        await api.delete(leaveUrl(meta)).catch(() => undefined);
    }, [leaveUrl, teardown]);

    const join = useCallback(
        async (next: HuddleTarget) => {
            setError(null);

            if (!canCaptureMedia()) {
                setError(INSECURE_CONTEXT_MESSAGE);

                return;
            }

            // Out of whatever call is in progress first. The server does the
            // same thing for its own roster; this is the local half.
            await leave();

            setJoining(true);

            try {
                const payload = await api.post<HuddleJoinResponse>(
                    next.kind === 'channel'
                        ? joinChannelHuddle.url({
                              workspace: next.workspace,
                              channel: next.channel,
                          })
                        : joinConversationHuddle.url({
                              workspace: next.workspace,
                              conversation: next.conversation,
                          }),
                );

                const connection = new Room({
                    // Only fetch the video resolution a tile is actually shown
                    // at, and stop sending layers nobody is subscribed to. Both
                    // matter far more than usual here: a huddle is a small strip
                    // of faces, not a full-screen grid.
                    adaptiveStream: true,
                    dynacast: true,
                });

                /*
                 * Wired before connecting, so the participants already in the
                 * room when this one arrives are not missed — they arrive as
                 * events during the connect handshake.
                 */
                const restate = () => snapshot();

                connection
                    .on(RoomEvent.ParticipantConnected, restate)
                    .on(RoomEvent.ParticipantDisconnected, restate)
                    .on(RoomEvent.TrackSubscribed, restate)
                    .on(RoomEvent.TrackUnsubscribed, restate)
                    .on(RoomEvent.TrackMuted, restate)
                    .on(RoomEvent.TrackUnmuted, restate)
                    .on(RoomEvent.LocalTrackPublished, restate)
                    .on(RoomEvent.LocalTrackUnpublished, restate)
                    .on(RoomEvent.ActiveSpeakersChanged, restate)
                    .on(RoomEvent.ConnectionStateChanged, restate)
                    .on(RoomEvent.ParticipantMetadataChanged, restate)
                    .on(RoomEvent.Disconnected, () => {
                        // The media server dropped us — a token that expired
                        // mid-reconnect, a room closed, a network that never
                        // came back. Clear the local state so the UI stops
                        // claiming we are in a call we are not.
                        void teardown();
                    });

                room.current = connection;
                target.current = next;

                await connection.connect(
                    payload.connection.url,
                    payload.connection.token,
                );

                /*
                 * Microphone on, camera off. A huddle is an audio call that can
                 * become a video one — opening somebody's camera because they
                 * clicked "Huddle" is the single most invasive default a chat
                 * app can have.
                 */
                await connection.localParticipant.setMicrophoneEnabled(true);

                /*
                 * Browsers refuse to play audio until a user gesture has
                 * happened. Joining is one, so this normally resolves
                 * immediately; it is called explicitly because the alternative
                 * is a call where nobody can hear anybody and nothing says why.
                 */
                await connection.startAudio().catch(() => undefined);

                snapshot();
            } catch (failure) {
                await teardown();

                setError(
                    failure instanceof ApiError
                        ? failure.userMessage
                        : 'Could not connect to the huddle.',
                );
            } finally {
                setJoining(false);
            }
        },
        [leave, snapshot, teardown],
    );

    const toggleMic = useCallback(async () => {
        const local = room.current?.localParticipant;

        if (local !== undefined) {
            await local.setMicrophoneEnabled(!local.isMicrophoneEnabled);
            snapshot();
        }
    }, [snapshot]);

    const toggleCamera = useCallback(async () => {
        const local = room.current?.localParticipant;

        if (local !== undefined) {
            await local.setCameraEnabled(!local.isCameraEnabled);
            snapshot();
        }
    }, [snapshot]);

    const toggleScreenShare = useCallback(async () => {
        const local = room.current?.localParticipant;

        if (local !== undefined) {
            // A refused screen-share prompt rejects; that is a decision, not an
            // error, and it must not tear down the call.
            await local
                .setScreenShareEnabled(!local.isScreenShareEnabled)
                .catch(() => undefined);
            snapshot();
        }
    }, [snapshot]);

    /*
     * Hang up when the tab goes away.
     *
     * `pagehide` rather than `beforeunload`: it fires on mobile Safari's
     * back-forward cache and on tab close alike, where `beforeunload` is
     * unreliable. `keepalive` is what lets the request outlive the document —
     * the ordinary fetch would be cancelled the instant the page unloads, which
     * is precisely when it is being sent.
     *
     * Never the only mechanism: a crashed tab sends nothing at all, which is
     * what App\Console\Commands\SweepHuddles exists for.
     */
    useEffect(() => {
        const hangUp = () => {
            const meta = target.current;

            if (meta === null) {
                return;
            }

            const token = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);

            void fetch(leaveUrl(meta), {
                method: 'DELETE',
                keepalive: true,
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': token ? decodeURIComponent(token[1]) : '',
                },
            });
        };

        window.addEventListener('pagehide', hangUp);

        return () => window.removeEventListener('pagehide', hangUp);
    }, [leaveUrl]);

    // Disconnect if the provider itself unmounts — signing out, or leaving the
    // workspace shell. Without this the socket survives the React tree.
    useEffect(() => () => void teardown(), [teardown]);

    const value = useMemo<HuddleContextValue>(
        () => ({
            active,
            isJoining,
            error,
            join,
            leave,
            toggleMic,
            toggleCamera,
            toggleScreenShare,
            dismissError: () => setError(null),
        }),
        [
            active,
            isJoining,
            error,
            join,
            leave,
            toggleMic,
            toggleCamera,
            toggleScreenShare,
        ],
    );

    return (
        <HuddleContext.Provider value={value}>
            {children}

            {/*
             * Remote audio has to be attached to real media elements or it is
             * never heard — LiveKit subscribes to the track but the browser has
             * nothing to play it through. Kept here, outside every page, so
             * navigating does not unmount the elements carrying the call.
             */}
            <div aria-hidden="true" className="sr-only">
                {(active?.participants ?? [])
                    .filter((participant) => !participant.isLocal)
                    .map((participant) => (
                        <RemoteAudio
                            key={participant.identity}
                            participant={participant.participant}
                        />
                    ))}
            </div>
        </HuddleContext.Provider>
    );
}

/**
 * One remote participant's audio, attached to an element the browser will play.
 *
 * The local participant is deliberately absent: attaching your own microphone
 * plays it back to you, which is an echo loud enough that people leave the call.
 */
function RemoteAudio({ participant }: { participant: Participant }) {
    const ref = useRef<HTMLAudioElement>(null);
    const attached = useRef<Track | null>(null);

    /*
     * The publication is looked up inside the effect rather than during render.
     * A participant who has not published yet has no track at all, and the
     * effect re-runs when the snapshot that produced this render changes — which
     * is exactly when TrackSubscribed fired.
     *
     * Idempotent for the same reason {@see HuddleTile} is: this runs on every
     * render, and re-attaching an unchanged track would restart playback several
     * times a second while anybody is speaking — which is audible as a stutter,
     * not just as a flicker.
     */
    useEffect(() => {
        const element = ref.current;
        const track =
            participant.getTrackPublication(Track.Source.Microphone)?.track ??
            null;

        if (element === null || track === attached.current) {
            return;
        }

        attached.current?.detach(element);
        attached.current = track;
        track?.attach(element);
    });

    useEffect(
        () => () => {
            attached.current?.detach();
            attached.current = null;
        },
        [],
    );

    return <audio ref={ref} autoPlay />;
}

/**
 * The current call, and the controls for it.
 *
 * Returns a no-op implementation outside the workspace shell — settings and auth
 * pages render no huddle UI, and a hook that threw there would make the header
 * components unusable anywhere else.
 */
export function useHuddle(): HuddleContextValue {
    return (
        useContext(HuddleContext) ?? {
            active: null,
            isJoining: false,
            error: null,
            join: async () => undefined,
            leave: async () => undefined,
            toggleMic: async () => undefined,
            toggleCamera: async () => undefined,
            toggleScreenShare: async () => undefined,
            dismissError: () => undefined,
        }
    );
}

/**
 * Whether the call in progress is the one belonging to this container.
 *
 * The provider holds exactly one huddle for the whole workspace, so every page
 * that renders a huddle affordance has to ask this before deciding between
 * "Join" and "You are in this huddle".
 */
export function useIsHuddlingIn(container: HuddleContainerRef | null): boolean {
    const { active } = useHuddle();

    if (active === null || container === null) {
        return false;
    }

    return (
        active.container.kind === container.kind &&
        active.container.id === container.id
    );
}
