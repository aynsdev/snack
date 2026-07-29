import { fileURLToPath } from 'node:url';
import react from '@vitejs/plugin-react';
import { defineConfig } from 'vitest/config';

/**
 * Vitest runs against the React sources directly, so the Laravel/Wayfinder Vite
 * plugins from `vite.config.ts` are intentionally absent here — they expect a
 * PHP process and a manifest that do not exist during unit tests.
 */
export default defineConfig({
    plugins: [react()],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    test: {
        environment: 'jsdom',
        globals: true,
        setupFiles: ['./resources/js/tests/setup.ts'],
        include: ['resources/js/**/*.test.{ts,tsx}'],
        restoreMocks: true,
        coverage: {
            provider: 'v8',
            include: ['resources/js/**/*.{ts,tsx}'],
            exclude: ['resources/js/actions/**', 'resources/js/routes/**', 'resources/js/wayfinder/**'],
        },
    },
});
