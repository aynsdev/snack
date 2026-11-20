import { createInertiaApp } from '@inertiajs/react';
import { QueryClientProvider } from '@tanstack/react-query';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import { initializeColorTheme } from '@/hooks/use-color-theme';
import AuthLayout from '@/layouts/auth-layout';
import SettingsLayout from '@/layouts/settings/layout';
import WorkspaceLayout from '@/layouts/workspace-layout';
import { usesSettingsLayout } from '@/lib/page-layout';
import { queryClient } from '@/lib/query-client';

// Configures the Reverb/Echo connection as a side effect, before any component
// subscribes to a channel.
import '@/lib/echo';

const appName = import.meta.env.VITE_APP_NAME || 'Snack';

/**
 * Pages that do *not* render inside the workspace shell.
 *
 * # Why this is a denylist
 *
 * It used to be the opposite — an allowlist of prefixes that got the shell,
 * with everything else falling through to the starter kit's generic layout.
 * That fails silently and in the worst possible way: a new page renders
 * perfectly, just wearing a different sidebar, and nothing warns you. Threads,
 * Mentions, and Saved all shipped that way.
 *
 * Snack is a workspace application, so the shell is the default and the
 * exceptions are the short, closed list below — the pages that genuinely have
 * no workspace to build a rail and sidebar from. A new page inside a workspace
 * now gets the right chrome by doing nothing at all.
 */
const STANDALONE_PAGES = ['workspace/index', 'workspace/create'];

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case STANDALONE_PAGES.includes(name):
                return null;
            case name.startsWith('auth/'):
                return AuthLayout;
            /*
             * Settings keeps its own sub-navigation, but nests inside the
             * workspace shell rather than replacing it — walking into settings
             * should not feel like leaving the workspace.
             */
            case usesSettingsLayout(name):
                return [WorkspaceLayout, SettingsLayout];
            default:
                return WorkspaceLayout;
        }
    },
    strictMode: true,
    withApp(app) {
        return (
            /*
             * Outside the app, so a timeline's cached pages outlive the page
             * component that fetched them — walking from a channel to a DM and
             * back returns to the history the reader had scrolled through
             * rather than to the newest page and a spinner.
             */
            <QueryClientProvider client={queryClient}>
                <TooltipProvider delayDuration={0}>
                    {app}
                    <Toaster />
                </TooltipProvider>
            </QueryClientProvider>
        );
    },
    progress: {
        // The brand orange, so the loading bar reads as part of Snack.
        color: '#F97316',
    },
});

// This will set light / dark mode on load...
initializeTheme();
initializeColorTheme();
