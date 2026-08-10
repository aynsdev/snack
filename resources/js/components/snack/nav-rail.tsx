import { Link, usePage } from '@inertiajs/react';
import {
    Bookmark,
    Folder,
    House,
    Images,
    Inbox,
    KanbanSquare,
    MessageSquare,
} from 'lucide-react';
import { useState } from 'react';
import { UserAvatar } from '@/components/snack/user-avatar';
import { ThemeSwitcher } from '@/components/theme-switcher';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { StatusDialog, UserMenuContent } from '@/components/user-menu-content';
import {
    useActiveConversationId,
    useIsActivityView,
    useIsAssetsView,
    useIsFilesView,
    useIsHomeView,
    useIsProjectsView,
    useIsSavedView,
} from '@/hooks/use-active-channel';
import { enterSection, useSidebarSection } from '@/hooks/use-sidebar-section';
import {
    useCurrentUser,
    useUnreadState,
    useWorkspace,
    useWorkspaceRail,
} from '@/hooks/use-workspace';
import { cn } from '@/lib/utils';
import { index as activityIndex } from '@/routes/activity';
import { index as assetsIndex } from '@/routes/assets';
import { index as dmsIndex } from '@/routes/conversations';
import { index as filesIndex } from '@/routes/files';
import { index as projectsIndex } from '@/routes/projects';
import { index as savedIndex } from '@/routes/saved';
import { show as showWorkspace } from '@/routes/workspaces';

/**
 * The second column: where in the workspace you are.
 *
 * Everything here is scoped to the workspace beside it — each destination is a
 * `/w/{workspace}/…` route and the rail only renders inside the workspace
 * shell — so switching workspaces switches this column with it.
 *
 * # Why a separate column from the sidebar
 *
 * The sidebar lists *things* — channels, people — and changes constantly as you
 * join and leave them. This lists the parts of the product, which do not
 * change. Mixing the two meant Threads and Files sat above the channel list
 * looking like channels, and every new destination made the list you actually
 * navigate by longer.
 *
 * Threads deliberately stays in the sidebar as well. It is where a reply you are
 * waiting on lives, which is a per-workspace list you check as often as a
 * channel, and Activity gathering it does not make that less true.
 */
export function NavRail({ onNavigate }: { onNavigate?: () => void }) {
    const workspace = useWorkspace();
    const workspaces = useWorkspaceRail();
    const unread = useUnreadState();

    /*
     * Both of these follow the section rather than the URL, because a
     * conversation belongs to whichever one it was opened from — see
     * {@see useSidebarSection}. A DM opened from the workspace sidebar is still
     * Home: the sidebar beside it is the channel list, and the rail saying
     * otherwise would point at a column that is not there.
     */
    const isDirectMessages = useSidebarSection() === 'direct-messages';

    // Unconditionally, on their own lines: a hook behind `||` would stop being
    // called the moment the left side was true.
    const isHomeUrl = useIsHomeView();
    const isConversation = useActiveConversationId() !== null;

    const isHome = isHomeUrl || (isConversation && !isDirectMessages);

    const isActivity = useIsActivityView();
    const isSaved = useIsSavedView();
    const isFiles = useIsFilesView();
    const isProjects = useIsProjectsView();
    const isAssets = useIsAssetsView();

    const directMessages = Object.values(unread.conversations).reduce(
        (total, counts) => total + counts.messages,
        0,
    );

    return (
        <nav
            aria-label="Workspace sections"
            /* Bordered on both sides, not just against the sidebar: the seam
               with the workspace rail is where the two dark columns would
               otherwise read as one. */
            className="flex w-[4.75rem] shrink-0 flex-col items-center gap-1 border-x border-rail-foreground/10 bg-nav py-3 text-rail-foreground"
        >
            <ul className="flex flex-1 flex-col items-center gap-1">
                {workspaces.length === 1 && (
                    <SingleWorkspaceMark
                        workspace={workspaces[0]}
                        href={showWorkspace(workspace.slug)}
                    />
                )}

                <NavRailItem
                    icon={House}
                    label="Home"
                    href={showWorkspace(workspace.slug)}
                    isActive={isHome}
                    onNavigate={onNavigate}
                />

                <NavRailItem
                    icon={MessageSquare}
                    label="DMs"
                    href={dmsIndex(workspace.slug)}
                    badge={directMessages}
                    isActive={isDirectMessages}
                    /*
                     * The one destination that has to announce itself: `/dms`
                     * redirects into the newest conversation, so the URL this
                     * click lands on is the same one a DM opened from the
                     * channel sidebar lands on. Skipped for a modified click,
                     * which opens a tab and leaves this one where it is.
                     */
                    onNavigate={(event) => {
                        if (!isModifiedClick(event)) {
                            enterSection('direct-messages');
                        }

                        onNavigate?.();
                    }}
                />

                <NavRailItem
                    icon={Inbox}
                    label="Activity"
                    href={activityIndex(workspace.slug)}
                    /*
                     * The notification count alone, not a sum of the three lists
                     * behind the page. A mention writes a notification too, so
                     * adding them would count one event twice — and this badge
                     * has to agree with the number of unread rows the reader
                     * then sees.
                     */
                    badge={unread.notifications}
                    isActive={isActivity}
                    onNavigate={onNavigate}
                />

                <NavRailItem
                    icon={Bookmark}
                    label="Saved"
                    href={savedIndex(workspace.slug)}
                    isActive={isSaved}
                    onNavigate={onNavigate}
                />

                <NavRailItem
                    icon={Folder}
                    label="Files"
                    href={filesIndex(workspace.slug)}
                    isActive={isFiles}
                    onNavigate={onNavigate}
                />

                {/*
                 * Projects: boards, backlogs, and the work on them.
                 *
                 * Where "Tasks — coming soon" used to sit, and named for the
                 * container rather than its contents. A task never stands on its
                 * own here — it is filed on a board, in a column, by somebody on
                 * that project — so a rail item called Tasks would promise a
                 * workspace-wide list of work that does not exist.
                 */}
                <NavRailItem
                    icon={KanbanSquare}
                    label="Projects"
                    href={projectsIndex(workspace.slug)}
                    isActive={isProjects}
                    onNavigate={onNavigate}
                />

                {/*
                 * The asset library: brand kits, company information, the
                 * documents people keep asking for a link to.
                 *
                 * Its own destination rather than a mode of Files, because the
                 * two answer different questions. Files lists what was *said* —
                 * every attachment on every message you can read. This lists
                 * what was *put somewhere* on purpose, which is the only kind of
                 * thing anybody goes looking for a year later.
                 */}
                <NavRailItem
                    icon={Images}
                    label="Assets"
                    href={assetsIndex(workspace.slug)}
                    isActive={isAssets}
                    onNavigate={onNavigate}
                />
            </ul>

            <div className="flex flex-col items-center gap-2">
                <ThemeSwitcher />
                <RailUserMenu />
            </div>
        </nav>
    );
}

function SingleWorkspaceMark({
    workspace,
    href,
}: {
    workspace: ReturnType<typeof useWorkspaceRail>[number];
    href: ReturnType<typeof showWorkspace>;
}) {
    return (
        <li className="mb-2 border-b border-rail-foreground/10 pb-3">
            <Tooltip>
                <TooltipTrigger asChild>
                    <Link
                        href={href}
                        aria-label={workspace.name}
                        className="flex size-11 items-center justify-center overflow-hidden rounded-xl bg-brand-subtle text-sm font-semibold text-foreground ring-1 ring-rail-foreground/10"
                    >
                        {workspace.logo_url ? (
                            <img
                                src={workspace.logo_url}
                                alt=""
                                className="size-full object-cover"
                            />
                        ) : (
                            workspace.initials
                        )}
                    </Link>
                </TooltipTrigger>
                <TooltipContent side="right">{workspace.name}</TooltipContent>
            </Tooltip>
        </li>
    );
}

function RailUserMenu() {
    const user = useCurrentUser();
    const page = usePage();
    const [menuOpen, setMenuOpen] = useState(false);
    const [statusOpen, setStatusOpen] = useState(false);

    if (!user) {
        return null;
    }

    return (
        <>
            <DropdownMenu open={menuOpen} onOpenChange={setMenuOpen}>
                <DropdownMenuTrigger
                    aria-label="Your account and status"
                    className="rounded-lg"
                >
                    <UserAvatar
                        user={user}
                        size="lg"
                        showPresence
                        ringClassName="ring-nav"
                    />
                </DropdownMenuTrigger>

                <DropdownMenuContent side="right" align="end" className="w-72">
                    <UserMenuContent
                        user={page.props.auth.user}
                        onEditStatus={() => {
                            setMenuOpen(false);
                            setStatusOpen(true);
                        }}
                    />
                </DropdownMenuContent>
            </DropdownMenu>

            <StatusDialog
                open={statusOpen}
                onOpenChange={setStatusOpen}
                emoji={user.status_emoji ?? ''}
                message={user.status_message ?? ''}
            />
        </>
    );
}

/**
 * A click the browser handles itself — a new tab, a new window, a download.
 * Inertia ignores these too, so anything that changes this tab's own state has
 * to ignore them as well.
 */
function isModifiedClick(event: React.MouseEvent<Element>): boolean {
    return (
        event.metaKey ||
        event.ctrlKey ||
        event.shiftKey ||
        event.altKey ||
        event.button !== 0
    );
}

function NavRailItem({
    icon: Icon,
    label,
    href,
    badge = 0,
    isActive = false,
    onNavigate,
}: {
    icon: typeof House;
    label: string;
    /** A Wayfinder route definition. Omitted for a destination that does not
     *  exist yet. */
    href?: string | { url: string; method: 'get' };
    badge?: number;
    isActive?: boolean;
    onNavigate?: (event: React.MouseEvent<Element>) => void;
}) {
    const className = cn(
        'group relative flex w-[3.75rem] flex-col items-center gap-1 rounded-xl px-1 py-2 transition-colors',
        isActive
            ? 'bg-rail-active/15 text-rail-active'
            : 'text-rail-muted hover:bg-rail-foreground/10 hover:text-rail-foreground',
        // A destination that does not exist yet renders dimmed, below.
        href === undefined && 'cursor-default opacity-45 hover:bg-transparent',
    );

    const content = (
        <>
            {/* The same active pill the workspace rail uses, so "you are here"
                is one visual idea across both columns rather than two. */}
            <span
                aria-hidden="true"
                className={cn(
                    'absolute top-1/2 -left-2 w-1 -translate-y-1/2 rounded-r-full bg-rail-active transition-all',
                    isActive ? 'h-8 opacity-100' : 'h-2 opacity-0',
                )}
            />

            <span className="relative">
                <Icon aria-hidden="true" className="size-5" />

                {badge > 0 && (
                    <span className="absolute -top-1.5 -right-2.5 min-w-4 rounded-full bg-mention px-1 py-0.5 text-center text-[0.625rem] leading-none font-bold text-mention-foreground tabular-nums">
                        {badge > 99 ? '99+' : badge}
                        <span className="sr-only"> unread</span>
                    </span>
                )}
            </span>

            <span className="text-[0.6875rem] leading-none font-medium">
                {label}
            </span>
        </>
    );

    if (href === undefined) {
        return (
            <li>
                <Tooltip>
                    <TooltipTrigger asChild>
                        {/*
                         * A button rather than a disabled one: `disabled` swallows
                         * the pointer events the tooltip needs, so the row would
                         * be dimmed with no way to find out why.
                         */}
                        <button
                            type="button"
                            aria-disabled="true"
                            onClick={(event) => event.preventDefault()}
                            className={className}
                        >
                            {content}
                        </button>
                    </TooltipTrigger>
                    <TooltipContent side="right">
                        {label} — coming soon
                    </TooltipContent>
                </Tooltip>
            </li>
        );
    }

    return (
        <li>
            <Link
                href={href}
                onClick={onNavigate}
                aria-current={isActive ? 'page' : undefined}
                className={className}
            >
                {content}
            </Link>
        </li>
    );
}
