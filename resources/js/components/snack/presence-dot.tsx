import { cn } from '@/lib/utils';
import type { PresenceStatus } from '@/types';

const PRESENCE_LABELS: Record<PresenceStatus, string> = {
    online: 'Active',
    away: 'Away',
    offline: 'Offline',
    do_not_disturb: 'Do not disturb',
};

/**
 * Availability indicator.
 *
 * Each state has a distinct *shape* as well as a colour — filled, hollow,
 * hollow-grey, barred — because roughly one in twelve people cannot reliably
 * tell the green from the amber.
 */
export function PresenceDot({
    status,
    className,
    size = 'md',
    withLabel = false,
}: {
    status: PresenceStatus;
    className?: string;
    size?: 'sm' | 'md';
    withLabel?: boolean;
}) {
    const label = PRESENCE_LABELS[status];
    const dimension = size === 'sm' ? 'size-2' : 'size-2.5';

    return (
        <span
            className={cn('inline-flex items-center gap-1.5', className)}
            title={withLabel ? undefined : label}
        >
            <span
                aria-hidden="true"
                className={cn(
                    dimension,
                    'relative shrink-0 rounded-full border-2',
                    status === 'online' && 'border-success bg-success',
                    status === 'away' && 'border-warning bg-transparent',
                    status === 'offline' &&
                        'border-muted-foreground/60 bg-transparent',
                    status === 'do_not_disturb' && 'border-danger bg-danger',
                )}
            >
                {status === 'do_not_disturb' && (
                    // The bar that distinguishes DND from Active without colour.
                    <span className="absolute top-1/2 left-1/2 h-[1.5px] w-[60%] -translate-x-1/2 -translate-y-1/2 rounded-full bg-background" />
                )}
            </span>

            {withLabel ? (
                <span className="text-xs text-muted-foreground">{label}</span>
            ) : (
                <span className="sr-only">{label}</span>
            )}
        </span>
    );
}
