import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { StandupCard as StandupCardData } from '@/types';

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

const { StandupCard } = await import('@/components/snack/standup-card');

function makeCard(overrides: Partial<StandupCardData> = {}): StandupCardData {
    return {
        standup_id: 1,
        run_id: 7,
        response_id: 42,
        name: 'Daily standup',
        channel: { name: 'engineering', slug: 'engineering' },
        date: '2026-08-10',
        date_label: 'Monday 10 August',
        questions: ['What are you working on?', 'Anything blocking you?'],
        closes_at: '2026-08-10T13:30:00+00:00',
        state: 'pending',
        submitted_at: null,
        ...overrides,
    };
}

/**
 * The standup form inside the bot's direct message.
 *
 * The rules being asserted are the two that make it a form rather than a chat
 * exchange: it asks everything at once so the answers can be reviewed before
 * they reach the team, and it stops being a form the moment it has been sent.
 */
describe('StandupCard', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('asks every question at once', () => {
        render(<StandupCard card={makeCard()} />);

        expect(
            screen.getByLabelText('What are you working on?'),
        ).toBeInTheDocument();
        expect(
            screen.getByLabelText('Anything blocking you?'),
        ).toBeInTheDocument();
        expect(screen.getByText(/Posts to #engineering/)).toBeInTheDocument();
    });

    /*
     * Sending nothing is not a standup answer, and the button says where it is
     * going — the person is publishing to their team, not replying to a bot.
     */
    it('will not send an empty form', () => {
        render(<StandupCard card={makeCard()} />);

        expect(
            screen.getByRole('button', { name: 'Send to #engineering' }),
        ).toBeDisabled();
    });

    it('posts the answers positionally, against the questions it was given', async () => {
        const user = userEvent.setup();

        api.post.mockResolvedValue({
            standup: makeCard({
                state: 'submitted',
                submitted_at: '2026-08-10T09:34:00+00:00',
                answers: [
                    {
                        question: 'What are you working on?',
                        answer: 'The parser',
                    },
                ],
            }),
        });

        render(<StandupCard card={makeCard()} />);

        await user.type(
            screen.getByLabelText('What are you working on?'),
            'The parser',
        );
        await user.click(
            screen.getByRole('button', { name: 'Send to #engineering' }),
        );

        await waitFor(() => expect(api.post).toHaveBeenCalledOnce());

        const [url, body] = api.post.mock.calls[0];

        expect(url).toContain('/standup-responses/42');
        // The blank second answer is sent as a blank, not dropped: positions
        // are what tie an answer to its question, so a shorter list would file
        // this answer under the wrong one.
        expect(body).toEqual({ answers: ['The parser', ''], skipped: false });
    });

    /*
     * "Nothing to report" is its own button rather than an empty submission.
     * The person with nothing to say is the one the channel most needs to hear
     * from — otherwise they are indistinguishable from somebody off sick.
     */
    it('sends a skip without needing anything typed', async () => {
        const user = userEvent.setup();

        api.post.mockResolvedValue({
            standup: makeCard({ state: 'skipped', answers: [] }),
        });

        render(<StandupCard card={makeCard()} />);

        await user.click(
            screen.getByRole('button', { name: 'Nothing to report' }),
        );

        await waitFor(() => expect(api.post).toHaveBeenCalledOnce());

        expect(api.post.mock.calls[0][1]).toMatchObject({ skipped: true });
    });

    it('retires the form once it has been sent, and keeps the answers', async () => {
        const user = userEvent.setup();

        api.post.mockResolvedValue({
            standup: makeCard({
                state: 'submitted',
                submitted_at: '2026-08-10T09:34:00+00:00',
                answers: [
                    {
                        question: 'What are you working on?',
                        answer: 'The parser',
                    },
                ],
            }),
        });

        render(<StandupCard card={makeCard()} />);

        await user.type(
            screen.getByLabelText('What are you working on?'),
            'The parser',
        );
        await user.click(
            screen.getByRole('button', { name: 'Send to #engineering' }),
        );

        await waitFor(() =>
            expect(
                screen.queryByRole('button', { name: 'Send to #engineering' }),
            ).not.toBeInTheDocument(),
        );

        // The conversation is the only place the person can see what they sent.
        expect(screen.getByText('The parser')).toBeInTheDocument();
        expect(screen.getByText(/Sent to #engineering/)).toBeInTheDocument();
    });

    /*
     * A card whose response was already answered renders answered from the
     * start — the state rides on the message, so it survives a reload.
     */
    it('renders an already-answered card without a form', () => {
        render(
            <StandupCard
                card={makeCard({
                    state: 'skipped',
                    submitted_at: '2026-08-10T09:34:00+00:00',
                    answers: [],
                })}
            />,
        );

        expect(
            screen.queryByRole('button', { name: 'Send to #engineering' }),
        ).not.toBeInTheDocument();
        expect(screen.getByText(/nothing to report/i)).toBeInTheDocument();
    });

    /*
     * The card is no longer the only way to answer: a plain reply typed into
     * the DM is parsed server-side, which retires this card and broadcasts the
     * message. So an answered card arriving as a new prop has to win over the
     * pending one this rendered with — the version that seeded local state at
     * first render went on offering to answer a standup already in the channel.
     */
    it('retires when the answer arrived some other way', () => {
        const { rerender } = render(<StandupCard card={makeCard()} />);

        expect(
            screen.getByRole('button', { name: 'Send to #engineering' }),
        ).toBeInTheDocument();

        rerender(
            <StandupCard
                card={makeCard({
                    state: 'submitted',
                    submitted_at: '2026-08-10T09:34:00+00:00',
                    answers: [
                        {
                            question: 'What are you working on?',
                            answer: 'The parser',
                        },
                    ],
                })}
            />,
        );

        expect(
            screen.queryByRole('button', { name: 'Send to #engineering' }),
        ).not.toBeInTheDocument();
        expect(screen.getByText('The parser')).toBeInTheDocument();
    });

    it('says so when the send fails, and leaves the form standing', async () => {
        const user = userEvent.setup();

        api.post.mockRejectedValue(new Error('nope'));

        render(<StandupCard card={makeCard()} />);

        await user.type(
            screen.getByLabelText('What are you working on?'),
            'The parser',
        );
        await user.click(
            screen.getByRole('button', { name: 'Send to #engineering' }),
        );

        expect(await screen.findByRole('alert')).toBeInTheDocument();

        // Still answerable — a failed send must not eat what was typed.
        expect(screen.getByLabelText('What are you working on?')).toHaveValue(
            'The parser',
        );
    });
});
