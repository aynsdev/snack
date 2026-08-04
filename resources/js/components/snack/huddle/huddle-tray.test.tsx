import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import type { ActiveHuddle, HuddleParticipantView } from '@/hooks/use-huddle';

/**
 * Getting a good look at one person in a call.
 *
 * The rules that matter are about the spotlight *staying where it was put*: a
 * screen share should claim it without being asked, and a viewer who leaves the
 * spotlight should not be dragged back into it on the next frame — the roster
 * re-snapshots several times a second while anybody is speaking, so anything
 * that recomputes focus from scratch would fight the user continuously.
 */
const state = vi.hoisted(() => ({
    active: null as ActiveHuddle | null,
}));

vi.mock('livekit-client', () => ({
    ConnectionState: { Connected: 'connected' },
    Track: { Source: { Camera: 'camera', ScreenShare: 'screen_share' } },
}));

vi.mock('@/hooks/use-huddle', () => ({
    useHuddle: () => ({
        active: state.active,
        isJoining: false,
        error: null,
        join: vi.fn(),
        leave: vi.fn(),
        toggleMic: vi.fn(),
        toggleCamera: vi.fn(),
        toggleScreenShare: vi.fn(),
        dismissError: vi.fn(),
    }),
}));

const { HuddleTray } = await import('@/components/snack/huddle/huddle-tray');

function person(
    name: string,
    overrides: Partial<HuddleParticipantView> = {},
): HuddleParticipantView {
    return {
        identity: `user.${name}`,
        userId: 1,
        name,
        avatarUrl: null,
        isLocal: false,
        isSpeaking: false,
        isMicEnabled: true,
        isCameraEnabled: false,
        isScreenSharing: false,
        participant: { getTrackPublication: () => undefined },
        ...overrides,
    } as unknown as HuddleParticipantView;
}

function huddle(participants: HuddleParticipantView[]): ActiveHuddle {
    return {
        huddleId: 'huddle-1',
        container: { kind: 'channel', id: 7 },
        label: '#design',
        state: 'connected',
        participants,
        isMicEnabled: true,
        isCameraEnabled: false,
        isScreenSharing: false,
    } as unknown as ActiveHuddle;
}

beforeEach(() => {
    state.active = huddle([person('Alicia'), person('Ben')]);
});

it('opens the stage focused on the face you clicked', () => {
    render(<HuddleTray />);

    fireEvent.click(screen.getByTitle('Focus Ben'));

    // Tapping a thumbnail the size of a stamp to see it bigger is the obvious
    // gesture; the corner panel doing nothing would be a dead end.
    expect(screen.getByTitle('Back to everyone')).toBeInTheDocument();
    expect(screen.getByText('Show everyone')).toBeInTheDocument();
});

it('gives the spotlight to somebody who starts sharing', () => {
    const { rerender } = render(<HuddleTray />);

    fireEvent.click(screen.getByTitle('Expand huddle'));

    state.active = huddle([
        person('Alicia'),
        person('Ben', { isScreenSharing: true }),
    ]);
    rerender(<HuddleTray />);

    /*
     * The whole reason to share a screen is that people should look at it.
     * Making everyone find and click the right thumbnail first is a five-second
     * pause on the start of every demo.
     */
    expect(screen.getByTitle('Back to everyone')).toBeInTheDocument();
    expect(screen.getByTitle('Focus Alicia')).toBeInTheDocument();
});

it('stays out of the spotlight once you have left it', () => {
    const { rerender } = render(<HuddleTray />);

    fireEvent.click(screen.getByTitle('Expand huddle'));

    const sharing = [
        person('Alicia'),
        person('Ben', { isScreenSharing: true }),
    ];

    state.active = huddle(sharing);
    rerender(<HuddleTray />);

    fireEvent.click(screen.getByText('Show everyone'));

    /*
     * The roster re-snapshots on every RoomEvent — ActiveSpeakersChanged alone
     * fires several times a second — and Ben is still sharing throughout. Only
     * the *transition* to sharing may claim the spotlight, or leaving it would
     * be undone on the next frame and read as a broken button.
     */
    state.active = huddle(sharing);
    rerender(<HuddleTray />);
    state.active = huddle(sharing);
    rerender(<HuddleTray />);

    expect(screen.queryByTitle('Back to everyone')).not.toBeInTheDocument();
    expect(screen.getByTitle('Focus Ben')).toBeInTheDocument();
});

it('follows the share that just started, not the one already running', () => {
    const { rerender } = render(<HuddleTray />);

    fireEvent.click(screen.getByTitle('Expand huddle'));

    state.active = huddle([
        person('Alicia', { isScreenSharing: true }),
        person('Ben'),
    ]);
    rerender(<HuddleTray />);

    fireEvent.click(screen.getByText('Show everyone'));

    // Ben starts sharing while Alicia still is. The roster changed, so the
    // effect runs again — and it has to distinguish "Ben just started" from
    // "Alicia is sharing", or the spotlight lands on the person whose share
    // everybody already declined to watch.
    state.active = huddle([
        person('Alicia', { isScreenSharing: true }),
        person('Ben', { isScreenSharing: true }),
    ]);
    rerender(<HuddleTray />);

    expect(screen.getByTitle('Focus Alicia')).toBeInTheDocument();
    expect(screen.queryByTitle('Focus Ben')).not.toBeInTheDocument();
});

it('unwinds one layer at a time on Escape', () => {
    render(<HuddleTray />);

    fireEvent.click(screen.getByTitle('Focus Ben'));

    fireEvent.keyDown(window, { key: 'Escape' });

    // Out of the spotlight, still on the stage. Collapsing both would drop
    // somebody who pressed Escape to stop watching a screen share all the way
    // back to a corner panel they did not ask for.
    expect(screen.queryByTitle('Back to everyone')).not.toBeInTheDocument();
    expect(screen.getByTitle('Back to the corner')).toBeInTheDocument();

    fireEvent.keyDown(window, { key: 'Escape' });

    expect(screen.getByTitle('Expand huddle')).toBeInTheDocument();
});

it('drops the spotlight when that person leaves the call', () => {
    const { rerender } = render(<HuddleTray />);

    fireEvent.click(screen.getByTitle('Focus Ben'));

    state.active = huddle([person('Alicia')]);
    rerender(<HuddleTray />);

    // Staring at a tile belonging to somebody who hung up is worse than being
    // put back in the grid.
    expect(screen.queryByTitle('Back to everyone')).not.toBeInTheDocument();
    expect(screen.getByTitle('Focus Alicia')).toBeInTheDocument();
});
