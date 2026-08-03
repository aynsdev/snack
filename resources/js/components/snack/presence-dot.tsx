import { useLivePresence } from '@/hooks/use-live-presence';
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
 *
 * Pass `userId` wherever it is known: the dot then follows live
 * `presence.changed` broadcasts instead of freezing at whatever the page was
 * rendered with, which is what made someone stay "Active" in the DM list for as
 * long as the tab stayed open. Without it the dot still renders correctly, just
 * only as of the last server response.
 */
export function PresenceDot({
    status,
    userId,
    className,
    size = 'md',
    withLabel = false,
}: {
    status: PresenceStatus;
    /** Who this dot belongs to, so live updates can find it. */
    userId?: number | null;
    className?: string;
    size?: 'sm' | 'md';
    withLabel?: boolean;
}) {
    const live = useLivePresence(userId, status);
    const label = PRESENCE_LABELS[live];
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
                    live === 'online' && 'border-success bg-success',
                    live === 'away' && 'border-warning bg-transparent',
                    live === 'offline' &&
                        'border-muted-foreground/60 bg-transparent',
                    live === 'do_not_disturb' && 'border-danger bg-danger',
                )}
            >
                {live === 'do_not_disturb' && (
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
