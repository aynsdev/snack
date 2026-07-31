import { Head, router } from '@inertiajs/react';
import {
    Bell,
    BellOff,
    LogOut,
    MoreVertical,
    Pencil,
    UserPlus,
    Users,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { AddPeopleDialog } from '@/components/snack/add-people-dialog';
import type { MentionSource } from '@/components/snack/mention-picker';
import { MessageComposer } from '@/components/snack/message-composer';
import { MessageList } from '@/components/snack/message-list';
import { PinnedBar } from '@/components/snack/pinned-bar';
import { PresenceDot } from '@/components/snack/presence-dot';
import { RenameConversationDialog } from '@/components/snack/rename-conversation-dialog';
import { RightPanel } from '@/components/snack/right-panel';
import { ThreadPanel } from '@/components/snack/thread-panel';
import { AvatarGroup, UserAvatar } from '@/components/snack/user-avatar';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useConversationMessages } from '@/hooks/use-conversation-messages';
import { useMessageAlert } from '@/hooks/use-message-alert';
import { usePins } from '@/hooks/use-pins';
import { useTyping } from '@/hooks/use-typing';
import { useCurrentUser, useWorkspace } from '@/hooks/use-workspace';
import { channels } from '@/lib/echo';
import { show as showConversation } from '@/routes/conversations';
import { store as conversationMessageStore } from '@/routes/conversations/messages';
import { destroy as leaveConversation } from '@/routes/conversations/participants';
import { update as updatePreferences } from '@/routes/conversations/preferences';
import type { Conversation, Message, MessagePage, SnackUser } from '@/types';

type Props = {
    conversation: Conversation;
    canPost: boolean;
    canManage: boolean;
    canAddPeople: boolean;
    canLeave: boolean;
    canPin: boolean;
    /** Ships with the page so the bar does not appear a beat late. */
    pinned: Message[];
    /** The newest page; older pages are fetched as the reader scrolls. */
    history: MessagePage;
};

export default function ConversationShow({
    conversation,
    canPost,
    canManage,
    canAddPeople,
    canLeave,
    canPin,
    pinned,
    history,
}: Props) {
    const workspace = useWorkspace();
    const currentUser = useCurrentUser();

    /*
     * One right panel at a time — the layout has a single column for it, so two
     * booleans could describe a state it cannot render.
     */
    const [panel, setPanel] = useState<
        { kind: 'details' } | { kind: 'thread'; rootId: number } | null
    >(null);

    // Memoized so the mention picker's source keeps a stable identity; see the
    // note in channels/show.tsx.
    const others = useMemo(
        () =>
            conversation.participants.filter(
                (participant) => participant.id !== currentUser?.id,
            ),
        [conversation.participants, currentUser?.id],
    );

    return (
        <>
            <Head title={conversation.display_name} />

            <div className="flex min-w-0 flex-1 flex-col">
                <ConversationHeader
                    conversation={conversation}
                    others={others}
                    canManage={canManage}
                    canAddPeople={canAddPeople}
                    canLeave={canLeave}
                    onToggleDetails={() =>
                        setPanel((current) =>
                            current?.kind === 'details'
                                ? null
                                : { kind: 'details' },
                        )
                    }
                />

                {/*
                 * Keyed by conversation so switching remounts the timeline.
                 * Without it the hook would carry one conversation's messages,
                 * cursor, and pending sends into the next.
                 */}
                <ConversationTimeline
                    key={conversation.id}
                    conversation={conversation}
                    canPost={canPost}
                    canPin={canPin}
                    pinned={pinned}
                    history={history}
                    others={others}
                    onOpenThread={(message) =>
                        setPanel({ kind: 'thread', rootId: message.id })
                    }
                />
            </div>

            {panel?.kind === 'details' && (
                <RightPanel
                    title={
                        conversation.type === 'group'
                            ? 'About this group'
                            : 'About this conversation'
                    }
                    onClose={() => setPanel(null)}
                >
                    <ConversationDetails conversation={conversation} />
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
                    sendUrl={conversationMessageStore.url({
                        workspace: workspace.slug,
                        conversation: conversation.id,
                    })}
                    containerName={conversation.display_name}
                    currentUser={currentUser}
                    canPost={canPost}
                    onClose={() => setPanel(null)}
                />
            )}
        </>
    );
}

/**
 * The live part of a conversation: history, real-time arrivals, and the composer.
 *
 * Split from the page so it can be remounted per conversation while the header
 * above it is re-rendered rather than rebuilt.
 */
function ConversationTimeline({
    conversation,
    canPost,
    canPin,
    pinned,
    history,
    others,
    onOpenThread,
}: {
    conversation: Conversation;
    canPost: boolean;
    canPin: boolean;
    pinned: Message[];
    history: MessagePage;
    others: SnackUser[];
    onOpenThread: (message: Message) => void;
}) {
    const workspace = useWorkspace();
    const currentUser = useCurrentUser();
    const alert = useMessageAlert({
        enabled:
            !conversation.is_muted &&
            conversation.notification_level !== 'nothing',
        // A direct conversation is titled by the other person, so naming the
        // container as well would read "Ada Lovelace in Ada Lovelace".
        title: (senderName) =>
            conversation.type === 'direct'
                ? senderName
                : `${senderName} in ${conversation.display_name}`,
        tag: `conversation:${conversation.id}`,
        href: showConversation.url({
            workspace: workspace.slug,
            conversation: conversation.id,
        }),
    });

    const timeline = useConversationMessages({
        conversation,
        workspaceSlug: workspace.slug,
        initial: history,
        currentUser,
        onMessageReceived: alert,
    });

    const typing = useTyping({
        channelName: channels.conversation(conversation.id),
        currentUserId: currentUser?.id ?? null,
        currentUserName: currentUser?.name ?? null,
    });

    const mentionSource = useMemo<MentionSource>(
        () => ({ kind: 'local', people: others }),
        [others],
    );

    const pins = usePins({
        broadcastChannel: channels.conversation(conversation.id),
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
                // Someone who has left a group keeps read access but no
                // posting rights, and reacting needs posting rights.
                onReact={
                    canPost
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
                onToggleSave={(message) => quietly(pins.toggleSave(message))}
                header={
                    <ConversationIntro
                        conversation={conversation}
                        others={others}
                    />
                }
            />

            {canPost ? (
                <MessageComposer
                    channelName={conversation.display_name}
                    /*
                     * The roster is already in props, so this filters in memory.
                     * A round trip per keystroke to re-filter four names would
                     * be slower than not having the picker at all.
                     */
                    mentionSource={mentionSource}
                    typists={typing.typists}
                    onTyping={typing.startTyping}
                    onStopTyping={typing.stopTyping}
                    onSend={timeline.send}
                />
            ) : (
                <div className="border-t border-border bg-muted/40 px-4 py-3">
                    <p className="text-sm text-muted-foreground">
                        {conversation.has_left
                            ? 'You left this conversation. You can still read what was said while you were here.'
                            : 'You cannot post in this conversation.'}
                    </p>
                </div>
            )}
        </>
    );
}

function ConversationHeader({
    conversation,
    others,
    canManage,
    canAddPeople,
    canLeave,
    onToggleDetails,
}: {
    conversation: Conversation;
    others: SnackUser[];
    canManage: boolean;
    canAddPeople: boolean;
    canLeave: boolean;
    onToggleDetails: () => void;
}) {
    const workspace = useWorkspace();
    const [renaming, setRenaming] = useState(false);
    const [addingPeople, setAddingPeople] = useState(false);

    const routeArgs = {
        workspace: workspace.slug,
        conversation: conversation.id,
    };

    /*
     * A one-to-one conversation is a person, so it gets their presence and their
     * status. A group is not a person: "is this group away?" is not a question,
     * so it gets a headcount instead.
     */
    const counterpart = others.length === 1 ? others[0] : null;

    const toggleMute = () => {
        router.patch(
            updatePreferences.url(routeArgs),
            { is_muted: !conversation.is_muted },
            { preserveScroll: true },
        );
    };

    return (
        <header className="flex items-center gap-3 border-b border-border px-4 py-3">
            <div className="flex min-w-0 items-center gap-2">
                {counterpart !== null ? (
                    <UserAvatar user={counterpart} size="sm" showPresence />
                ) : (
                    <AvatarGroup users={others} max={3} size="xs" />
                )}

                <h1 className="truncate text-base font-bold tracking-tight">
                    {conversation.display_name}
                </h1>

                {conversation.is_muted && (
                    <BellOff
                        aria-label="Muted"
                        className="size-3.5 shrink-0 text-muted-foreground"
                    />
                )}
            </div>

            {counterpart !== null ? (
                <>
                    <span
                        aria-hidden="true"
                        className="h-4 w-px shrink-0 bg-border"
                    />
                    <div className="flex min-w-0 flex-1 items-center gap-2">
                        <PresenceDot
                            status={counterpart.presence_status}
                            size="sm"
                            withLabel
                        />
                        {counterpart.status_message && (
                            <p className="min-w-0 truncate text-sm text-muted-foreground">
                                {counterpart.status_emoji}{' '}
                                {counterpart.status_message}
                            </p>
                        )}
                    </div>
                </>
            ) : (
                <div className="min-w-0 flex-1" />
            )}

            <div className="ml-auto flex shrink-0 items-center gap-1">
                <button
                    type="button"
                    onClick={onToggleDetails}
                    className="flex items-center gap-1.5 rounded-lg px-2 py-1.5 text-sm text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                >
                    <Users aria-hidden="true" className="size-4" />
                    <span className="tabular-nums">
                        {conversation.participants.length}
                    </span>
                    <span className="sr-only">participants</span>
                </button>

                <DropdownMenu>
                    <DropdownMenuTrigger className="rounded-lg p-2 text-muted-foreground transition-colors hover:bg-accent hover:text-foreground">
                        <MoreVertical aria-hidden="true" className="size-4" />
                        <span className="sr-only">Conversation options</span>
                    </DropdownMenuTrigger>

                    <DropdownMenuContent align="end" className="w-56">
                        <DropdownMenuItem onSelect={toggleMute}>
                            {conversation.is_muted ? (
                                <>
                                    <Bell className="mr-2 size-4" />
                                    Unmute conversation
                                </>
                            ) : (
                                <>
                                    <BellOff className="mr-2 size-4" />
                                    Mute conversation
                                </>
                            )}
                        </DropdownMenuItem>

                        {canAddPeople && (
                            <DropdownMenuItem
                                onSelect={() => setAddingPeople(true)}
                            >
                                <UserPlus className="mr-2 size-4" />
                                Add people
                            </DropdownMenuItem>
                        )}

                        {canManage && (
                            <DropdownMenuItem
                                onSelect={() => setRenaming(true)}
                            >
                                <Pencil className="mr-2 size-4" />
                                Rename conversation
                            </DropdownMenuItem>
                        )}

                        {canLeave && (
                            <>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem
                                    variant="destructive"
                                    onSelect={() =>
                                        router.delete(
                                            leaveConversation.url(routeArgs),
                                        )
                                    }
                                >
                                    <LogOut className="mr-2 size-4" />
                                    Leave conversation
                                </DropdownMenuItem>
                            </>
                        )}
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>

            {canManage && (
                <RenameConversationDialog
                    conversation={conversation}
                    workspaceSlug={workspace.slug}
                    open={renaming}
                    onOpenChange={setRenaming}
                />
            )}

            {canAddPeople && (
                <AddPeopleDialog
                    conversation={conversation}
                    workspaceSlug={workspace.slug}
                    open={addingPeople}
                    onOpenChange={setAddingPeople}
                />
            )}
        </header>
    );
}

/**
 * The masthead at the top of a conversation's history.
 *
 * Rendered only once the whole history is loaded, since "this is the beginning"
 * is a claim that is only true when there is nothing older left to fetch.
 */
function ConversationIntro({
    conversation,
    others,
}: {
    conversation: Conversation;
    others: SnackUser[];
}) {
    const counterpart = others.length === 1 ? others[0] : null;

    return (
        <div className="px-6 pt-10 pb-2">
            {counterpart !== null ? (
                <UserAvatar user={counterpart} size="xl" className="mb-4" />
            ) : (
                <AvatarGroup
                    users={others}
                    max={4}
                    size="lg"
                    className="mb-4"
                />
            )}

            <h2 className="text-2xl font-bold tracking-tight text-balance">
                {conversation.display_name}
            </h2>

            <p className="mt-2 max-w-prose text-sm text-pretty text-muted-foreground">
                {counterpart !== null
                    ? `This is the very beginning of your direct message history with ${counterpart.name}.`
                    : 'This is the very beginning of this group conversation.'}
            </p>
        </div>
    );
}

/**
 * Conversation details for the right panel.
 *
 * The roster arrives with the page rather than as a deferred prop: a conversation
 * has a handful of participants, not a channel's hundreds, and they are already
 * loaded to title the thread.
 */
function ConversationDetails({ conversation }: { conversation: Conversation }) {
    return (
        <div className="space-y-5 p-4">
            <section>
                <h3 className="mb-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                    {conversation.participants.length} participant
                    {conversation.participants.length === 1 ? '' : 's'}
                </h3>

                <ul className="space-y-0.5">
                    {conversation.participants.map((participant) => (
                        <li
                            key={participant.id}
                            className="flex items-center gap-2 rounded-lg px-1.5 py-1.5 hover:bg-accent"
                        >
                            <UserAvatar
                                user={participant}
                                size="sm"
                                showPresence
                            />

                            <span className="min-w-0 flex-1">
                                <span className="block truncate text-sm">
                                    {participant.name}
                                </span>
                                {participant.title && (
                                    <span className="block truncate text-xs text-muted-foreground">
                                        {participant.title}
                                    </span>
                                )}
                            </span>
                        </li>
                    ))}
                </ul>
            </section>

            <section>
                <h3 className="mb-1 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                    Notifications
                </h3>
                <p className="text-sm text-muted-foreground">
                    {conversation.is_muted
                        ? 'Muted. You will not be notified about new messages here.'
                        : 'You are notified about every message here.'}
                </p>
            </section>
        </div>
    );
}
