import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { BiteCard as BiteCardData } from '@/types';

const api = vi.hoisted(() => ({
    get: vi.fn(),
    post: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
}));

vi.mock('@/lib/api', () => ({
    api,
    ApiError: class ApiError extends Error {
        get userMessage() {
            return this.message;
        }
    },
}));

vi.mock('@/hooks/use-workspace', () => ({
    useWorkspace: () => ({ slug: 'snack-hq' }),
}));

const { BiteCard } = await import('@/components/snack/bite-card');

function makeCard(overrides: Partial<BiteCardData> = {}): BiteCardData {
    return {
        conversation_id: 'conv-1',
        state: 'pending',
        approvals: [
            {
                id: 'call_abc',
                tool: 'CreateTask',
                reason: 'Create “Fix the signup redirect” on ENG.',
                summary: 'Create “Fix the signup redirect” on ENG.',
            },
        ],
        ...overrides,
    };
}

describe('BiteCard', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    /**
     * The summary is the entire point of the pause: what the person checks is
     * that the model got the project and the title right. A card that only said
     * "the assistant wants to use a tool" would be clicked through unread.
     */
    it('shows what it is about to do', () => {
        render(<BiteCard card={makeCard()} messageId={12} />);

        expect(
            screen.getByText('Create “Fix the signup redirect” on ENG.'),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Create' }),
        ).toBeInTheDocument();
    });

    it('submits an approval and swaps to the decided state', async () => {
        api.post.mockResolvedValue({ bite: makeCard({ state: 'approved' }) });

        render(<BiteCard card={makeCard()} messageId={12} />);

        await userEvent.click(screen.getByRole('button', { name: 'Create' }));

        await waitFor(() =>
            expect(screen.getByText('You approved this.')).toBeInTheDocument(),
        );

        expect(api.post).toHaveBeenCalledWith(
            expect.stringContaining('/bite'),
            {
                decision: 'approve',
            },
        );
    });

    it('submits a rejection', async () => {
        api.post.mockResolvedValue({ bite: makeCard({ state: 'rejected' }) });

        render(<BiteCard card={makeCard()} messageId={12} />);

        await userEvent.click(screen.getByRole('button', { name: 'Cancel' }));

        await waitFor(() =>
            expect(screen.getByText('You cancelled this.')).toBeInTheDocument(),
        );

        expect(api.post).toHaveBeenCalledWith(expect.anything(), {
            decision: 'reject',
        });
    });

    /**
     * Approving runs a chain of provider calls that can time out. A card stuck
     * on a spinner is one the person can neither retry nor dismiss, so the
     * buttons have to come back.
     */
    it('reports a failure and lets the person try again', async () => {
        api.post.mockRejectedValue(new Error('That did not go through.'));

        render(<BiteCard card={makeCard()} messageId={12} />);

        await userEvent.click(screen.getByRole('button', { name: 'Create' }));

        await waitFor(() =>
            expect(screen.getByRole('alert')).toBeInTheDocument(),
        );

        expect(screen.getByRole('button', { name: 'Create' })).toBeEnabled();
    });

    /**
     * Both buttons go inert while one is in flight — pressing Cancel after
     * Create would submit a second decision against a tool call the server has
     * already consumed.
     */
    it('disables both buttons while a decision is in flight', async () => {
        api.post.mockImplementation(() => new Promise(() => {}));

        render(<BiteCard card={makeCard()} messageId={12} />);

        await userEvent.click(screen.getByRole('button', { name: 'Create' }));

        await waitFor(() =>
            expect(
                screen.getByRole('button', { name: 'Creating…' }),
            ).toBeDisabled(),
        );

        expect(screen.getByRole('button', { name: 'Cancel' })).toBeDisabled();
    });

    /**
     * A card decided in an earlier session arrives already resolved from the
     * message row, and must not offer buttons that address a spent tool call.
     */
    it('renders an already-decided card without buttons', () => {
        render(
            <BiteCard
                card={makeCard({ state: 'approved', approvals: [] })}
                messageId={12}
            />,
        );

        expect(screen.getByText('You approved this.')).toBeInTheDocument();
        expect(screen.queryByRole('button')).not.toBeInTheDocument();
    });
});
