import { Deferred, Head, Link } from '@inertiajs/react';
import { Info, Users } from 'lucide-react';
import { useState } from 'react';
import { ChannelIcon } from '@/components/snack/channel-icon';
import { MessageComposer } from '@/components/snack/message-composer';
import { MessageList } from '@/components/snack/message-list';
import { RightPanel } from '@/components/snack/right-panel';
import { UserAvatar } from '@/components/snack/user-avatar';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { useChannelMessages } from '@/hooks/use-channel-messages';
import { useNotificationSound } from '@/hooks/use-notification-sound';
import { useCurrentUser, useWorkspace } from '@/hooks/use-workspace';
import { store as joinChannel } from '@/routes/channels/membership';
import type { Channel, MessagePage, SnackUser } from '@/types';

type Props = {
    channel: Channel;
    canPost: boolean;
    canManage: boolean;
    /** The newest page; older pages are fetched as the reader scrolls. */
    history: MessagePage;
    /** Deferred: streams in after the channel paints. */
    members?: SnackUser[];
};

export default function ChannelShow({
    channel,
    canPost,
    canManage,
    history,
    members,
}: Props) {
    const [detailsOpen, setDetailsOpen] = useState(false);

    return (
        <>
            <Head title={`#${channel.slug}`} />

            <div className="flex min-w-0 flex-1 flex-col">
                <ChannelHeader
                    channel={channel}
                    canManage={canManage}
                    onToggleDetails={() => setDetailsOpen((open) => !open)}
                />

                {/*
                 * Keyed by channel so switching channels remounts the timeline.
                 * Without it the hook would carry one channel's messages,
                 * cursor, and pending sends into the next.
                 */}
                <ChannelTimeline
                    key={channel.id}
                    channel={channel}
                    canPost={canPost}
                    history={history}
                />
            </div>

            {detailsOpen && (
                <RightPanel
                    title="About this channel"
                    onClose={() => setDetailsOpen(false)}
                >
                    <ChannelDetails channel={channel} members={members} />
                </RightPanel>
            )}
        </>
    );
}

/**
 * The live part of a channel: history, real-time arrivals, and the composer.
 *
 * Split from the page so it can be remounted per channel while the header and
 * details panel above it are re-rendered rather than rebuilt.
 */
function ChannelTimeline({
    channel,
    canPost,
    history,
}: {
    channel: Channel;
    canPost: boolean;
    history: MessagePage;
}) {
    const workspace = useWorkspace();
    const currentUser = useCurrentUser();
    const playArrival = useNotificationSound(!channel.is_muted);

    const timeline = useChannelMessages({
        channel,
        workspaceSlug: workspace.slug,
        initial: history,
        currentUser,
        onMessageReceived: playArrival,
    });

    return (
        <>
            <MessageList
                messages={timeline.messages}
                pending={timeline.pending}
                hasMore={timeline.hasMore}
                loadingOlder={timeline.loadingOlder}
                currentUserId={currentUser?.id ?? null}
                onLoadOlder={timeline.loadOlder}
                onEdit={timeline.edit}
                onDelete={timeline.remove}
                onRetry={timeline.retry}
                onDiscard={timeline.discard}
                onReachBottom={timeline.markAsRead}
                header={<ChannelIntro channel={channel} />}
            />

            {channel.is_member ? (
                <MessageComposer
                    channelName={`#${channel.slug}`}
                    disabled={!canPost}
                    disabledReason={
                        canPost
                            ? undefined
                            : channel.is_archived
                              ? 'This channel is archived.'
                              : 'Only workspace admins can post in an announcement channel.'
                    }
                    onSend={timeline.send}
                />
            ) : (
                <ChannelPreviewBar channel={channel} />
            )}
        </>
    );
}

function ChannelHeader({
    channel,
    canManage,
    onToggleDetails,
}: {
    channel: Channel;
    canManage: boolean;
    onToggleDetails: () => void;
}) {
    return (
        <header className="flex items-center gap-3 border-b border-border px-4 py-3">
            <div className="flex min-w-0 items-center gap-2">
                <ChannelIcon
                    type={channel.type}
                    className="text-muted-foreground"
                />
                <h1 className="truncate text-base font-bold tracking-tight">
                    {channel.name}
                </h1>

                {channel.is_archived && (
                    <span className="shrink-0 rounded-md bg-muted px-1.5 py-0.5 text-xs font-medium text-muted-foreground">
                        Archived
                    </span>
                )}
            </div>

            {channel.topic && (
                <>
                    <span
                        aria-hidden="true"
                        className="h-4 w-px shrink-0 bg-border"
                    />
                    <p className="min-w-0 flex-1 truncate text-sm text-muted-foreground">
                        {channel.topic}
                    </p>
                </>
            )}

            <div className="ml-auto flex shrink-0 items-center gap-1">
                {typeof channel.member_count === 'number' && (
                    <button
                        type="button"
                        onClick={onToggleDetails}
                        className="flex items-center gap-1.5 rounded-lg px-2 py-1.5 text-sm text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                    >
                        <Users aria-hidden="true" className="size-4" />
                        <span className="tabular-nums">
                            {channel.member_count}
                        </span>
                        <span className="sr-only">members</span>
                    </button>
                )}

                <button
                    type="button"
                    onClick={onToggleDetails}
                    className="rounded-lg p-2 text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                >
                    <Info aria-hidden="true" className="size-4" />
                    <span className="sr-only">
                        {canManage ? 'Channel settings' : 'Channel details'}
                    </span>
                </button>
            </div>
        </header>
    );
}

/**
 * The masthead at the top of a channel's history.
 *
 * Rendered only once the whole history is loaded, since "this is the start of
 * the channel" is a claim that is only true when there is nothing older left to
 * fetch.
 */
function ChannelIntro({ channel }: { channel: Channel }) {
    return (
        <div className="px-6 pt-10 pb-2">
            <span className="mb-4 flex size-12 items-center justify-center rounded-2xl bg-brand-subtle text-brand">
                <ChannelIcon type={channel.type} className="size-6" />
            </span>

            <h2 className="text-2xl font-bold tracking-tight text-balance">
                This is the start of #{channel.slug}
            </h2>

            <p className="mt-2 max-w-prose text-sm text-pretty text-muted-foreground">
                {channel.description ??
                    'Add a description so people know what this channel is for.'}
            </p>
        </div>
    );
}

/**
 * What someone previewing a channel sees instead of a composer.
 */
function ChannelPreviewBar({ channel }: { channel: Channel }) {
    const workspace = useWorkspace();

    return (
        <div className="flex flex-wrap items-center justify-between gap-3 border-t border-border bg-muted/40 px-4 py-3">
            <p className="text-sm text-muted-foreground">
                You are previewing #{channel.slug}.
            </p>

            {!workspace.abilities.is_guest && !channel.is_archived && (
                <Button asChild size="sm">
                    <Link
                        href={joinChannel({
                            workspace: workspace.slug,
                            channel: channel.slug,
                        })}
                        method="post"
                        as="button"
                    >
                        Join channel
                    </Link>
                </Button>
            )}
        </div>
    );
}

/**
 * Channel details for the right panel.
 *
 * The roster is a deferred Inertia prop, so the channel paints immediately and
 * the member list streams in behind a skeleton.
 */
function ChannelDetails({
    channel,
    members,
}: {
    channel: Channel;
    members?: SnackUser[];
}) {
    return (
        <div className="space-y-5 p-4">
            <section>
                <h3 className="mb-1 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                    Description
                </h3>
                <p className="text-sm text-pretty">
                    {channel.description ?? 'No description yet.'}
                </p>
            </section>

            <section>
                <h3 className="mb-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                    Members
                </h3>

                <Deferred
                    data="members"
                    fallback={
                        <ul className="space-y-2">
                            {Array.from({ length: 5 }).map((_, index) => (
                                <li
                                    key={index}
                                    className="flex animate-pulse items-center gap-2"
                                >
                                    <Skeleton className="size-6 rounded-lg" />
                                    <Skeleton className="h-4 w-32" />
                                </li>
                            ))}
                        </ul>
                    }
                >
                    <ul className="space-y-0.5">
                        {(members ?? []).map((member) => (
                            <li
                                key={member.id}
                                className="flex items-center gap-2 rounded-lg px-1.5 py-1.5 hover:bg-accent"
                            >
                                <UserAvatar
                                    user={member}
                                    size="sm"
                                    showPresence
                                />
                                <span className="min-w-0 flex-1 truncate text-sm">
                                    {member.name}
                                </span>
                            </li>
                        ))}
                    </ul>
                </Deferred>
            </section>
        </div>
    );
}
