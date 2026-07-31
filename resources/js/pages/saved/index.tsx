import { Head, Link } from '@inertiajs/react';
import { Bookmark } from 'lucide-react';
import {
    ContainerLabel,
    useContainerHref,
} from '@/components/snack/container-label';
import { EmptyState } from '@/components/snack/empty-state';
import { MessageText } from '@/components/snack/message-text';
import { UserAvatar } from '@/components/snack/user-avatar';
import { dayLabel } from '@/lib/message-grouping';
import { cn } from '@/lib/utils';
import type { SavedSummary } from '@/types';

type Props = {
    saved: SavedSummary[];
};

/**
 * Private bookmarks.
 *
 * Visible only to the person who made them — nothing here is shared with the
 * channel, which is the whole difference between saving and pinning. Ordered by
 * when they were saved rather than when the message was written: the useful
 * question is "what did I put aside", and the answer is often an old message.
 */
export default function SavedIndex({ saved }: Props) {
    return (
        <>
            <Head title="Saved" />

            <div className="flex min-w-0 flex-1 flex-col">
                <header className="flex items-center gap-2 border-b border-border px-4 py-3">
                    <Bookmark
                        aria-hidden="true"
                        className="size-4 text-muted-foreground"
                    />
                    <h1 className="text-base font-bold tracking-tight">
                        Saved
                    </h1>

                    {saved.length > 0 && (
                        <span className="text-sm text-muted-foreground tabular-nums">
                            {saved.length}
                        </span>
                    )}
                </header>

                <div className="snack-scroll min-h-0 flex-1 overflow-y-auto">
                    {saved.length === 0 ? (
                        <EmptyState
                            icon={Bookmark}
                            title="Nothing saved yet"
                            description="Save a message to keep it somewhere you can find it again. Only you can see what you have saved."
                        />
                    ) : (
                        <ul className="divide-y divide-border">
                            {saved.map((bookmark) => (
                                <SavedRow
                                    key={bookmark.id}
                                    bookmark={bookmark}
                                />
                            ))}
                        </ul>
                    )}
                </div>
            </div>
        </>
    );
}

function SavedRow({ bookmark }: { bookmark: SavedSummary }) {
    const href = useContainerHref(bookmark.container);
    const message = bookmark.message;

    return (
        <li>
            <Link
                href={href ?? '#'}
                className={cn(
                    'flex gap-3 px-4 py-3 transition-colors hover:bg-accent/50',
                    href === null && 'pointer-events-none opacity-60',
                )}
            >
                <div className="pt-0.5">
                    {message.sender && (
                        <UserAvatar user={message.sender} size="md" />
                    )}
                </div>

                <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-2">
                        <ContainerLabel container={bookmark.container} />

                        {bookmark.saved_at !== null && (
                            <span className="ml-auto shrink-0 text-xs text-muted-foreground">
                                Saved {dayLabel(bookmark.saved_at)}
                            </span>
                        )}
                    </div>

                    <p className="mt-0.5 text-xs text-muted-foreground">
                        {message.sender?.name ?? 'Snack'}
                    </p>

                    <div className="mt-1 line-clamp-3 text-sm">
                        <MessageText body={message.body} />
                    </div>

                    {bookmark.notes !== null && (
                        <p className="mt-1.5 border-l-2 border-brand/60 pl-2 text-xs text-muted-foreground">
                            {bookmark.notes}
                        </p>
                    )}
                </div>
            </Link>
        </li>
    );
}
