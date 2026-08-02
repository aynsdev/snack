import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ThemeSwitcher } from '@/components/theme-switcher';

const updateColorTheme = vi.fn();

vi.mock('@/hooks/use-color-theme', async (importOriginal) => {
    const original = (await importOriginal()) as Record<string, unknown>;

    return {
        ...original,
        useColorTheme: () => ({
            colorTheme: 'original',
            updateColorTheme,
        }),
    };
});

describe('ThemeSwitcher', () => {
    beforeEach(() => updateColorTheme.mockClear());

    it('exposes the current color theme from the workspace rail', () => {
        render(<ThemeSwitcher />);

        expect(
            screen.getByRole('button', {
                name: 'Change color theme. Current theme: Original',
            }),
        ).toBeInTheDocument();
    });

    it('changes the color theme from the menu', async () => {
        const user = userEvent.setup();
        render(<ThemeSwitcher />);

        await user.click(
            screen.getByRole('button', {
                name: 'Change color theme. Current theme: Original',
            }),
        );
        await user.click(
            await screen.findByRole('menuitemradio', { name: 'Ocean' }),
        );

        expect(updateColorTheme).toHaveBeenCalledWith('ocean');
    });
});
