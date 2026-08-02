import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ThemePicker } from '@/components/theme-picker';

const updateColorTheme = vi.fn();

vi.mock('@/hooks/use-color-theme', async (importOriginal) => {
    const original = (await importOriginal()) as Record<string, unknown>;

    return {
        ...original,
        useColorTheme: () => ({
            colorTheme: 'sage',
            updateColorTheme,
        }),
    };
});

describe('ThemePicker', () => {
    beforeEach(() => updateColorTheme.mockClear());

    it('shows all available color themes', () => {
        render(<ThemePicker />);

        expect(screen.getAllByRole('button')).toHaveLength(6);
        expect(screen.getByRole('button', { name: 'Sage' })).toHaveAttribute(
            'aria-pressed',
            'true',
        );
    });

    it('selects a color theme', async () => {
        const user = userEvent.setup();
        render(<ThemePicker />);

        await user.click(screen.getByRole('button', { name: 'Rose' }));

        expect(updateColorTheme).toHaveBeenCalledWith('rose');
    });
});
