import type { Auth } from '@/types/auth';
import type { HeaderClockState } from '@/types/hr';
import type { ConnectorFlash, FlashMessages, SnackProps } from '@/types/snack';

declare module 'react' {
    // eslint-disable-next-line @typescript-eslint/no-unused-vars
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            /** Workspace rail and current-workspace navigation. */
            snack: SnackProps;
            flash: FlashMessages;
            sidebarOpen: boolean;
            /** The header time clock; null outside a workspace or without an employee file. */
            clock: HeaderClockState | null;
            [key: string]: unknown;
        };
        /**
         * One-time data sent with Inertia::flash() — never persisted to the
         * history state, which is why a freshly issued credential travels here.
         */
        flashDataType: {
            connector?: ConnectorFlash;
        };
    }
}
