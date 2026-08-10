import { Link, router } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import {
    Bell,
    ChevronDown,
    ClipboardList,
    Compass,
    Headphones,
    MessagesSquare,
    Plug,
    Plus,
    ScrollText,
    Search,
    Settings,
    SquarePen,
    Star,
    UsersRound,
    X,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { AssetSidebar } from '@/components/snack/assets/asset-sidebar';
import { ChannelListItem } from '@/components/snack/channel-list-item';
import {
    ChannelListMenu,
    ChannelSectionDropZone,
    ChannelSectionHeading,
    ChannelSectionPicker,
    ChannelSectionToggle,
} from '@/components/snack/channel-sections';
import { ConversationListItem } from '@/components/snack/conversation-list-item';
import { NewMessageDialog } from '@/components/snack/new-message-dialog';
import { NotificationPermissionPrompt } from '@/components/snack/notification-permission-prompt';
import { PersonListItem } from '@/components/snack/person-list-item';
import { ProjectAvatar } from '@/components/snack/projects/project-avatar';
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
    useActiveProjectSlug,
    useIsHuddlesView,
    useIsThreadsView,
} from '@/hooks/use-active-channel';
import { useCollapsedChannelSections } from '@/hooks/use-collapsed-channel-sections';
import { useSidebarSection } from '@/hooks/use-sidebar-section';
import type { ChannelSection } from '@/hooks/use-workspace';
import {
    useCurrentUser,
    useCurrentWorkspace,
    useSidebarChannels,
    useSidebarConversations,
    useSidebarPeople,
    useSidebarProjects,
    useUnreadState,
    useWorkspace,
} from '@/hooks/use-workspace';
import { cn } from '@/lib/utils';
import { index as auditIndex } from '@/routes/audit';
import { index as browseChannels } from '@/routes/channels';
import { index as huddlesIndex } from '@/routes/huddles';
import { index as workspaceMembers } from '@/routes/members';
import { edit as notificationPreferences } from '@/routes/notification-preferences';
import {
    board as projectBoard,
    index as projectsIndex,
} from '@/routes/projects';
import { index as searchIndex } from '@/routes/search';
import { index as standupsIndex } from '@/routes/standups';
import { index as threadsIndex } from '@/routes/threads';
import { index as webhooksIndex } from '@/routes/webhooks';
import { index as workspaceInvitations } from '@/routes/workspace-invitations';
import { edit as workspaceSettings } from '@/routes/workspace-settings';
import type {
    Channel,
    ChannelGroup,
    Conversation,
    Project,
    WorkspacePerson,
} from '@/types';

/**
 * The second column: workspace identity, global navigation, and the channel
 * list.
 *
 * Rendered inside a drawer on small screens by {@see WorkspaceLayout}, which is
 * why it takes its width from the parent rather than setting its own.
 */
export function WorkspaceSidebar({
    onNavigate,
    inDrawer = false,
}: {
    /** Lets the mobile drawer close itself when a link is followed. */
    onNavigate?: () => void;
    /**
     * Rendered inside the mobile drawer, whose own dismiss button sits in the
     * top-right corner — which is where this component's header would otherwise
     * put the compose button. Only the header cares.
     */
    inDrawer?: boolean;
}) {
    const workspace = useWorkspace();
    const current = useCurrentWorkspace();
    const currentUser = useCurrentUser();
    const {
        channels,
        muted,
        sections,
        groups: channelGroups,
    } = useSidebarChannels();
    const { mutedGroups } = useSidebarConversations();
    const unread = useUnreadState();
    const activeChannelSlug = useActiveChannelSlug();
    const activeConversationId = useActiveConversationId();
    const isThreadsView = useIsThreadsView();
    const isHuddlesView = useIsHuddlesView();

    /*
     * Inside direct messages the sidebar is the DM list and nothing else.
     * The rail on the left already said which part of the workspace you are in,
     * so repeating the channel list beside a conversation makes the reader scan
     * past everything they did not choose to find the row they did.
     *
     * The section rather than the URL: a conversation opened from the roster at
     * the bottom of *this* sidebar must leave this sidebar standing. See
     * {@see useSidebarSection}.
     */
    const section = useSidebarSection();
    const isDirectMessages = section === 'direct-messages';
    const isProjects = section === 'projects';
    const isAssets = section === 'assets';

    const [composing, setComposing] = useState(false);
    const [dmFilter, setDmFilter] = useState('');

    /*
     * Mounted here rather than inside the search button, because the DM shape
     * below replaces that button with a filter box — and ⌘K has to keep working
     * wherever you are in the workspace, which is the only reason it exists.
     */
    useSearchShortcut(searchIndex.url(workspace.slug));

    if (isAssets) {
        /*
         * Inside the library the sidebar is its shelves, for the reason it is
         * the project list inside Projects: the rail has already said which part
         * of the workspace you are in, and a channel list beside a gallery is a
         * list of rooms none of this is filed in.
         */
        return (
            <div className="flex h-full flex-col bg-sidebar text-sidebar-foreground">
                <SidebarHeader
                    onCompose={() => setComposing(true)}
                    inDrawer={inDrawer}
                />

                <NewMessageDialog
                    workspaceSlug={workspace.slug}
                    open={composing}
                    onOpenChange={setComposing}
                />

                <nav
                    aria-label="Asset library"
                    className="snack-scroll flex-1 space-y-1 overflow-y-auto px-3 pt-1 pb-4"
                >
                    <AssetSidebar onNavigate={onNavigate} />
                </nav>

                <NotificationPermissionPrompt />
            </div>
        );
    }

    if (isProjects) {
        /*
         * Inside Projects the sidebar is the project list, for the reason it is
         * the DM list inside direct messages: the rail has already said which
         * part of the workspace you are in, and a channel list beside a board is
         * a list of rooms none of this work is happening in.
         */
        return (
            <div className="flex h-full flex-col bg-sidebar text-sidebar-foreground">
                <SidebarHeader
                    onCompose={() => setComposing(true)}
                    inDrawer={inDrawer}
                />

                <NewMessageDialog
                    workspaceSlug={workspace.slug}
                    open={composing}
                    onOpenChange={setComposing}
                />

                <nav
                    aria-label="Projects"
                    className="snack-scroll flex-1 space-y-4 overflow-y-auto px-3 pt-1 pb-4"
                >
                    <ProjectSection onNavigate={onNavigate} />
                </nav>

                <NotificationPermissionPrompt />
            </div>
        );
    }

    if (isDirectMessages) {
        return (
            <div className="flex h-full flex-col bg-sidebar text-sidebar-foreground">
                <SidebarHeader
                    onCompose={() => setComposing(true)}
                    inDrawer={inDrawer}
                />

                <NewMessageDialog
                    workspaceSlug={workspace.slug}
                    open={composing}
                    onOpenChange={setComposing}
                />

                <div className="px-3 pb-2">
                    <DirectMessageFilter
                        value={dmFilter}
                        onChange={setDmFilter}
                    />
                </div>

                <nav
                    aria-label="Direct messages"
                    className="snack-scroll flex-1 space-y-4 overflow-y-auto px-3 pb-4"
                >
                    <DirectMessageSection
                        filter={dmFilter}
                        onNavigate={onNavigate}
                    />
                </nav>

                <NotificationPermissionPrompt />
            </div>
        );
    }

    return (
        <div className="flex h-full flex-col bg-sidebar text-sidebar-foreground">
            <SidebarHeader
                onCompose={() => setComposing(true)}
                inDrawer={inDrawer}
            />

            <NewMessageDialog
                workspaceSlug={workspace.slug}
                open={composing}
                onOpenChange={setComposing}
            />

            <div className="px-3 pb-2">
                <SearchButton onNavigate={onNavigate} />
            </div>

            <nav
                aria-label="Workspace"
                className="snack-scroll flex-1 space-y-4 overflow-y-auto px-3 pb-4"
            >
                {/*
                 * Threads alone. Mentions, Notifications, Saved, and Files moved
                 * to the rail on the left, where the parts of the product live;
                 * what is left here is the workspace's own contents — the rooms
                 * and the people — plus this, which is the one list that can be
                 * waiting on a reply and so is checked as often as a channel.
                 */}
                <ul className="space-y-0.5">
                    <SidebarLink
                        icon={MessagesSquare}
                        label="Threads"
                        badge={unread.threads}
                        href={threadsIndex.url(workspace.slug)}
                        active={isThreadsView}
                        onNavigate={onNavigate}
                    />

                    {/*
                     * Under Threads, and for the same reason it is here rather
                     * than in the rail: both are records of things that happened
                     * *in* this workspace's rooms, and a huddle leaves nothing
                     * behind in the room it happened in — so the only way to
                     * find out one took place is a list of them.
                     */}
                    <SidebarLink
                        icon={Headphones}
                        label="Huddles"
                        href={huddlesIndex.url(workspace.slug)}
                        active={isHuddlesView}
                        onNavigate={onNavigate}
                    />
                </ul>

                <SidebarSection
                    title="Channels"
                    action={
                        <ChannelListMenu
                            workspaceSlug={workspace.slug}
                            canBrowse={
                                current?.workspace.abilities.create_channels ??
                                false
                            }
                            onBrowse={() => {
                                onNavigate?.();
                                router.visit(browseChannels(workspace.slug));
                            }}
                        />
                    }
                >
                    <ChannelSectionList
                        workspaceSlug={workspace.slug}
                        sections={sections}
                        groups={channelGroups}
                        hasChannels={channels.length > 0}
                        activeChannelSlug={activeChannelSlug}
                        onNavigate={onNavigate}
                    />

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

                <DirectMessageSection onNavigate={onNavigate} />

                {(muted.length > 0 || mutedGroups.length > 0) && (
                    <SidebarSection title="Muted" defaultOpen={false}>
                        <ul className="space-y-0.5">
                            {muted.map((channel) => (
                                <li key={`channel-${channel.id}`}>
                                    <ChannelRow
                                        channel={channel}
                                        workspaceSlug={workspace.slug}
                                        groups={channelGroups}
                                        isActive={
                                            channel.slug === activeChannelSlug
                                        }
                                        onNavigate={onNavigate}
                                    />
                                </li>
                            ))}

                            {mutedGroups.map((conversation) => (
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

/**
 * The project list: the boards you are on, and the way to the rest.
 *
 * Membership rather than every project in the workspace — see
 * App\Services\WorkspaceNavigator::memberProjects(). "Browse projects" at the
 * bottom is what makes the others reachable, exactly as it is for channels.
 *
 * Arranged under the same headings the project page uses, but only the ones
 * holding something of yours: the sidebar shows a handful of boards, and a
 * heading over nothing costs a row that a board could have had. Where they are
 * curated is the project page — there is nothing to manage here.
 */
function ProjectSection({ onNavigate }: { onNavigate?: () => void }) {
    const workspace = useWorkspace();
    const { projects, starred, sections } = useSidebarProjects();
    const activeProjectSlug = useActiveProjectSlug();

    /*
     * A named group always names itself, even when it is the only one.
     *
     * Filing a project under "Platform" and then being shown a bare list is the
     * heading going missing — the reader did the filing and cannot see it. The
     * only heading worth suppressing is the synthetic "Ungrouped" one when
     * there is nothing else beside it: a list whose every project is ungrouped
     * is not a section, it is a list.
     */
    const showUngrouped = sections.length > 1;

    return (
        <SidebarSection
            title="Projects"
            action={
                workspace.abilities.create_projects ? (
                    <Link
                        href={projectsIndex(workspace.slug)}
                        onClick={onNavigate}
                        className="rounded-md p-1 hover:bg-sidebar-accent"
                    >
                        <Plus aria-hidden="true" className="size-4" />
                        <span className="sr-only">
                            Create or browse projects
                        </span>
                    </Link>
                ) : null
            }
        >
            {projects.length === 0 ? (
                <p className="px-2 py-1.5 text-xs text-muted-foreground">
                    You are not on any projects yet.
                </p>
            ) : (
                <>
                    {starred.length > 0 && (
                        <div>
                            <p className="flex items-center gap-1 px-2 pt-1 pb-0.5 text-[0.6875rem] font-medium tracking-wide text-muted-foreground/70 uppercase">
                                <Star
                                    aria-hidden="true"
                                    className="size-3 fill-current text-amber-500"
                                />
                                Starred
                            </p>

                            <ProjectRows
                                projects={starred}
                                workspaceSlug={workspace.slug}
                                activeProjectSlug={activeProjectSlug}
                                onNavigate={onNavigate}
                            />
                        </div>
                    )}

                    {sections.map((section) => (
                        <div
                            key={section.group?.id ?? 'ungrouped'}
                            className="mt-1.5 first:mt-0"
                        >
                            {(section.group !== null || showUngrouped) && (
                                <p className="px-2 pt-1 pb-0.5 text-[0.6875rem] font-medium tracking-wide text-muted-foreground/70 uppercase">
                                    {section.group?.name ?? 'Ungrouped'}
                                </p>
                            )}

                            <ProjectRows
                                projects={section.projects}
                                workspaceSlug={workspace.slug}
                                activeProjectSlug={activeProjectSlug}
                                onNavigate={onNavigate}
                            />
                        </div>
                    ))}
                </>
            )}

            <Link
                href={projectsIndex(workspace.slug)}
                onClick={onNavigate}
                className="mt-0.5 flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm text-muted-foreground transition-colors hover:bg-sidebar-accent"
            >
                <Compass aria-hidden="true" className="size-4" />
                Browse projects
            </Link>
        </SidebarSection>
    );
}

/**
 * The rows themselves, shared by the starred list and every heading below it —
 * a starred project is the same row in a different place, not a second kind of
 * row with its own styling to keep in step.
 */
function ProjectRows({
    projects,
    workspaceSlug,
    activeProjectSlug,
    onNavigate,
}: {
    projects: Project[];
    workspaceSlug: string;
    activeProjectSlug: string | null;
    onNavigate?: () => void;
}) {
    return (
        <ul className="space-y-0.5">
            {projects.map((project) => (
                <li key={project.id}>
                    <Link
                        href={projectBoard([workspaceSlug, project.slug])}
                        onClick={onNavigate}
                        aria-current={
                            project.slug === activeProjectSlug
                                ? 'page'
                                : undefined
                        }
                        className={cn(
                            'flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-sm transition-colors',
                            project.slug === activeProjectSlug
                                ? 'bg-sidebar-accent font-semibold text-foreground'
                                : 'text-muted-foreground hover:bg-sidebar-accent hover:text-foreground',
                        )}
                    >
                        <ProjectAvatar project={project} size="sm" />
                        <span className="truncate">{project.name}</span>
                    </Link>
                </li>
            ))}
        </ul>
    );
}

/**
 * The DM list: groups you are in, then everyone else in the workspace.
 *
 * Its own component because the sidebar has two shapes — the workspace's
 * contents, and direct messages alone — and this list is in both of them. One
 * definition means the roster cannot render one way beside the channels and
 * another way on its own.
 */
function DirectMessageSection({
    filter = '',
    onNavigate,
}: {
    /** Narrows the list to matching names. Empty means "show everyone". */
    filter?: string;
    onNavigate?: () => void;
}) {
    const workspace = useWorkspace();
    const currentUser = useCurrentUser();
    const { groups: allGroups } = useSidebarConversations();
    const allPeople = useSidebarPeople();
    const activeConversationId = useActiveConversationId();

    const [composing, setComposing] = useState(false);

    /*
     * Filtered here rather than on the server. The whole roster is already on
     * the shared prop — it is what the sidebar renders — so a request per
     * keystroke would fetch a list the client is holding, and the results would
     * arrive a network round trip after the letter that asked for them.
     */
    const term = filter.trim().toLocaleLowerCase();

    const { groups, people } = useMemo(() => {
        if (term === '') {
            return { groups: allGroups, people: allPeople };
        }

        const matches = (text: string | null | undefined) =>
            (text ?? '').toLocaleLowerCase().includes(term);

        return {
            groups: allGroups.filter((group) => matches(group.display_name)),
            people: allPeople.filter(
                (person) =>
                    matches(person.user.name) || matches(person.user.username),
            ),
        };
    }, [allGroups, allPeople, term]);

    return (
        <>
            <NewMessageDialog
                workspaceSlug={workspace.slug}
                open={composing}
                onOpenChange={setComposing}
            />

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
                            <span className="sr-only">Start a new message</span>
                        </button>
                    ) : null
                }
            >
                {groups.length === 0 && people.length === 0 ? (
                    term === '' ? (
                        <p className="px-2 py-1.5 text-xs text-muted-foreground">
                            {workspace.abilities.start_conversations
                                ? 'Nobody else is in this workspace yet.'
                                : 'You have no direct messages.'}
                        </p>
                    ) : (
                        /*
                         * Nobody by that name — which is a different answer from
                         * "nothing said that", and the reader may well have
                         * meant the second. The way to it is offered rather than
                         * silently substituted: this box searches people, and
                         * that page searches what people wrote.
                         */
                        <div className="px-2 py-1.5">
                            <p className="text-xs text-muted-foreground">
                                No person or group matches “{filter.trim()}”.
                            </p>

                            <Link
                                href={searchIndex.url(workspace.slug, {
                                    query: { q: filter.trim() },
                                })}
                                onClick={onNavigate}
                                className="mt-1.5 inline-flex items-center gap-1.5 text-xs font-medium text-brand hover:underline"
                            >
                                <Search aria-hidden="true" className="size-3" />
                                Search messages instead
                            </Link>
                        </div>
                    )
                ) : (
                    <ul className="space-y-0.5">
                        {/*
                         * Groups above people. A group is a room somebody
                         * deliberately made, so it belongs with the other
                         * things you navigate to; the roster underneath is
                         * a directory you scan, and it is the longer list.
                         */}
                        {groups.map((conversation) => (
                            <li key={conversation.id}>
                                <ConversationRow
                                    conversation={conversation}
                                    workspaceSlug={workspace.slug}
                                    viewerId={currentUser?.id ?? null}
                                    isActive={
                                        conversation.id === activeConversationId
                                    }
                                    onNavigate={onNavigate}
                                />
                            </li>
                        ))}

                        {people.map((person) => (
                            <li key={person.user.id}>
                                <PersonRow
                                    person={person}
                                    workspaceSlug={workspace.slug}
                                    isActive={
                                        person.conversation !== null &&
                                        person.conversation.id ===
                                            activeConversationId
                                    }
                                    onNavigate={onNavigate}
                                />
                            </li>
                        ))}
                    </ul>
                )}
            </SidebarSection>
        </>
    );
}

/**
 * The channel list, arranged under the reader's own sections.
 *
 * # Folding
 *
 * A folded section keeps showing anything unread and the channel you are
 * currently in. Folding is "I do not need to see these", not "stop telling me
 * things" — a sidebar that swallowed an unread badge because of where its owner
 * filed the channel would make the fold a thing nobody could safely use. The
 * count beside the heading is what is actually hidden, so the row does not go
 * quiet about the rest.
 *
 * # Dragging
 *
 * Whole sections are the drop targets, and the drag state lives here because
 * the row that starts the gesture and the section that ends it are siblings.
 * See ChannelSectionDropZone.
 */
function ChannelSectionList({
    workspaceSlug,
    sections,
    groups,
    hasChannels,
    activeChannelSlug,
    onNavigate,
}: {
    workspaceSlug: string;
    sections: ChannelSection[];
    groups: ChannelGroup[];
    /** Whether anything at all is in the list, muted channels aside. */
    hasChannels: boolean;
    activeChannelSlug: string | null;
    onNavigate?: () => void;
}) {
    const unread = useUnreadState();
    const { isCollapsed, toggle, expand } =
        useCollapsedChannelSections(workspaceSlug);

    const [draggingChannel, setDraggingChannel] = useState<Channel | null>(
        null,
    );

    /*
     * While a filed channel is in the air, the leftovers are a place to put it
     * down — even when there is nothing in them.
     *
     * The list normally drops that section when it is empty, because a heading
     * over nothing is a wasted row. But someone who has filed *every* channel
     * has, at that moment, nowhere to drag one back to: the way out of a section
     * would exist only in the ⋯ menu, which is not where they are looking. So it
     * comes back for the duration of the drag and goes away again when the
     * channel lands.
     */
    const dropSections = useMemo(() => {
        const needsLeftovers =
            draggingChannel !== null &&
            draggingChannel.channel_group_id !== null &&
            !sections.some((channelSection) => channelSection.group === null);

        return needsLeftovers
            ? [...sections, { group: null, channels: [] }]
            : sections;
    }, [sections, draggingChannel]);

    if (!hasChannels && groups.length === 0) {
        return (
            <p className="px-2 py-1.5 text-xs text-muted-foreground">
                You have not joined any channels yet.
            </p>
        );
    }

    return (
        <>
            {dropSections.map((channelSection, index) => {
                const groupId = channelSection.group?.id ?? null;
                const collapsed = isCollapsed(groupId);

                // What survives the fold: anything with something in it for
                // you, and wherever you are standing right now.
                const shown = collapsed
                    ? channelSection.channels.filter((channel) => {
                          const counts = unread.channels[channel.id];

                          return (
                              channel.slug === activeChannelSlug ||
                              (counts?.messages ?? 0) > 0 ||
                              (counts?.mentions ?? 0) > 0
                          );
                      })
                    : channelSection.channels;

                return (
                    <ChannelSectionDropZone
                        key={groupId ?? 'ungrouped'}
                        groupId={groupId}
                        workspaceSlug={workspaceSlug}
                        draggingChannel={draggingChannel}
                        onDropped={(landedIn) => {
                            setDraggingChannel(null);
                            expand(landedIn);
                        }}
                    >
                        {channelSection.group !== null ? (
                            <ChannelSectionHeading
                                group={channelSection.group}
                                workspaceSlug={workspaceSlug}
                                canMoveUp={index > 0}
                                canMoveDown={index < groups.length - 1}
                                collapsed={collapsed}
                                hiddenCount={
                                    channelSection.channels.length -
                                    shown.length
                                }
                                onToggle={() => toggle(groupId)}
                            />
                        ) : (
                            /*
                             * The leftovers get a heading only when something
                             * above them has one — and it folds like the rest,
                             * since "everything I have not filed" is exactly the
                             * pile somebody wants out of the way.
                             */
                            groups.length > 0 && (
                                <div className="flex items-center gap-1 px-2 pt-1 pb-0.5">
                                    <ChannelSectionToggle
                                        label="Other"
                                        collapsed={collapsed}
                                        hiddenCount={
                                            channelSection.channels.length -
                                            shown.length
                                        }
                                        onToggle={() => toggle(groupId)}
                                    />
                                </div>
                            )
                        )}

                        {channelSection.channels.length === 0 ? (
                            /*
                             * An empty section keeps its heading — see
                             * WorkspaceNavigator::sidebarChannelGroups() — so it
                             * needs a line saying it is empty on purpose, and
                             * where the channels come from. Folded, it says
                             * nothing: the fold is the answer.
                             */
                            !collapsed && (
                                <p className="px-2 py-1 text-xs text-muted-foreground/70">
                                    {channelSection.group === null
                                        ? 'Drop here to take it out of its section.'
                                        : 'Empty. Drag a channel here, or use its ⋯ menu.'}
                                </p>
                            )
                        ) : (
                            <ul className="space-y-0.5">
                                {shown.map((channel) => (
                                    <li key={channel.id}>
                                        <ChannelRow
                                            channel={channel}
                                            workspaceSlug={workspaceSlug}
                                            groups={groups}
                                            isActive={
                                                channel.slug ===
                                                activeChannelSlug
                                            }
                                            /*
                                             * Only where there is somewhere to
                                             * drop it. With no sections the list
                                             * is one list, and a row that lifts
                                             * off the page promises a
                                             * rearrangement that cannot happen.
                                             */
                                            draggable={groups.length > 0}
                                            isDragging={
                                                draggingChannel?.id ===
                                                channel.id
                                            }
                                            onDragStart={setDraggingChannel}
                                            onDragEnd={() =>
                                                setDraggingChannel(null)
                                            }
                                            onNavigate={onNavigate}
                                        />
                                    </li>
                                ))}
                            </ul>
                        )}
                    </ChannelSectionDropZone>
                );
            })}
        </>
    );
}

function ChannelRow({
    channel,
    workspaceSlug,
    groups,
    isActive,
    draggable = false,
    isDragging = false,
    onDragStart,
    onDragEnd,
    onNavigate,
}: {
    channel: Channel;
    workspaceSlug: string;
    /** The viewer's sections, for the row's own "move to section" menu. */
    groups: ChannelGroup[];
    isActive: boolean;
    draggable?: boolean;
    isDragging?: boolean;
    onDragStart?: (channel: Channel) => void;
    onDragEnd?: () => void;
    onNavigate?: () => void;
}) {
    const unread = useUnreadState();
    const counts = unread.channels[channel.id];

    return (
        // `group/row` is what the picker's hover state hangs off, and `relative`
        // is what it is positioned against — it overlays the row rather than
        // sitting inside the link. See ChannelSectionPicker.
        <div className="group/row relative" onClick={onNavigate}>
            <ChannelListItem
                channel={channel}
                workspaceSlug={workspaceSlug}
                isActive={isActive}
                unreadMessages={counts?.messages ?? 0}
                unreadMentions={counts?.mentions ?? 0}
                draggable={draggable}
                isDragging={isDragging}
                onDragStart={onDragStart}
                onDragEnd={onDragEnd}
            />

            <ChannelSectionPicker
                channel={channel}
                groups={groups}
                workspaceSlug={workspaceSlug}
                isActive={isActive}
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

/**
 * A person in the DM list, badged with the unread count of the conversation
 * they have with the viewer — when there is one. Somebody you have never
 * messaged has nothing to be unread.
 */
function PersonRow({
    person,
    workspaceSlug,
    isActive,
    onNavigate,
}: {
    person: WorkspacePerson;
    workspaceSlug: string;
    isActive: boolean;
    onNavigate?: () => void;
}) {
    const unread = useUnreadState();
    const counts =
        person.conversation === null
            ? undefined
            : unread.conversations[person.conversation.id];

    return (
        <div onClick={onNavigate}>
            <PersonListItem
                person={person}
                workspaceSlug={workspaceSlug}
                isActive={isActive}
                unreadMessages={counts?.messages ?? 0}
            />
        </div>
    );
}

function SidebarHeader({
    onCompose,
    inDrawer,
}: {
    onCompose: () => void;
    inDrawer: boolean;
}) {
    const workspace = useWorkspace();

    return (
        <div
            className={cn(
                'flex items-center justify-between gap-2 px-3 py-3',
                // Clear of the drawer's dismiss button, which is absolutely
                // positioned in the same corner as the compose button.
                inDrawer && 'pr-11',
            )}
        >
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

                    <DropdownMenuSeparator />

                    {/*
                     * Everyone's own notification settings, above the
                     * admin-only block: these are the ones any member has a
                     * reason to open, and they change nothing for anybody else.
                     */}
                    <DropdownMenuItem asChild>
                        <Link href={notificationPreferences(workspace.slug)}>
                            <Bell className="mr-2 size-4" />
                            Notification preferences
                        </Link>
                    </DropdownMenuItem>

                    {workspace.abilities.administer && (
                        <DropdownMenuItem asChild>
                            <Link href={workspaceSettings(workspace.slug)}>
                                <Settings className="mr-2 size-4" />
                                Workspace settings
                            </Link>
                        </DropdownMenuItem>
                    )}

                    {/*
                     * Beside the settings rather than in the channel's own menu.
                     * A standup is configured per channel but it is not a
                     * channel setting: it messages people directly, on a
                     * schedule, and the question an admin arrives with is
                     * "what is this workspace sending my team" — which is a
                     * list, not a property of whichever channel they happen to
                     * be looking at.
                     */}
                    {workspace.abilities.administer && (
                        <DropdownMenuItem asChild>
                            <Link href={standupsIndex(workspace.slug)}>
                                <ClipboardList className="mr-2 size-4" />
                                Standups
                            </Link>
                        </DropdownMenuItem>
                    )}

                    {/*
                     * Beside the standups, and admin-only for a stronger reason
                     * than the others: that screen prints live webhook URLs, so
                     * opening it is the same right as issuing one.
                     */}
                    {workspace.abilities.administer && (
                        <DropdownMenuItem asChild>
                            <Link href={webhooksIndex(workspace.slug)}>
                                <Plug className="mr-2 size-4" />
                                Webhooks
                            </Link>
                        </DropdownMenuItem>
                    )}

                    {workspace.abilities.view_audit_logs && (
                        <DropdownMenuItem asChild>
                            <Link href={auditIndex(workspace.slug)}>
                                <ScrollText className="mr-2 size-4" />
                                Audit log
                            </Link>
                        </DropdownMenuItem>
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

/**
 * The way into search.
 *
 * A link rather than an inline input, because search has its own page: the
 * results need room, and the query belongs in the URL so it can be shared and
 * returned to. Typing here would mean two search boxes that disagree about which
 * one holds the query.
 *
 * ⌘K focuses the box on that page — see {@see useSearchShortcut}, which is
 * mounted here rather than there so the shortcut works from anywhere in the
 * workspace, which is the only reason to have it.
 */
function SearchButton({ onNavigate }: { onNavigate?: () => void }) {
    const workspace = useWorkspace();
    const href = searchIndex.url(workspace.slug);

    return (
        <Link
            href={href}
            onClick={onNavigate}
            className={cn(
                'flex w-full items-center gap-2 rounded-lg border border-sidebar-border bg-background/60 px-2.5 py-2 text-sm text-muted-foreground',
                'transition-colors hover:bg-background',
            )}
        >
            <Search aria-hidden="true" className="size-4" />
            <span className="flex-1 text-left">Search</span>
            <kbd className="rounded bg-muted px-1.5 py-0.5 font-sans text-[0.6875rem] font-medium text-muted-foreground">
                ⌘K
            </kbd>
        </Link>
    );
}

/**
 * Narrowing the DM list by name.
 *
 * # Why this is not the search box
 *
 * It occupies the same slot as {@see SearchButton}, and does something entirely
 * different: global search is a navigation that looks through everything anyone
 * has *written*, while this filters the list already in front of you down to the
 * person you are looking for. Sending "ada" to the search page from here would
 * answer a question about messages when the question was about a name — and it
 * would cost a page load to do it.
 *
 * So it filters as you type, with no request and no navigation, and the way to
 * the other kind of search is offered when nothing here matches.
 */
function DirectMessageFilter({
    value,
    onChange,
}: {
    value: string;
    onChange: (value: string) => void;
}) {
    return (
        <div
            className={cn(
                'flex w-full items-center gap-2 rounded-lg border border-sidebar-border bg-background/60 px-2.5 py-2',
                'transition-colors focus-within:bg-background',
            )}
        >
            <Search
                aria-hidden="true"
                className="size-4 shrink-0 text-muted-foreground"
            />

            <input
                type="text"
                value={value}
                onChange={(event) => onChange(event.target.value)}
                // Escape clears rather than blurs: the box is a filter, so the
                // reader wants their whole list back, not the cursor moved.
                onKeyDown={(event) => {
                    if (event.key === 'Escape' && value !== '') {
                        event.preventDefault();
                        onChange('');
                    }
                }}
                placeholder="Find a person or group"
                aria-label="Filter direct messages"
                className="min-w-0 flex-1 bg-transparent text-sm outline-none placeholder:text-muted-foreground"
            />

            {value !== '' && (
                <button
                    type="button"
                    onClick={() => onChange('')}
                    className="shrink-0 rounded p-0.5 text-muted-foreground transition-colors hover:text-foreground"
                >
                    <X aria-hidden="true" className="size-3.5" />
                    <span className="sr-only">Clear the filter</span>
                </button>
            )}
        </div>
    );
}

/**
 * ⌘K / Ctrl+K opens search.
 *
 * Deliberately inert while the focus is in a text field. Search is a full
 * navigation, and nothing in Snack persists an unsent draft yet — so firing this
 * from the composer would discard a half-written message on a keystroke the
 * author may well have meant for the browser. It can be relaxed once drafts
 * survive a navigation.
 */
function useSearchShortcut(href: string) {
    useEffect(() => {
        const onKeyDown = (event: KeyboardEvent) => {
            if (event.key !== 'k' || !(event.metaKey || event.ctrlKey)) {
                return;
            }

            const target = event.target;

            if (
                target instanceof HTMLElement &&
                (target.isContentEditable ||
                    ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName))
            ) {
                return;
            }

            event.preventDefault();
            router.visit(href);
        };

        window.addEventListener('keydown', onKeyDown);

        return () => window.removeEventListener('keydown', onKeyDown);
    }, [href]);
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

/**
 * A top-level sidebar entry.
 *
 * Renders as a link once its view exists and as a disabled button until then,
 * so the sidebar's shape is settled before every destination in it is built.
 */
function SidebarLink({
    icon: Icon,
    label,
    badge = 0,
    href,
    active = false,
    onNavigate,
}: {
    icon: LucideIcon;
    label: string;
    badge?: number;
    /** Omitted while the destination is still to come. */
    href?: string;
    active?: boolean;
    onNavigate?: () => void;
}) {
    const content = (
        <>
            <Icon aria-hidden="true" className="size-4" />
            <span className="flex-1 text-left">{label}</span>

            {badge > 0 && (
                <span className="min-w-5 rounded-full bg-mention px-1.5 py-0.5 text-center text-[0.6875rem] leading-none font-bold text-mention-foreground tabular-nums">
                    {badge > 99 ? '99+' : badge}
                </span>
            )}
        </>
    );

    const className = cn(
        'flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-sm transition-colors',
        active
            ? 'bg-sidebar-accent font-semibold text-foreground'
            : 'text-muted-foreground hover:bg-sidebar-accent hover:text-foreground',
        'disabled:pointer-events-none disabled:opacity-50',
    );

    return (
        <li>
            {href === undefined ? (
                <button
                    type="button"
                    disabled
                    title={`${label} — arriving in a later phase`}
                    className={className}
                >
                    {content}
                </button>
            ) : (
                <Link
                    href={href}
                    onClick={onNavigate}
                    aria-current={active ? 'page' : undefined}
                    className={className}
                >
                    {content}
                </Link>
            )}
        </li>
    );
}
