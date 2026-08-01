import { Link } from '@inertiajs/react';
import { SnackLogo } from '@/components/snack/snack-logo';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

/**
 * The shell every auth screen sits in.
 *
 * Carries the Snack lockup rather than the starter kit's generic mark: this is
 * the first screen anyone signing in sees, and it is the only place the product
 * gets to introduce itself before the workspace takes over.
 */
export default function AuthSimpleLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    return (
        <div className="relative flex min-h-svh flex-col items-center justify-center overflow-hidden bg-background p-6 md:p-10">
            <div
                aria-hidden="true"
                className="pointer-events-none absolute inset-x-0 -top-40 h-80 bg-gradient-to-b from-brand-subtle to-transparent opacity-60 blur-3xl"
            />

            <div className="relative w-full max-w-sm">
                <div className="flex flex-col gap-8">
                    <div className="flex flex-col items-center gap-6">
                        <Link
                            href={home()}
                            aria-label="Snack home"
                            className="rounded-md focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                        >
                            <SnackLogo />
                        </Link>

                        <div className="space-y-2 text-center">
                            <h1 className="text-xl font-semibold tracking-tight">
                                {title}
                            </h1>
                            <p className="text-sm text-pretty text-muted-foreground">
                                {description}
                            </p>
                        </div>
                    </div>

                    {children}
                </div>
            </div>
        </div>
    );
}
