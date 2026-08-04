import { Form, Head, Link } from '@inertiajs/react';
import { AtSign, Bell, CheckCheck, Inbox, MessagesSquare } from 'lucide-react';
import { useMemo } from 'react';
import { EmptyState } from '@/components/snack/empty-state';
import { MentionRow } from '@/components/snack/mention-row';
import { NotificationRow } from '@/components/snack/notification-row';
import { ThreadRow } from '@/components/snack/thread-row';
import { Button } from '@/components/ui/button';
import { useNotifications } from '@/hooks/use-notifications';
import { useCurrentUser, useWorkspace } from '@/hooks/use-workspace';
import { cn } from '@/lib/utils';
import { index as activityIndex } from '@/routes/activity';
import { update as markAllRead } from '@/routes/notifications/read';
import type {
    ActivityTab,
    MentionSummary,
    NotificationEntry,
    ThreadSummary,
} from '@/types';

type Props = {
    tab: ActivityTab;
    /** Null on a tab that does not include this list — see the controller. */
    notifications: NotificationEntry[] | null;
    mentions: MentionSummary[] | null;
    threads: ThreadSummary[] | null;
};

/**
 * Everything waiting for you: notifications, mentions, and followed threads.
 *
 * Three destinations that answered one question, made into tabs over it. All
 * interleaves them by time — what happened, in the order it happened — and the
 * others are each list on its own, unchanged from the pages that still serve
 * them at their own addresses.
 *
 * The open tab lives in the URL rather than in state, so it survives a reload
 * and a shared link, and so the server can build only the list being looked at.
 */
export default function ActivityIndex({
    tab,
    notifications,
    mentions,
    threads,
}: Props) {
    const workspace = useWorkspace();
    const currentUser = useCurrentUser();

    /*
     * A module-level constant rather than `?? []`: the hook below compares this
     * prop by identity to decide whether the server has sent a new page, and a
     * fresh array literal every render would make that comparison always true —
     * a setState during render, forever.
     */
    const { notifications: live, markAsRead } = useNotifications({
        workspaceSlug: workspace.slug,
        userId: currentUser?.id ?? 0,
        initial: notifications ?? NO_NOTIFICATIONS,
    });

    const feed = useMemo(
        () => mergeFeed(live, mentions, threads),
        [live, mentions, threads],
    );

    const unread = live.filter((entry) => !entry.is_read).length;

    return (
        <>
            <Head title="Activity" />

            <div className="flex min-w-0 flex-1 flex-col">
                <header className="border-b border-border">
                    <div className="flex items-center gap-2 px-4 pt-3">
                        <Inbox
                            aria-hidden="true"
                            className="size-4 text-muted-foreground"
                        />
                        <h1 className="text-base font-bold tracking-tight">
                            Activity
                        </h1>

                        {unread > 0 && (
                            <span className="rounded-full bg-mention px-1.5 py-0.5 text-[0.6875rem] leading-none font-bold text-mention-foreground tabular-nums">
                                {unread}
                            </span>
                        )}

                        {/*
                         * Only where it applies. Mentions and threads are read
                         * by opening what they point at, so a button offering to
                         * clear them from here would be a promise this page
                         * cannot keep.
                         */}
                        {notifications !== null && unread > 0 && (
                            <Form
                                {...markAllRead.form(workspace.slug)}
                                className="ml-auto"
                            >
                                <Button
                                    type="submit"
                                    variant="ghost"
                                    size="sm"
                                    className="gap-1.5 text-muted-foreground"
                                >
                                    <CheckCheck aria-hidden="true" />
                                    Mark all read
                                </Button>
                            </Form>
                        )}
                    </div>

                    <nav aria-label="Activity" className="flex gap-1 px-2 pt-2">
                        {TABS.map(({ value, label, icon }) => (
                            <ActivityTabLink
                                key={value}
                                workspaceSlug={workspace.slug}
                                tab={value}
                                label={label}
                                icon={icon}
                                isActive={value === tab}
                            />
                        ))}
                    </nav>
                </header>

                <div className="snack-scroll min-h-0 flex-1 overflow-y-auto">
                    {feed.length === 0 ? (
                        <EmptyState
                            icon={EMPTY_STATES[tab].icon}
                            title={EMPTY_STATES[tab].title}
                            description={EMPTY_STATES[tab].description}
                        />
                    ) : (
                        <ul className="divide-y divide-border">
                            {feed.map((item) => {
                                switch (item.kind) {
                                    case 'notification':
                                        return (
                                            <NotificationRow
                                                key={item.key}
                                                notification={item.entry}
                                                onOpen={markAsRead}
                                            />
                                        );
                                    case 'mention':
                                        return (
                                            <MentionRow
                                                key={item.key}
                                                mention={item.entry}
                                            />
                                        );
                                    case 'thread':
                                        return (
                                            <ThreadRow
                                                key={item.key}
                                                thread={item.entry}
                                            />
                                        );
                                }
                            })}
                        </ul>
                    )}
                </div>
            </div>
        </>
    );
}

const NO_NOTIFICATIONS: NotificationEntry[] = [];

const TABS: {
    value: ActivityTab;
    label: string;
    icon: typeof Bell;
}[] = [
    { value: 'all', label: 'All', icon: Inbox },
    { value: 'notifications', label: 'Notifications', icon: Bell },
    { value: 'mentions', label: 'Mentions', icon: AtSign },
    { value: 'threads', label: 'Threads', icon: MessagesSquare },
];

function ActivityTabLink({
    workspaceSlug,
    tab,
    label,
    icon: Icon,
    isActive,
}: {
    workspaceSlug: string;
    tab: ActivityTab;
    label: string;
    icon: typeof Bell;
    isActive: boolean;
}) {
    return (
        <Link
            href={activityIndex.url(workspaceSlug, { query: { tab } })}
            /*
             * Only the lists change between tabs, so the shared props — rail,
             * sidebar, unread badges — are left alone. It also keeps the
             * sidebar's scroll position, which a full visit would reset.
             */
            only={['tab', 'notifications', 'mentions', 'threads']}
            preserveScroll
            aria-current={isActive ? 'page' : undefined}
            className={cn(
                'flex items-center gap-1.5 rounded-t-lg border-b-2 px-3 py-2 text-sm transition-colors',
                isActive
                    ? 'border-brand font-semibold text-foreground'
                    : 'border-transparent text-muted-foreground hover:text-foreground',
            )}
        >
            <Icon aria-hidden="true" className="size-4" />
            {label}
        </Link>
    );
}

/**
 * One row of the merged feed, tagged with what it is and when it happened.
 */
type FeedItem =
    | {
          kind: 'notification';
          key: string;
          at: string | null;
          entry: NotificationEntry;
      }
    | { kind: 'mention'; key: string; at: string | null; entry: MentionSummary }
    | { kind: 'thread'; key: string; at: string | null; entry: ThreadSummary };

/**
 * Interleave whichever lists this tab carries, newest first.
 *
 * Sorting client-side because the three come from different tables with
 * different notions of "when" — a notification's own timestamp, the mention's
 * message, a thread's latest reply — and a SQL union over that would need all
 * three normalised into one shape before they could be compared. They are
 * already bounded to twenty rows apiece, so the sort is over sixty items at
 * most.
 *
 * Undated rows sort last rather than first: an unknown time is not a recent
 * one, and letting it head the list would put the least informative row where
 * the most urgent one belongs.
 */
function mergeFeed(
    notifications: NotificationEntry[],
    mentions: MentionSummary[] | null,
    threads: ThreadSummary[] | null,
): FeedItem[] {
    const items: FeedItem[] = [
        ...notifications.map((entry): FeedItem => ({
            kind: 'notification',
            key: `notification-${entry.id}`,
            at: entry.created_at,
            entry,
        })),
        ...(mentions ?? []).map((entry): FeedItem => ({
            kind: 'mention',
            key: `mention-${entry.id}`,
            at: entry.message.created_at,
            entry,
        })),
        ...(threads ?? []).map((entry): FeedItem => ({
            kind: 'thread',
            key: `thread-${entry.root.id}`,
            at: entry.root.last_reply_at ?? entry.root.created_at,
            entry,
        })),
    ];

    // Both are UTC ISO-8601 from the server, so they compare lexically.
    return items.sort((left, right) => {
        if (left.at === right.at) {
            return 0;
        }

        if (left.at === null) {
            return 1;
        }

        if (right.at === null) {
            return -1;
        }

        return right.at.localeCompare(left.at);
    });
}

const EMPTY_STATES: Record<
    ActivityTab,
    { icon: typeof Bell; title: string; description: string }
> = {
    all: {
        icon: Inbox,
        title: 'Nothing waiting',
        description:
            'Mentions, direct messages, and replies in threads you follow all land here — so catching up is one list rather than three.',
    },
    notifications: {
        icon: Bell,
        title: 'Nothing waiting',
        description:
            'Mentions, direct messages, and replies in threads you follow land here — so you can catch up on what was aimed at you without rereading every channel.',
    },
    mentions: {
        icon: AtSign,
        title: 'No mentions yet',
        description:
            'When somebody types your @handle — or addresses the whole channel — it lands here, so you can find it again without scrolling back through every conversation.',
    },
    threads: {
        icon: MessagesSquare,
        title: 'No threads yet',
        description:
            'Reply to a message to start a thread. Anything you start or answer shows up here, so you can pick it back up without hunting for it.',
    },
};
