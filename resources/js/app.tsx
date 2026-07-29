import { createInertiaApp } from '@inertiajs/react';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import SettingsLayout from '@/layouts/settings/layout';
import WorkspaceLayout from '@/layouts/workspace-layout';

// Configures the Reverb/Echo connection as a side effect, before any component
// subscribes to a channel.
import '@/lib/echo';

const appName = import.meta.env.VITE_APP_NAME || 'Snack';

/**
 * Pages that render inside the three-column workspace shell.
 *
 * The workspace picker and the create-workspace form are excluded: neither has
 * a workspace to build a rail and sidebar from.
 */
function usesWorkspaceShell(name: string): boolean {
    if (name === 'workspace/index' || name === 'workspace/create') {
        return false;
    }

    return (
        name.startsWith('channels/') ||
        name.startsWith('conversations/') ||
        name.startsWith('workspace/') ||
        name.startsWith('admin/')
    );
}

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name === 'welcome':
            case name === 'workspace/index':
            case name === 'workspace/create':
                return null;
            case name.startsWith('auth/'):
                return AuthLayout;
            case usesWorkspaceShell(name):
                return WorkspaceLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    strictMode: true,
    withApp(app) {
        return (
            <TooltipProvider delayDuration={0}>
                {app}
                <Toaster />
            </TooltipProvider>
        );
    },
    progress: {
        // The brand orange, so the loading bar reads as part of Snack.
        color: '#F97316',
    },
});

// This will set light / dark mode on load...
initializeTheme();
