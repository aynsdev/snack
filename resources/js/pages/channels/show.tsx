import { Deferred, Head, Link } from '@inertiajs/react';
import { Info, Settings, UserPlus, Users } from 'lucide-react';
import { useMemo, useState } from 'react';
import { AddChannelMembersDialog } from '@/components/snack/add-channel-members-dialog';
import { ChannelIcon } from '@/components/snack/channel-icon';
import { ChannelSettingsDialog } from '@/components/snack/channel-settings-dialog';
import { ContainerCanvasTabs } from '@/components/snack/container-canvas-tabs';
import { HuddleBar } from '@/components/snack/huddle/huddle-bar';
import { HuddleButton } from '@/components/snack/huddle/huddle-button';
import type { MentionSource } from '@/components/snack/mention-picker';
import { MessageComposer } from '@/components/snack/message-composer';
import { MessageList } from '@/components/snack/message-list';
import { PinnedBar } from '@/components/snack/pinned-bar';
import { RemoveChannelMemberButton } from '@/components/snack/remove-channel-member-button';
import { RightPanel } from '@/components/snack/right-panel';
import { ThreadPanel } from '@/components/snack/thread-panel';
import { UserAvatar } from '@/components/snack/user-avatar';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { useBiteReplying } from '@/hooks/use-bite-replying';
import { useChannelMessages } from '@/hooks/use-channel-messages';
import { useContainerHuddle } from '@/hooks/use-container-huddle';
import type { HuddleTarget } from '@/hooks/use-huddle';
import { useMessageAlert } from '@/hooks/use-message-alert';
import { usePins } from '@/hooks/use-pins';
import { useTyping } from '@/hooks/use-typing';
import { useCurrentUser, useWorkspace } from '@/hooks/use-workspace';
import { channels } from '@/lib/echo';
import { show as showChannel } from '@/routes/channels';
import { store as storeChannelCanvas } from '@/routes/channels/canvases';
import { store as joinChannel } from '@/routes/channels/membership';
import { index as mentionable } from '@/routes/channels/mentionable';
import { store as messageStore } from '@/routes/channels/messages';
import type {
    Channel,
    ChannelMembership,
    CanvasDocument,
    Huddle as HuddleType,
    Message,
    MessagePage,
} from '@/types';

type Props = {
    channel: Channel;
    canPost: boolean;
    canCreateCanvas: boolean;
    canvases: CanvasDocument[];
    canManage: boolean;
    canPin: boolean;
    /**
     * Whether the Huddle control renders. Folds "may this person hold a call
     * here" and "does this deployment have a media server" into one flag — see
     * App\Policies\HuddlePolicy.
     */
    canHuddle: boolean;
    /** Ships with the page so the bar does not appear a beat late. */
    pinned: Message[];
    /** The live huddle, if there is one. Ships for the same reason pins do. */
    huddle: HuddleType | null;
    /** The newest page; older pages are fetched as the reader scrolls. */
    history: MessagePage;
    /** Deferred: streams in after the channel paints. */
    members?: ChannelMembership[];
};

export default function ChannelShow({
    channel,
    canPost,
    canCreateCanvas,
    canvases,
    canManage,
    canPin,
    canHuddle,
    pinned,
    huddle,
    history,
    members,
}: Props) {
    const workspace = useWorkspace();
    const currentUser = useCurrentUser();

    /*
     * One right panel at a time, so the two are a single piece of state rather
     * than two booleans that can both be true. Opening a thread while the
     * details panel is open replaces it, which is what the layout can actually
     * show — there is only one column.
     */
    const [panel, setPanel] = useState<
        { kind: 'details' } | { kind: 'thread'; rootId: number } | null
    >(null);

    const [editing, setEditing] = useState(false);
    const [addingPeople, setAddingPeople] = useState(false);

    /*
     * Memoized for the same reason `endpoints` is in use-channel-messages: an
     * object rebuilt every render gives every hook that depends on it a fresh
     * identity, which is how the mention picker ended up re-fetching in a loop.
     *
     * Built here rather than in the timeline because the thread panel's
     * composer needs the same roster — a reply can @mention as well.
     */
    const mentionSource = useMemo<MentionSource>(
        () => ({
            kind: 'remote',
            url: (term) =>
                mentionable.url(
                    { workspace: workspace.slug, channel: channel.slug },
                    { query: { q: term } },
                ),
        }),
        [workspace.slug, channel.slug],
    );

    /*
     * Memoized for the reason `mentionSource` above is: an object rebuilt every
     * render gives every hook depending on it a fresh identity, and this one is
     * read by the huddle provider's callbacks.
     */
    const huddleTarget = useMemo<HuddleTarget>(
        () => ({
            kind: 'channel',
            workspace: workspace.slug,
            channel: channel.slug,
            id: channel.id,
            label: `#${channel.slug}`,
        }),
        [workspace.slug, channel.slug, channel.id],
    );

    /*
     * The server's answer, kept current by the container's broadcast. Keyed on
     * the channel id so navigating between channels re-seeds it — see
     * useContainerHuddle, and the `useState(prop)` trap it exists to avoid.
     */
    const liveHuddle = useContainerHuddle({
        broadcastChannel: channels.channel(channel.id),
        containerKey: `channel:${channel.id}`,
        initial: huddle,
    });

    return (
        <>
            <Head title={`#${channel.slug}`} />

            <div className="flex min-w-0 flex-1 flex-col">
                <ChannelHeader
                    channel={channel}
                    canManage={canManage}
                    canHuddle={canHuddle}
                    huddleTarget={huddleTarget}
                    onToggleDetails={() =>
                        setPanel((current) =>
                            current?.kind === 'details'
                                ? null
                                : { kind: 'details' },
                        )
                    }
                    onEdit={() => setEditing(true)}
                />

                <ContainerCanvasTabs
                    canvases={canvases}
                    canCreate={canCreateCanvas}
                    menuLabel="Add to this channel"
                    createUrl={storeChannelCanvas.url({
                        workspace: workspace.slug,
                        channel: channel.slug,
                    })}
                />

                {/*
                 * Above the timeline rather than inside it: a huddle belongs to
                 * the room, not to a point in its history, and it must stay put
                 * while the conversation scrolls underneath.
                 */}
                <HuddleBar
                    huddle={liveHuddle}
                    target={huddleTarget}
                    // Joining is a contribution, like posting — so it is offered
                    // on exactly the terms the composer is.
                    canJoin={canHuddle && canPost && channel.is_member}
                />

                {canManage && (
                    <>
                        <ChannelSettingsDialog
                            channel={channel}
                            workspaceSlug={workspace.slug}
                            canAdministerWorkspace={
                                workspace.abilities.administer
                            }
                            open={editing}
                            onOpenChange={setEditing}
                        />

                        <AddChannelMembersDialog
                            channel={channel}
                            workspaceSlug={workspace.slug}
                            members={members}
                            open={addingPeople}
                            onOpenChange={setAddingPeople}
                        />
                    </>
                )}

                {/*
                 * Keyed by channel so switching channels remounts the timeline.
                 * Without it the hook would carry one channel's messages,
                 * cursor, and pending sends into the next.
                 */}
                <ChannelTimeline
                    key={channel.id}
                    channel={channel}
                    canPost={canPost}
                    canPin={canPin}
                    pinned={pinned}
                    history={history}
                    mentionSource={mentionSource}
                    onOpenThread={(message) =>
                        setPanel({ kind: 'thread', rootId: message.id })
                    }
                />
            </div>

            {panel?.kind === 'details' && (
                <RightPanel
                    title="About this channel"
                    onClose={() => setPanel(null)}
                >
                    <ChannelDetails
                        channel={channel}
                        members={members}
                        canManage={canManage}
                        currentUserId={currentUser?.id ?? null}
                        workspaceSlug={workspace.slug}
                        onAddPeople={() => setAddingPeople(true)}
                    />
                </RightPanel>
            )}

            {panel?.kind === 'thread' && (
                /*
                 * Keyed by root so opening a different thread remounts the
                 * panel rather than leaving one thread's replies on screen
                 * while another loads.
                 */
                <ThreadPanel
                    key={panel.rootId}
                    rootId={panel.rootId}
                    workspaceSlug={workspace.slug}
                    sendUrl={messageStore.url({
                        workspace: workspace.slug,
                        channel: channel.slug,
                    })}
                    containerName={`#${channel.slug}`}
                    mentionSource={mentionSource}
                    currentUser={currentUser}
                    canPost={canPost && channel.is_member}
                    onClose={() => setPanel(null)}
                />
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
    canPin,
    pinned,
    history,
    mentionSource,
    onOpenThread,
}: {
    channel: Channel;
    canPost: boolean;
    canPin: boolean;
    pinned: Message[];
    history: MessagePage;
    mentionSource: MentionSource;
    onOpenThread: (message: Message) => void;
}) {
    const workspace = useWorkspace();
    const currentUser = useCurrentUser();
    const alert = useMessageAlert({
        enabled: !channel.is_muted && channel.notification_level !== 'nothing',
        title: (senderName) => `${senderName} in #${channel.name}`,
        tag: `channel:${channel.id}`,
        href: showChannel.url({
            workspace: workspace.slug,
            channel: channel.slug,
        }),
    });

    const timeline = useChannelMessages({
        channel,
        workspaceSlug: workspace.slug,
        initial: history,
        currentUser,
        onMessageReceived: alert,
    });

    const typing = useTyping({
        channelName: channels.channel(channel.id),
        currentUserId: currentUser?.id ?? null,
        currentUserName: currentUser?.name ?? null,
    });

    const biteReplying = useBiteReplying({
        channelName: channels.channel(channel.id),
    });

    const pins = usePins({
        broadcastChannel: channels.channel(channel.id),
        workspaceSlug: workspace.slug,
        initial: pinned,
        onSaveChanged: timeline.applySaved,
    });

    const quietly = (work: Promise<unknown>) =>
        void work.catch(() => undefined);

    return (
        <>
            <PinnedBar
                pinned={pins.pinned}
                onUnpin={
                    canPin
                        ? (message) => quietly(pins.togglePin(message))
                        : undefined
                }
            />

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
                onOpenThread={onOpenThread}
                /*
                 * No reacting from a channel preview: reacting is a
                 * contribution, and MessagePolicy::react resolves to posting
                 * rights. Hiding the control matches what the server would
                 * answer rather than offering a button that 403s.
                 */
                onReact={
                    canPost && channel.is_member
                        ? (id, emoji, current) =>
                              void timeline
                                  .react(id, emoji, current)
                                  .catch(() => undefined)
                        : undefined
                }
                onTogglePin={
                    canPin
                        ? (message) => quietly(pins.togglePin(message))
                        : undefined
                }
                // Saving needs only read access, so it is offered even while
                // previewing a channel nobody has joined.
                onToggleSave={(message) => quietly(pins.toggleSave(message))}
                header={<ChannelIntro channel={channel} />}
            />

            {channel.is_member ? (
                <MessageComposer
                    channelName={`#${channel.slug}`}
                    // A channel's roster is not in the page — only its count —
                    // so the picker asks the server, scoped to this channel.
                    mentionSource={mentionSource}
                    typists={typing.typists}
                    replying={biteReplying}
                    workspaceSlug={workspace.slug}
                    onTyping={typing.startTyping}
                    onStopTyping={typing.stopTyping}
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
    canHuddle,
    huddleTarget,
    onToggleDetails,
    onEdit,
}: {
    channel: Channel;
    canManage: boolean;
    canHuddle: boolean;
    huddleTarget: HuddleTarget;
    onToggleDetails: () => void;
    onEdit: () => void;
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
                <HuddleButton
                    target={huddleTarget}
                    container={{ kind: 'channel', id: channel.id }}
                    canHuddle={canHuddle}
                />

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

                {/*
                 * Separate from the details panel: that is a read-only roster
                 * anyone can open, this changes the channel for everybody and
                 * only renders for people who may.
                 */}
                {canManage && (
                    <button
                        type="button"
                        onClick={onEdit}
                        className="rounded-lg p-2 text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                    >
                        <Settings aria-hidden="true" className="size-4" />
                        <span className="sr-only">Channel settings</span>
                    </button>
                )}

                <button
                    type="button"
                    onClick={onToggleDetails}
                    className="rounded-lg p-2 text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                >
                    <Info aria-hidden="true" className="size-4" />
                    <span className="sr-only">Channel details</span>
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
    canManage,
    currentUserId,
    workspaceSlug,
    onAddPeople,
}: {
    channel: Channel;
    members?: ChannelMembership[];
    canManage: boolean;
    currentUserId: number | null;
    workspaceSlug: string;
    onAddPeople: () => void;
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

                {/*
                 * Beside the roster rather than in the header: this is the one
                 * place the answer to "who is already in here?" is on screen,
                 * which is the question you ask right before adding somebody.
                 * An archived channel takes no new members, and the server
                 * refuses regardless.
                 */}
                {canManage && !channel.is_archived && (
                    <button
                        type="button"
                        onClick={onAddPeople}
                        className="mb-2 flex w-full items-center gap-2 rounded-lg px-1.5 py-1.5 text-left text-sm text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                    >
                        <span className="flex size-6 items-center justify-center rounded-lg bg-muted">
                            <UserPlus aria-hidden="true" className="size-3.5" />
                        </span>
                        Add people
                    </button>
                )}

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
                        {(members ?? []).map((membership) => (
                            <li
                                key={membership.id}
                                className="flex items-center gap-2 rounded-lg px-1.5 py-1.5 hover:bg-accent"
                            >
                                <UserAvatar
                                    user={membership.user}
                                    size="sm"
                                    showPresence
                                />
                                <span className="min-w-0 flex-1 truncate text-sm">
                                    {membership.user.name}
                                </span>
                                {canManage &&
                                    !channel.is_archived &&
                                    !channel.is_default &&
                                    membership.user.id !== currentUserId && (
                                        <RemoveChannelMemberButton
                                            channel={channel}
                                            membership={membership}
                                            workspaceSlug={workspaceSlug}
                                        />
                                    )}
                            </li>
                        ))}
                    </ul>
                </Deferred>
            </section>
        </div>
    );
}
