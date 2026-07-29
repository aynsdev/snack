import { PresenceDot } from '@/components/snack/presence-dot';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { cn } from '@/lib/utils';
import type { SnackUser } from '@/types';

const SIZES = {
    xs: 'size-5 text-[0.5625rem]',
    sm: 'size-6 text-[0.625rem]',
    md: 'size-8 text-xs',
    lg: 'size-10 text-sm',
    xl: 'size-16 text-lg',
} as const;

export type AvatarSize = keyof typeof SIZES;

/**
 * A person, with optional presence.
 *
 * The presence dot is positioned outside the avatar's rounded clip and given a
 * ring in the surrounding surface colour, so it stays legible against both a
 * photo and a coloured fallback.
 */
export function UserAvatar({
    user,
    size = 'md',
    showPresence = false,
    ringClassName = 'ring-background',
    className,
}: {
    user: Pick<
        SnackUser,
        'name' | 'initials' | 'avatar_url' | 'presence_status'
    >;
    size?: AvatarSize;
    showPresence?: boolean;
    /** Match this to the surface behind the avatar. */
    ringClassName?: string;
    className?: string;
}) {
    return (
        <span className={cn('relative inline-flex shrink-0', className)}>
            <Avatar className={cn(SIZES[size], 'rounded-lg')}>
                {user.avatar_url && (
                    <AvatarImage src={user.avatar_url} alt="" />
                )}

                <AvatarFallback className="rounded-lg bg-brand-subtle font-semibold text-foreground">
                    {user.initials}
                </AvatarFallback>
            </Avatar>

            {showPresence && (
                <PresenceDot
                    status={user.presence_status}
                    size={size === 'xs' || size === 'sm' ? 'sm' : 'md'}
                    className={cn(
                        'absolute -right-0.5 -bottom-0.5 rounded-full ring-2',
                        ringClassName,
                    )}
                />
            )}
        </span>
    );
}

/**
 * Overlapping avatars for thread participants and channel member previews.
 */
export function AvatarGroup({
    users,
    max = 3,
    size = 'xs',
    className,
}: {
    users: Pick<
        SnackUser,
        'id' | 'name' | 'initials' | 'avatar_url' | 'presence_status'
    >[];
    max?: number;
    size?: AvatarSize;
    className?: string;
}) {
    const visible = users.slice(0, max);
    const overflow = users.length - visible.length;

    return (
        <span className={cn('flex items-center', className)}>
            {visible.map((user) => (
                <UserAvatar
                    key={user.id}
                    user={user}
                    size={size}
                    className="-ml-1 rounded-lg ring-2 ring-[--color-sidebar] first:ml-0"
                />
            ))}

            {overflow > 0 && (
                <span className="ml-1.5 text-xs font-medium text-muted-foreground">
                    +{overflow}
                </span>
            )}
        </span>
    );
}
