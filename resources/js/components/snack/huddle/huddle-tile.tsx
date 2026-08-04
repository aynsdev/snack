import { Track } from 'livekit-client';
import { MicOff, MonitorUp } from 'lucide-react';
import { useEffect, useRef } from 'react';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import type { HuddleParticipantView } from '@/hooks/use-huddle';
import { useInitials } from '@/hooks/use-initials';
import { cn } from '@/lib/utils';

/**
 * One person in the huddle, as a tile.
 *
 * Shows their camera or screen share when they are publishing one, and their
 * avatar when they are not — which is the usual case, since a huddle is an audio
 * call that can become a video one rather than the other way round.
 *
 * The speaking ring is drawn from LiveKit's own audio-level detection rather
 * than from mute state: a muted person is not speaking, but an unmuted silent
 * one is not either, and the ring is what makes a five-person call followable.
 */
export function HuddleTile({
    participant,
    size = 'compact',
}: {
    participant: HuddleParticipantView;
    /** `expanded` drops the tray's cramped type scale for the full-screen grid. */
    size?: 'compact' | 'expanded';
}) {
    const getInitials = useInitials();
    const video = useRef<HTMLVideoElement>(null);

    /*
     * The track currently painting into the element above.
     *
     * The effect below runs on every render and must therefore be idempotent,
     * which means it needs to know what it did last time. There is nothing in
     * props to compare against: LiveKit's Participant mutates in place, so a
     * track appearing changes no value React can see.
     */
    const attached = useRef<Track | null>(null);

    /*
     * Deliberately without a dependency array — see above. The parent
     * re-snapshots on every relevant RoomEvent, so this runs often.
     *
     * It must therefore do *nothing* when the track has not changed. An earlier
     * version attached unconditionally and detached in the cleanup, which meant
     * every render tore the stream out of the element and put it back: detach()
     * nulls `srcObject`, so the picture blanked for a frame each time. With
     * ActiveSpeakersChanged firing several times a second while anyone is
     * talking, that is a camera that visibly strobes for the whole call.
     */
    useEffect(() => {
        const element = video.current;

        if (element === null) {
            return;
        }

        const source = participant.isScreenSharing
            ? Track.Source.ScreenShare
            : Track.Source.Camera;

        const track =
            participant.participant.getTrackPublication(source)?.track ?? null;

        if (track === attached.current) {
            return;
        }

        // Only reached when the participant switched between camera and screen
        // share, or published or dropped one — never on an ordinary re-render.
        attached.current?.detach(element);
        attached.current = track;
        track?.attach(element);
    });

    /*
     * Unmount only. Detaching with no argument releases every element this
     * track was attached to, which avoids reading the element ref here — React
     * has already cleared it by the time this runs.
     */
    useEffect(
        () => () => {
            attached.current?.detach();
            attached.current = null;
        },
        [],
    );

    const hasVideo = participant.isCameraEnabled || participant.isScreenSharing;
    const expanded = size === 'expanded';

    return (
        <div
            className={cn(
                'relative aspect-video overflow-hidden bg-muted ring-2 transition-colors',
                expanded ? 'rounded-2xl' : 'rounded-xl',
                participant.isSpeaking ? 'ring-brand' : 'ring-transparent',
            )}
        >
            {/*
             * Always mounted, hidden rather than unmounted when there is no
             * video: the ref has to exist before the effect above can attach a
             * track to it, and a track arriving would otherwise find nothing to
             * attach to on the render that discovered it.
             */}
            <video
                ref={video}
                autoPlay
                playsInline
                // Your own camera is played back locally and must never carry
                // audio; the microphone is a separate track rendered elsewhere.
                muted={participant.isLocal}
                className={cn(
                    'size-full',
                    // Screen shares are letterboxed rather than cropped — the
                    // interesting part of a shared window is usually its edges.
                    participant.isScreenSharing
                        ? 'object-contain'
                        : 'object-cover',
                    /*
                     * Your own camera is flipped, and only yours.
                     *
                     * A camera preview is a mirror: people expect to reach right
                     * and see the reflection move right, exactly as in a bathroom
                     * mirror, and an unflipped self-view feels subtly wrong to
                     * look at and makes any writing you hold up unreadable.
                     *
                     * It stops at your camera. Remote participants are flipped by
                     * their *own* browser before publishing, so mirroring them
                     * again would undo it, and a mirrored screen share would be
                     * backwards text — which is the one case the convention would
                     * actively break.
                     */
                    participant.isLocal &&
                        !participant.isScreenSharing &&
                        '-scale-x-100',
                    !hasVideo && 'hidden',
                )}
            />

            {!hasVideo && (
                <div className="flex size-full items-center justify-center">
                    <Avatar
                        className={cn(
                            'rounded-xl',
                            expanded ? 'size-24 rounded-2xl' : 'size-12',
                        )}
                    >
                        {participant.avatarUrl !== null && (
                            <AvatarImage src={participant.avatarUrl} alt="" />
                        )}

                        <AvatarFallback
                            className={cn(
                                'rounded-xl bg-brand-subtle font-semibold',
                                expanded && 'rounded-2xl text-2xl',
                            )}
                        >
                            {getInitials(participant.name)}
                        </AvatarFallback>
                    </Avatar>
                </div>
            )}

            <div
                className={cn(
                    'absolute inset-x-0 bottom-0 flex items-center gap-1.5 bg-gradient-to-t from-black/70 to-transparent',
                    expanded ? 'px-3 pt-8 pb-2' : 'px-2 pt-6 pb-1.5',
                )}
            >
                {!participant.isMicEnabled && (
                    <MicOff
                        aria-hidden="true"
                        className={cn(
                            'shrink-0 text-white/90',
                            expanded ? 'size-4' : 'size-3',
                        )}
                    />
                )}

                {participant.isScreenSharing && (
                    <MonitorUp
                        aria-hidden="true"
                        className={cn(
                            'shrink-0 text-white/90',
                            expanded ? 'size-4' : 'size-3',
                        )}
                    />
                )}

                <span
                    className={cn(
                        'min-w-0 truncate font-medium text-white',
                        expanded ? 'text-sm' : 'text-xs',
                    )}
                >
                    {participant.isLocal ? 'You' : participant.name}
                </span>
            </div>
        </div>
    );
}
