import { render } from '@testing-library/react';
import { expect, it, vi } from 'vitest';
import type { HuddleParticipantView } from '@/hooks/use-huddle';

/**
 * What a tile does with the media track behind it.
 *
 * Both rules here are about a picture that is technically correct and horrible
 * to look at: a camera that strobes, and a self-view that is not a mirror.
 *
 * livekit-client is stubbed down to the one enum the component reads. The real
 * module opens a WebRTC stack on import, and none of it is the subject here.
 */
vi.mock('livekit-client', () => ({
    Track: {
        Source: { Camera: 'camera', ScreenShare: 'screen_share' },
    },
}));

const { HuddleTile } = await import('@/components/snack/huddle/huddle-tile');

type FakeTrack = {
    attach: ReturnType<typeof vi.fn>;
    detach: ReturnType<typeof vi.fn>;
};

function fakeTrack(): FakeTrack {
    return { attach: vi.fn(), detach: vi.fn() };
}

/**
 * A participant publishing `tracks`, keyed by source.
 *
 * `getTrackPublication` is a function rather than a snapshot because that is how
 * LiveKit behaves: the object mutates in place, and the component has to cope
 * with a track appearing without any prop changing.
 */
function participantView(
    tracks: Partial<Record<string, FakeTrack>>,
    overrides: Partial<HuddleParticipantView> = {},
): HuddleParticipantView {
    return {
        identity: 'user.1',
        userId: 1,
        name: 'Alicia Reyes',
        avatarUrl: null,
        isLocal: false,
        isSpeaking: false,
        isMicEnabled: true,
        isCameraEnabled: true,
        isScreenSharing: false,
        participant: {
            getTrackPublication: (source: string) =>
                tracks[source] === undefined
                    ? undefined
                    : { track: tracks[source] },
        },
        ...overrides,
    } as unknown as HuddleParticipantView;
}

it('attaches a track once however often it re-renders', () => {
    const camera = fakeTrack();
    const participant = participantView({ camera });

    const { rerender } = render(<HuddleTile participant={participant} />);

    /*
     * The tray re-snapshots on every RoomEvent, and ActiveSpeakersChanged alone
     * fires several times a second while anybody is talking. An earlier version
     * attached on each render and detached in the cleanup, so the stream was
     * torn out of the element and put back continuously — detach() nulls
     * srcObject, which reads as a camera that strobes for the whole call.
     */
    rerender(<HuddleTile participant={participantView({ camera })} />);
    rerender(<HuddleTile participant={participantView({ camera })} />);

    expect(camera.attach).toHaveBeenCalledTimes(1);
    expect(camera.detach).not.toHaveBeenCalled();
});

it('swaps the element over when the source changes', () => {
    const camera = fakeTrack();
    const screen = fakeTrack();

    const { rerender } = render(
        <HuddleTile participant={participantView({ camera })} />,
    );

    rerender(
        <HuddleTile
            participant={participantView(
                { camera, screen_share: screen },
                { isScreenSharing: true },
            )}
        />,
    );

    // Idempotence must not become inertia: a real change still has to land.
    expect(camera.detach).toHaveBeenCalledTimes(1);
    expect(screen.attach).toHaveBeenCalledTimes(1);
});

it('mirrors your own camera and nobody else’s', () => {
    const own = render(
        <HuddleTile
            participant={participantView(
                { camera: fakeTrack() },
                { isLocal: true },
            )}
        />,
    );

    // A camera preview is a mirror — people expect to reach right and see the
    // reflection move right.
    expect(own.container.querySelector('video')).toHaveClass('-scale-x-100');

    const theirs = render(
        <HuddleTile participant={participantView({ camera: fakeTrack() })} />,
    );

    // Remote video is already mirrored by the sender's own browser before it is
    // published, so flipping it again would undo it.
    expect(theirs.container.querySelector('video')).not.toHaveClass(
        '-scale-x-100',
    );
});

it('never mirrors a screen share', () => {
    const { container } = render(
        <HuddleTile
            participant={participantView(
                { screen_share: fakeTrack() },
                { isLocal: true, isScreenSharing: true },
            )}
        />,
    );

    // The one case where the mirror convention would actively break something:
    // backwards text.
    expect(container.querySelector('video')).not.toHaveClass('-scale-x-100');
});
