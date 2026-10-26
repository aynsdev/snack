import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';

/**
 * A small uppercase tag beside a name — "Agent" on a message header or a
 * connector row — saying what kind of thing the name belongs to.
 *
 * The explanation in `title` is a hover tooltip only, so it is repeated as
 * screen-reader text: assistive technology does not reliably announce titles.
 */
export function MetaBadge({
    children,
    icon: Icon,
    title,
}: {
    children: ReactNode;
    icon?: LucideIcon;
    title?: string;
}) {
    return (
        <span
            title={title}
            className="inline-flex shrink-0 items-center gap-0.5 rounded bg-muted px-1 py-px text-[0.625rem] font-semibold text-muted-foreground uppercase"
        >
            {Icon !== undefined && (
                <Icon aria-hidden="true" className="size-2.5" />
            )}
            {children}
            {title !== undefined && (
                <span className="sr-only">{`: ${title}`}</span>
            )}
        </span>
    );
}
