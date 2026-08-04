import { Track } from 'livekit-client';
import { MicOff, MonitorUp } from 'lucide-react';
import { useEffect, useRef } from 'react';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import type { HuddleParticipantView } from '@/hooks/use-huddle';
import { useInitials } from '@/hooks/use-initials';
import { cn } from '@/lib/utils';

/**
 * One person in the huddle tray.
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
}: {
    participant: HuddleParticipantView;
}) {
    const getInitials = useInitials();
    const video = useRef<HTMLVideoElement>(null);

    /*
     * Deliberately without a dependency array.
     *
     * The publication is looked up from a LiveKit object that mutates in place,
     * so there is nothing stable to key on: a participant who has not published
     * yet has no track, and the track appears without any value here changing.
     * The parent re-renders on every relevant RoomEvent — that is what
     * useHuddle's snapshot exists for — so re-running on each render is both
     * correct and cheap, and attach() on an already-attached element is a no-op.
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
            participant.participant.getTrackPublication(source)?.track;

        if (track === undefined) {
            return;
        }

        track.attach(element);

        return () => {
            track.detach(element);
        };
    });

    const hasVideo = participant.isCameraEnabled || participant.isScreenSharing;

    return (
        <div
            className={cn(
                'relative aspect-video overflow-hidden rounded-xl bg-muted ring-2 transition-colors',
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
                    !hasVideo && 'hidden',
                )}
            />

            {!hasVideo && (
                <div className="flex size-full items-center justify-center">
                    <Avatar className="size-12 rounded-xl">
                        {participant.avatarUrl !== null && (
                            <AvatarImage src={participant.avatarUrl} alt="" />
                        )}

                        <AvatarFallback className="rounded-xl bg-brand-subtle font-semibold">
                            {getInitials(participant.name)}
                        </AvatarFallback>
                    </Avatar>
                </div>
            )}

            <div className="absolute inset-x-0 bottom-0 flex items-center gap-1.5 bg-gradient-to-t from-black/70 to-transparent px-2 pt-6 pb-1.5">
                {!participant.isMicEnabled && (
                    <MicOff
                        aria-hidden="true"
                        className="size-3 shrink-0 text-white/90"
                    />
                )}

                {participant.isScreenSharing && (
                    <MonitorUp
                        aria-hidden="true"
                        className="size-3 shrink-0 text-white/90"
                    />
                )}

                <span className="min-w-0 truncate text-xs font-medium text-white">
                    {participant.isLocal ? 'You' : participant.name}
                </span>
            </div>
        </div>
    );
}
