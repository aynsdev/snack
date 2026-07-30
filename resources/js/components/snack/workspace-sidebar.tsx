import { Link } from '@inertiajs/react';
import {
    AtSign,
    Bookmark,
    ChevronDown,
    Compass,
    MessagesSquare,
    Plus,
    Search,
    Settings,
    SquarePen,
    UsersRound,
} from 'lucide-react';
import { useState } from 'react';
import { ChannelListItem } from '@/components/snack/channel-list-item';
import { ConversationListItem } from '@/components/snack/conversation-list-item';
import { NewMessageDialog } from '@/components/snack/new-message-dialog';
import { NotificationPermissionPrompt } from '@/components/snack/notification-permission-prompt';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    useActiveChannelSlug,
    useActiveConversationId,
} from '@/hooks/use-active-channel';
import {
    useCurrentUser,
    useCurrentWorkspace,
    useSidebarChannels,
    useSidebarConversations,
    useUnreadState,
    useWorkspace,
} from '@/hooks/use-workspace';
import { cn } from '@/lib/utils';
import { index as browseChannels } from '@/routes/channels';
import { index as workspaceMembers } from '@/routes/members';
import { index as workspaceInvitations } from '@/routes/workspace-invitations';
import type { Channel, Conversation } from '@/types';

/**
 * The second column: workspace identity, global navigation, and the channel
 * list.
 *
 * Rendered inside a drawer on small screens by {@see WorkspaceLayout}, which is
 * why it takes its width from the parent rather than setting its own.
 */
export function WorkspaceSidebar({
    onNavigate,
}: {
    /** Lets the mobile drawer close itself when a link is followed. */
    onNavigate?: () => void;
}) {
    const workspace = useWorkspace();
    const current = useCurrentWorkspace();
    const currentUser = useCurrentUser();
    const { channels, muted } = useSidebarChannels();
    const { conversations, muted: mutedConversations } =
        useSidebarConversations();
    const unread = useUnreadState();
    const activeChannelSlug = useActiveChannelSlug();
    const activeConversationId = useActiveConversationId();

    const [composing, setComposing] = useState(false);

    return (
        <div className="flex h-full flex-col bg-sidebar text-sidebar-foreground">
            <SidebarHeader onCompose={() => setComposing(true)} />

            <NewMessageDialog
                workspaceSlug={workspace.slug}
                open={composing}
                onOpenChange={setComposing}
            />

            <div className="px-3 pb-2">
                <SearchButton />
            </div>

            <nav
                aria-label="Workspace"
                className="snack-scroll flex-1 space-y-4 overflow-y-auto px-3 pb-4"
            >
                <ul className="space-y-0.5">
                    <SidebarLink
                        icon={MessagesSquare}
                        label="Threads"
                        badge={unread.threads}
                        // Threads get their own view in Phase 4; the entry point
                        // is present so the sidebar's shape is settled.
                        disabled
                    />
                    <SidebarLink
                        icon={AtSign}
                        label="Mentions"
                        badge={unread.mentions}
                        disabled
                    />
                    <SidebarLink icon={Bookmark} label="Saved" disabled />
                </ul>

                <SidebarSection
                    title="Channels"
                    action={
                        current?.workspace.abilities.create_channels ? (
                            <Link
                                href={browseChannels(workspace.slug)}
                                onClick={onNavigate}
                                className="rounded-md p-1 hover:bg-sidebar-accent"
                            >
                                <Plus aria-hidden="true" className="size-4" />
                                <span className="sr-only">
                                    Add or browse channels
                                </span>
                            </Link>
                        ) : null
                    }
                >
                    {channels.length === 0 ? (
                        <p className="px-2 py-1.5 text-xs text-muted-foreground">
                            You have not joined any channels yet.
                        </p>
                    ) : (
                        <ul className="space-y-0.5">
                            {channels.map((channel) => (
                                <li key={channel.id}>
                                    <ChannelRow
                                        channel={channel}
                                        workspaceSlug={workspace.slug}
                                        isActive={
                                            channel.slug === activeChannelSlug
                                        }
                                        onNavigate={onNavigate}
                                    />
                                </li>
                            ))}
                        </ul>
                    )}

                    {!workspace.abilities.is_guest && (
                        <Link
                            href={browseChannels(workspace.slug)}
                            onClick={onNavigate}
                            className="mt-0.5 flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm text-muted-foreground transition-colors hover:bg-sidebar-accent"
                        >
                            <Compass aria-hidden="true" className="size-4" />
                            Browse channels
                        </Link>
                    )}
                </SidebarSection>

                <SidebarSection
                    title="Direct messages"
                    action={
                        workspace.abilities.start_conversations ? (
                            <button
                                type="button"
                                onClick={() => setComposing(true)}
                                className="rounded-md p-1 hover:bg-sidebar-accent"
                            >
                                <Plus aria-hidden="true" className="size-4" />
                                <span className="sr-only">
                                    Start a new message
                                </span>
                            </button>
                        ) : null
                    }
                >
                    {conversations.length === 0 ? (
                        <p className="px-2 py-1.5 text-xs text-muted-foreground">
                            {workspace.abilities.start_conversations
                                ? 'No conversations yet. Start one with the + above.'
                                : 'You have no direct messages.'}
                        </p>
                    ) : (
                        <ul className="space-y-0.5">
                            {conversations.map((conversation) => (
                                <li key={conversation.id}>
                                    <ConversationRow
                                        conversation={conversation}
                                        workspaceSlug={workspace.slug}
                                        viewerId={currentUser?.id ?? null}
                                        isActive={
                                            conversation.id ===
                                            activeConversationId
                                        }
                                        onNavigate={onNavigate}
                                    />
                                </li>
                            ))}
                        </ul>
                    )}
                </SidebarSection>

                {(muted.length > 0 || mutedConversations.length > 0) && (
                    <SidebarSection title="Muted" defaultOpen={false}>
                        <ul className="space-y-0.5">
                            {muted.map((channel) => (
                                <li key={`channel-${channel.id}`}>
                                    <ChannelRow
                                        channel={channel}
                                        workspaceSlug={workspace.slug}
                                        isActive={
                                            channel.slug === activeChannelSlug
                                        }
                                        onNavigate={onNavigate}
                                    />
                                </li>
                            ))}

                            {mutedConversations.map((conversation) => (
                                <li key={`conversation-${conversation.id}`}>
                                    <ConversationRow
                                        conversation={conversation}
                                        workspaceSlug={workspace.slug}
                                        viewerId={currentUser?.id ?? null}
                                        isActive={
                                            conversation.id ===
                                            activeConversationId
                                        }
                                        onNavigate={onNavigate}
                                    />
                                </li>
                            ))}
                        </ul>
                    </SidebarSection>
                )}
            </nav>

            <NotificationPermissionPrompt />
        </div>
    );
}

function ChannelRow({
    channel,
    workspaceSlug,
    isActive,
    onNavigate,
}: {
    channel: Channel;
    workspaceSlug: string;
    isActive: boolean;
    onNavigate?: () => void;
}) {
    const unread = useUnreadState();
    const counts = unread.channels[channel.id];

    return (
        <div onClick={onNavigate}>
            <ChannelListItem
                channel={channel}
                workspaceSlug={workspaceSlug}
                isActive={isActive}
                unreadMessages={counts?.messages ?? 0}
                unreadMentions={counts?.mentions ?? 0}
            />
        </div>
    );
}

function ConversationRow({
    conversation,
    workspaceSlug,
    viewerId,
    isActive,
    onNavigate,
}: {
    conversation: Conversation;
    workspaceSlug: string;
    viewerId: number | null;
    isActive: boolean;
    onNavigate?: () => void;
}) {
    const unread = useUnreadState();
    const counts = unread.conversations[conversation.id];

    return (
        <div onClick={onNavigate}>
            <ConversationListItem
                conversation={conversation}
                workspaceSlug={workspaceSlug}
                viewerId={viewerId}
                isActive={isActive}
                unreadMessages={counts?.messages ?? 0}
            />
        </div>
    );
}

function SidebarHeader({ onCompose }: { onCompose: () => void }) {
    const workspace = useWorkspace();

    return (
        <div className="flex items-center justify-between gap-2 px-3 py-3">
            <DropdownMenu>
                <DropdownMenuTrigger className="-ml-1 flex min-w-0 items-center gap-1.5 rounded-lg px-2 py-1.5 transition-colors hover:bg-sidebar-accent">
                    <span className="truncate text-[0.9375rem] font-bold tracking-tight">
                        {workspace.name}
                    </span>
                    <ChevronDown
                        aria-hidden="true"
                        className="size-4 shrink-0 opacity-60"
                    />
                    <span className="sr-only">Workspace menu</span>
                </DropdownMenuTrigger>

                <DropdownMenuContent align="start" className="w-64">
                    <DropdownMenuItem asChild>
                        <Link href={workspaceMembers(workspace.slug)}>
                            <UsersRound className="mr-2 size-4" />
                            Members
                        </Link>
                    </DropdownMenuItem>

                    {workspace.abilities.manage_members && (
                        <DropdownMenuItem asChild>
                            <Link href={workspaceInvitations(workspace.slug)}>
                                <Plus className="mr-2 size-4" />
                                Invite people
                            </Link>
                        </DropdownMenuItem>
                    )}

                    {workspace.abilities.administer && (
                        <>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem disabled>
                                <Settings className="mr-2 size-4" />
                                Workspace settings
                            </DropdownMenuItem>
                        </>
                    )}
                </DropdownMenuContent>
            </DropdownMenu>

            <button
                type="button"
                onClick={onCompose}
                disabled={!workspace.abilities.start_conversations}
                title={
                    workspace.abilities.start_conversations
                        ? 'New message'
                        : 'Guests cannot start conversations'
                }
                className="rounded-lg p-2 transition-colors hover:bg-sidebar-accent disabled:opacity-40"
            >
                <SquarePen aria-hidden="true" className="size-[1.125rem]" />
                <span className="sr-only">New message</span>
            </button>
        </div>
    );
}

function SearchButton() {
    return (
        <button
            type="button"
            disabled
            title="Search — arriving in Phase 5"
            className={cn(
                'flex w-full items-center gap-2 rounded-lg border border-sidebar-border bg-background/60 px-2.5 py-2 text-sm text-muted-foreground',
                'transition-colors hover:bg-background disabled:opacity-60',
            )}
        >
            <Search aria-hidden="true" className="size-4" />
            <span className="flex-1 text-left">Search</span>
            <kbd className="rounded bg-muted px-1.5 py-0.5 font-sans text-[0.6875rem] font-medium text-muted-foreground">
                ⌘K
            </kbd>
        </button>
    );
}

function SidebarSection({
    title,
    action,
    defaultOpen = true,
    children,
}: {
    title: string;
    action?: React.ReactNode;
    defaultOpen?: boolean;
    children: React.ReactNode;
}) {
    const [open, setOpen] = useState(defaultOpen);

    return (
        <Collapsible open={open} onOpenChange={setOpen}>
            <div className="flex items-center justify-between gap-1 pr-1">
                <CollapsibleTrigger className="flex items-center gap-1 rounded-md px-2 py-1 text-xs font-semibold tracking-wide text-muted-foreground uppercase transition-colors hover:text-foreground">
                    <ChevronDown
                        aria-hidden="true"
                        className={cn(
                            'size-3.5 transition-transform',
                            !open && '-rotate-90',
                        )}
                    />
                    {title}
                </CollapsibleTrigger>

                {action}
            </div>

            <CollapsibleContent className="mt-0.5">
                {children}
            </CollapsibleContent>
        </Collapsible>
    );
}

function SidebarLink({
    icon: Icon,
    label,
    badge = 0,
    disabled = false,
}: {
    icon: typeof AtSign;
    label: string;
    badge?: number;
    disabled?: boolean;
}) {
    return (
        <li>
            <button
                type="button"
                disabled={disabled}
                title={
                    disabled
                        ? `${label} — arriving in a later phase`
                        : undefined
                }
                className={cn(
                    'flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-sm transition-colors',
                    'text-muted-foreground hover:bg-sidebar-accent hover:text-foreground',
                    'disabled:pointer-events-none disabled:opacity-50',
                )}
            >
                <Icon aria-hidden="true" className="size-4" />
                <span className="flex-1 text-left">{label}</span>

                {badge > 0 && (
                    <span className="min-w-5 rounded-full bg-mention px-1.5 py-0.5 text-center text-[0.6875rem] leading-none font-bold text-mention-foreground tabular-nums">
                        {badge > 99 ? '99+' : badge}
                    </span>
                )}
            </button>
        </li>
    );
}
