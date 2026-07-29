import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * The shared empty state.
 *
 * Every empty surface in Snack says what the space is for and offers the one
 * action that fills it — an empty channel list without a "create a channel"
 * button is a dead end.
 */
export function EmptyState({
    icon: Icon,
    title,
    description,
    action,
    className,
}: {
    icon?: LucideIcon;
    title: string;
    description?: string;
    action?: ReactNode;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'flex flex-col items-center justify-center px-6 py-12 text-center',
                className,
            )}
        >
            {Icon && (
                <span className="mb-4 flex size-12 items-center justify-center rounded-2xl bg-brand-subtle text-brand">
                    <Icon aria-hidden="true" className="size-6" />
                </span>
            )}

            <h3 className="text-base font-semibold text-balance">{title}</h3>

            {description && (
                <p className="mt-1.5 max-w-sm text-sm text-pretty text-muted-foreground">
                    {description}
                </p>
            )}

            {action && <div className="mt-5">{action}</div>}
        </div>
    );
}
