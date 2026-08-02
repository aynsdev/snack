import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import AppearanceTabs from '@/components/appearance-tabs';

const updateAppearance = vi.fn();

vi.mock('@/hooks/use-appearance', () => ({
    useAppearance: () => ({
        appearance: 'system',
        resolvedAppearance: 'light',
        updateAppearance,
    }),
}));

describe('AppearanceTabs', () => {
    beforeEach(() => updateAppearance.mockClear());

    it('marks the current theme as selected', () => {
        render(<AppearanceTabs />);

        expect(screen.getByRole('button', { name: 'System' })).toHaveAttribute(
            'aria-pressed',
            'true',
        );
    });

    it('changes the appearance from the segmented control', async () => {
        const user = userEvent.setup();
        render(<AppearanceTabs />);

        await user.click(screen.getByRole('button', { name: 'Dark' }));

        expect(updateAppearance).toHaveBeenCalledWith('dark');
    });
});
