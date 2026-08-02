import { useSyncExternalStore } from 'react';

export const colorThemes = [
    {
        value: 'original',
        label: 'Original',
        colors: ['#ffffff', '#f97316', '#171717'],
    },
    { value: 'sage', label: 'Sage', colors: ['#edf3eb', '#4f7a55', '#253229'] },
    {
        value: 'ocean',
        label: 'Ocean',
        colors: ['#ebf2f4', '#39788d', '#233139'],
    },
    { value: 'clay', label: 'Clay', colors: ['#f5ece7', '#ad5f47', '#342824'] },
    { value: 'rose', label: 'Rose', colors: ['#f5ecee', '#a85868', '#34272b'] },
    {
        value: 'graphite',
        label: 'Graphite',
        colors: ['#f0f1ef', '#59615d', '#252826'],
    },
] as const;

export type ColorTheme = (typeof colorThemes)[number]['value'];

type UseColorThemeReturn = {
    readonly colorTheme: ColorTheme;
    readonly updateColorTheme: (theme: ColorTheme) => void;
};

const listeners = new Set<() => void>();
const colorThemeValues = new Set<string>(colorThemes.map(({ value }) => value));
let currentColorTheme: ColorTheme = 'original';

const isColorTheme = (value: string | null): value is ColorTheme => {
    return value !== null && colorThemeValues.has(value);
};

const getStoredColorTheme = (): ColorTheme => {
    if (typeof window === 'undefined') {
        return 'original';
    }

    const storedTheme = localStorage.getItem('color-theme');

    return isColorTheme(storedTheme) ? storedTheme : 'original';
};

const applyColorTheme = (theme: ColorTheme): void => {
    if (typeof document === 'undefined') {
        return;
    }

    document.documentElement.dataset.colorTheme = theme;
};

const subscribe = (callback: () => void) => {
    listeners.add(callback);

    return () => listeners.delete(callback);
};

const notify = (): void => listeners.forEach((listener) => listener());

export function initializeColorTheme(): void {
    if (typeof window === 'undefined') {
        return;
    }

    currentColorTheme = getStoredColorTheme();
    applyColorTheme(currentColorTheme);
}

export function useColorTheme(): UseColorThemeReturn {
    const colorTheme = useSyncExternalStore(
        subscribe,
        () => currentColorTheme,
        () => 'original' as ColorTheme,
    );

    const updateColorTheme = (theme: ColorTheme): void => {
        currentColorTheme = theme;
        localStorage.setItem('color-theme', theme);
        applyColorTheme(theme);
        notify();
    };

    return { colorTheme, updateColorTheme } as const;
}
