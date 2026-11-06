import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { WebhookDocs } from '@/components/snack/webhook-docs';

// The card renderer draws `text` through MessageText, which reads the current
// workspace for mention links; the guide's examples mention nobody.
vi.mock('@/hooks/use-workspace', () => ({
    useCurrentUser: () => null,
    useCurrentWorkspace: () => null,
}));

const limits = {
    max_text_length: 4000,
    max_attachments: 10,
    max_fields: 20,
    rate_limit_per_minute: 60,
};

describe('WebhookDocs', () => {
    it('opens on the basics with the server’s own limits', () => {
        render(<WebhookDocs limits={limits} />);

        expect(screen.getByRole('button', { name: 'Basics' })).toHaveAttribute(
            'aria-pressed',
            'true',
        );
        expect(screen.getByText('4,000 chars')).toBeInTheDocument();
        expect(
            screen.getByText(/60 deliveries a minute per webhook/),
        ).toBeInTheDocument();
    });

    it('explains that cards are not files and draws a real card', async () => {
        const user = userEvent.setup();
        render(<WebhookDocs limits={limits} />);

        await user.click(screen.getByRole('button', { name: 'Cards' }));

        expect(screen.getByText(/They are not files/)).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'TXN-1042' })).toHaveAttribute(
            'href',
            'https://example.com/transactions/1042',
        );
        expect(screen.getByText('₱1,250.00')).toBeInTheDocument();
    });

    it('shows that colour tints the card, not the message', async () => {
        const user = userEvent.setup();
        render(<WebhookDocs limits={limits} />);

        await user.click(screen.getByRole('button', { name: 'Colours' }));

        expect(screen.getByText(/not to\s+the message/)).toBeInTheDocument();

        for (const color of ['good', 'warning', 'danger', 'default']) {
            expect(screen.getByText(`"color": "${color}"`)).toBeInTheDocument();
        }

        expect(
            screen.getByText('Succeeded, paid, settled').closest('.border-l-2'),
        ).toHaveClass('border-l-emerald-500');
    });

    it('teaches thread_key for grouping updates', async () => {
        const user = userEvent.setup();
        render(<WebhookDocs limits={limits} />);

        await user.click(screen.getByRole('button', { name: 'Threads' }));

        expect(
            screen.getByText(/every later delivery with the same key replies/),
        ).toBeInTheDocument();
        expect(screen.getByText('2 replies')).toBeInTheDocument();
    });
});
