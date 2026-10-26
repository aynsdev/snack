import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { PlanActions } from '@/components/snack/plan-actions';

describe('PlanActions', () => {
    it('approves straight away and then shows it was sent', () => {
        const onSend = vi.fn().mockResolvedValue(undefined);
        render(<PlanActions onSend={onSend} />);

        fireEvent.click(screen.getByRole('button', { name: 'Approve' }));

        expect(onSend).toHaveBeenCalledWith('/approve');
        expect(
            screen.getByRole('button', { name: 'Approval sent' }),
        ).toBeDisabled();
    });

    it('asks before cancelling', () => {
        const onSend = vi.fn().mockResolvedValue(undefined);
        render(<PlanActions onSend={onSend} />);

        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(onSend).not.toHaveBeenCalled();

        fireEvent.click(screen.getByRole('button', { name: 'Keep it' }));
        expect(onSend).not.toHaveBeenCalled();

        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        fireEvent.click(screen.getByRole('button', { name: 'Cancel run' }));

        expect(onSend).toHaveBeenCalledWith('/cancel');
        expect(
            screen.getByRole('button', { name: 'Cancel sent' }),
        ).toBeDisabled();
    });

    it('offers the buttons again when sending fails', async () => {
        const onSend = vi.fn().mockRejectedValue(new Error('offline'));
        render(<PlanActions onSend={onSend} />);

        fireEvent.click(screen.getByRole('button', { name: 'Approve' }));

        expect(
            await screen.findByRole('button', { name: 'Approve' }),
        ).toBeEnabled();
    });
});
