import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it } from 'vitest';
import { initializeColorTheme, useColorTheme } from '@/hooks/use-color-theme';

function ColorThemeHarness() {
    const { colorTheme, updateColorTheme } = useColorTheme();

    return (
        <button type="button" onClick={() => updateColorTheme('sage')}>
            {colorTheme}
        </button>
    );
}

describe('useColorTheme', () => {
    beforeEach(() => {
        localStorage.clear();
        delete document.documentElement.dataset.colorTheme;
        initializeColorTheme();
    });

    it('restores a saved color theme', () => {
        localStorage.setItem('color-theme', 'ocean');
        initializeColorTheme();
        render(<ColorThemeHarness />);

        expect(screen.getByRole('button')).toHaveTextContent('ocean');
        expect(document.documentElement.dataset.colorTheme).toBe('ocean');
    });

    it('persists and applies a new color theme', async () => {
        const user = userEvent.setup();
        render(<ColorThemeHarness />);

        await user.click(screen.getByRole('button'));

        expect(localStorage.getItem('color-theme')).toBe('sage');
        expect(document.documentElement.dataset.colorTheme).toBe('sage');
    });
});
