import { act, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { HeaderClock } from '@/components/hr/header-clock';
import type { HeaderClockState } from '@/types/hr';

const usePage = vi.hoisted(() => vi.fn());
const routerPost = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/react', () => ({
    usePage,
    Link: ({
        children,
        href,
    }: {
        children: React.ReactNode;
        href: string | { url: string };
    }) => <a href={typeof href === 'string' ? href : href.url}>{children}</a>,
    router: { post: routerPost },
}));

vi.mock('@/hooks/use-workspace', () => ({
    useWorkspace: () => ({ slug: 'snack-hq' }),
}));

function pageWith(clock: HeaderClockState | null) {
    usePage.mockReturnValue({ props: { clock, flash: {} } });
}

describe('HeaderClock', () => {
    beforeEach(() => {
        vi.useFakeTimers({ shouldAdvanceTime: true });
        vi.setSystemTime(new Date('2026-09-29T11:30:00Z'));
        routerPost.mockReset();

        // Deny location so the click resolves straight to the post.
        Object.defineProperty(navigator, 'geolocation', {
            configurable: true,
            value: {
                getCurrentPosition: (
                    _ok: unknown,
                    fail: (error: {
                        code: number;
                        PERMISSION_DENIED: number;
                    }) => void,
                ) => fail({ code: 1, PERMISSION_DENIED: 1 }),
            },
        });
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('renders nothing for someone without an employee file', () => {
        pageWith(null);

        const { container } = render(<HeaderClock />);

        expect(container).toBeEmptyDOMElement();
    });

    it('offers to clock in, and posts to the clock-in endpoint', async () => {
        pageWith({
            employee_id: 1,
            open_entry: null,
            require_location: false,
            geofence: null,
        });

        render(<HeaderClock />);

        await userEvent.click(
            screen.getByRole('button', { name: /clock in/i }),
        );

        expect(routerPost).toHaveBeenCalledWith(
            '/w/snack-hq/hr/time/clock-in',
            expect.objectContaining({ latitude: null, longitude: null }),
            expect.any(Object),
        );
    });

    it('shows the running time to the second once clocked in', () => {
        pageWith({
            employee_id: 1,
            open_entry: { id: 1, clock_in_at: '2026-09-29T09:15:00Z' },
            require_location: false,
            geofence: null,
        });

        render(<HeaderClock />);

        const trigger = screen.getByRole('button', { name: /clocked in/i });

        expect(trigger).toHaveTextContent('02:15:00');

        act(() => {
            vi.advanceTimersByTime(7000);
        });

        expect(trigger).toHaveTextContent('02:15:07');

        act(() => {
            vi.advanceTimersByTime(60 * 60 * 1000);
        });

        expect(trigger).toHaveTextContent('03:15:07');
    });
});
