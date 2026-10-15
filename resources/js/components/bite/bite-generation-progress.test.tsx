import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    BiteGenerationProgress,
    BiteThinking,
} from '@/components/bite/bite-generation-progress';
import type { BitePendingGeneration } from '@/types/bite';

const generation: BitePendingGeneration = {
    id: 1,
    kind: 'image',
    model: 'Mystic',
    prompt: 'A red tuk-tuk at sunrise',
    status: 'processing',
    aspect_ratio: '1:1',
};

let intersect: (entries: { isIntersecting: boolean }[]) => void;
const disconnect = vi.fn();

beforeEach(() => {
    vi.useFakeTimers();
    vi.spyOn(document, 'hidden', 'get').mockReturnValue(false);
    vi.spyOn(Math, 'random').mockReturnValue(0);
    vi.stubGlobal(
        'IntersectionObserver',
        class {
            constructor(callback: typeof intersect) {
                intersect = callback;
            }
            observe() {
                intersect([{ isIntersecting: true }]);
            }
            disconnect = disconnect;
        },
    );
});

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

describe('Bite generation progress', () => {
    it('shows an animated preview with model context and changing activity words', () => {
        render(<BiteGenerationProgress generation={generation} />);
        expect(
            screen.getByRole('status', {
                name: 'Generating image with Mystic',
            }),
        ).toHaveAttribute('data-active', 'true');
        expect(
            screen.getByText('A red tuk-tuk at sunrise'),
        ).toBeInTheDocument();
        expect(screen.getByText('Creating your image')).toBeInTheDocument();
        expect(screen.getByText('Thinking…')).toBeInTheDocument();

        act(() => vi.advanceTimersByTime(3200));
        expect(screen.getByText('Imagining…')).toBeInTheDocument();
        act(() => vi.advanceTimersByTime(3200));
        expect(screen.getByText('Composing…')).toBeInTheDocument();
    });

    it('pauses off-screen activity and cleans up when a job disappears', () => {
        const { unmount } = render(
            <BiteGenerationProgress generation={generation} />,
        );
        act(() => intersect([{ isIntersecting: false }]));
        expect(screen.getByRole('status')).toHaveAttribute(
            'data-active',
            'false',
        );
        act(() => vi.advanceTimersByTime(10000));
        expect(screen.getByText('Thinking…')).toBeInTheDocument();

        unmount();
        expect(disconnect).toHaveBeenCalled();
        expect(vi.getTimerCount()).toBe(0);
    });

    it('pauses activity in a hidden tab', () => {
        render(<BiteThinking />);
        vi.spyOn(document, 'hidden', 'get').mockReturnValue(true);
        fireEvent(document, new Event('visibilitychange'));
        expect(screen.getByRole('status')).toHaveAttribute(
            'data-active',
            'false',
        );
    });

    it('uses the same preview for video generation', () => {
        render(
            <BiteGenerationProgress
                generation={{
                    ...generation,
                    kind: 'video',
                    model: 'Kling 2.6 Pro',
                    aspect_ratio: '16:9',
                }}
            />,
        );
        expect(
            screen.getByRole('status', {
                name: 'Generating video with Kling 2.6 Pro',
            }),
        ).toBeInTheDocument();
        expect(screen.getByText('Creating your video')).toBeInTheDocument();
    });
});
