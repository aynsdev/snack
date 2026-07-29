import { Hash, Lock, Megaphone } from 'lucide-react';
import { cn } from '@/lib/utils';
import type { ChannelType } from '@/types';

const ICONS = {
    public: Hash,
    private: Lock,
    announcement: Megaphone,
} as const;

const LABELS: Record<ChannelType, string> = {
    public: 'Public channel',
    private: 'Private channel',
    announcement: 'Announcement channel',
};

/**
 * The glyph that prefixes a channel name everywhere it appears.
 *
 * Carries an accessible label so a screen reader announces "Private channel
 * finance" rather than "finance", which is the difference between knowing and
 * guessing whether a room is safe to paste something into.
 */
export function ChannelIcon({
    type,
    className,
}: {
    type: ChannelType;
    className?: string;
}) {
    const Icon = ICONS[type];

    return (
        <>
            <Icon
                aria-hidden="true"
                className={cn('size-4 shrink-0', className)}
                strokeWidth={2.25}
            />
            <span className="sr-only">{LABELS[type]}</span>
        </>
    );
}
