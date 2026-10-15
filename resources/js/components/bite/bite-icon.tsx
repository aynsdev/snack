import { cn } from '@/lib/utils';
import biteAvatar from '../../../images/bite-avatar.png';

/**
 * Bite's own mark — the bot account's avatar — at icon size.
 *
 * Shaped like a lucide icon (a component taking `className`) so it drops into
 * anything that renders one, such as the navigation rail.
 */
export function BiteIcon({ className }: { className?: string }) {
    return (
        <img
            src={biteAvatar}
            alt=""
            aria-hidden="true"
            className={cn('rounded-md object-cover', className)}
        />
    );
}
