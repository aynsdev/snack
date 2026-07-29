import { Deferred, Head, Link } from '@inertiajs/react';
import { Info, MessageSquarePlus, Users } from 'lucide-react';
import { useState } from 'react';
import { ChannelIcon } from '@/components/snack/channel-icon';
import { EmptyState } from '@/components/snack/empty-state';
import { RightPanel } from '@/components/snack/right-panel';
import { UserAvatar } from '@/components/snack/user-avatar';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { useWorkspace } from '@/hooks/use-workspace';
import { store as joinChannel } from '@/routes/channels/membership';
import type { Channel, SnackUser } from '@/types';

type Props = {
    channel: Channel;
    canPost: boolean;
    canManage: boolean;
    /** Deferred: streams in after the channel paints. */
    members?: SnackUser[];
};

/**
 * A channel.
 *
 * Phase 1 ships the header, the details panel, and the empty timeline. The
 * message history, composer, and real-time subscription land in Phase 2 — the
 * layout is settled first so those drop in without reshaping anything.
 */
export default function ChannelShow({
    channel,
    canPost,
    canManage,
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

                <div className="snack-scroll min-h-0 flex-1 overflow-y-auto">
                    <ChannelIntro channel={channel} />

                    <EmptyState
                        icon={MessageSquarePlus}
                        title="No messages yet"
                        description={
                            canPost
                                ? 'This is the very beginning of the channel. Say something.'
                                : 'Messages will appear here once the conversation starts.'
                        }
                    />
                </div>

                <ChannelComposer channel={channel} canPost={canPost} />
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
 * The masthead at the top of a channel's history, explaining what the room is
 * for. It scrolls away with the messages rather than sitting fixed.
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
 * The composer's footprint, reserved in Phase 1.
 *
 * Holding the space now keeps the message list's scroll height stable when the
 * real composer lands, so Phase 2's "stick to bottom" behaviour has a fixed box
 * to measure against.
 */
function ChannelComposer({
    channel,
    canPost,
}: {
    channel: Channel;
    canPost: boolean;
}) {
    const workspace = useWorkspace();

    if (!channel.is_member) {
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

    return (
        <div className="border-t border-border p-4">
            <div className="rounded-xl border border-input px-3.5 py-3 text-sm text-muted-foreground">
                {canPost
                    ? `Message #${channel.slug} — the composer arrives in Phase 2.`
                    : 'Only workspace admins can post in an announcement channel.'}
            </div>
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
