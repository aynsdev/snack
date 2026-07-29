import type { Auth } from '@/types/auth';
import type { FlashMessages, SnackProps } from '@/types/snack';

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
            [key: string]: unknown;
        };
    }
}
