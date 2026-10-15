import { Link, usePage } from '@inertiajs/react';
import { Film, Image, LayoutGrid, MessageSquare, Plus } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useActiveBiteChatId } from '@/hooks/use-active-channel';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { useWorkspace } from '@/hooks/use-workspace';
import { cn } from '@/lib/utils';
import { index as biteIndex } from '@/routes/bite';
import { show as showChat } from '@/routes/bite/chats';
import { index as mediaIndex } from '@/routes/bite/media';
import type { BiteChatSummary } from '@/types/bite';

/**
 * The Bite section's sidebar: Chats and Media, the way an assistant app keeps
 * its history.
 *
 * The chat list comes from the page rather than the shared props — it is only
 * ever needed here, and every Bite page sends it. The Media tab swaps the list
 * for its filters, since a gallery has nothing to pick between but the kind.
 */
export function BiteSidebar({ onNavigate }: { onNavigate?: () => void }) {
    const workspace = useWorkspace();
    const { currentUrl } = useCurrentUrl();
    const isMedia = /^\/w\/[^/]+\/ai\/media/.test(currentUrl);
    const chats =
        (usePage().props.biteChats as BiteChatSummary[] | undefined) ?? [];

    return (
        <div className="space-y-4">
            <div
                role="tablist"
                aria-label="Bite"
                className="grid grid-cols-2 gap-1 rounded-lg bg-sidebar-accent/60 p-1"
            >
                <TabLink
                    href={biteIndex.url(workspace.slug)}
                    label="Chats"
                    icon={MessageSquare}
                    active={!isMedia}
                    onNavigate={onNavigate}
                />
                <TabLink
                    href={mediaIndex.url(workspace.slug)}
                    label="Media"
                    icon={LayoutGrid}
                    active={isMedia}
                    onNavigate={onNavigate}
                />
            </div>

            {isMedia ? (
                <MediaFilters onNavigate={onNavigate} />
            ) : (
                <>
                    <Link
                        href={biteIndex.url(workspace.slug)}
                        onClick={onNavigate}
                        className="flex w-full items-center gap-2 rounded-lg bg-foreground/5 px-2 py-2 text-sm font-medium transition-colors hover:bg-sidebar-accent"
                    >
                        <Plus aria-hidden="true" className="size-4" />
                        New chat
                    </Link>

                    <ChatList chats={chats} onNavigate={onNavigate} />
                </>
            )}
        </div>
    );
}

function TabLink({
    href,
    label,
    icon: Icon,
    active,
    onNavigate,
}: {
    href: string;
    label: string;
    icon: LucideIcon;
    active: boolean;
    onNavigate?: () => void;
}) {
    return (
        <Link
            href={href}
            role="tab"
            aria-selected={active}
            onClick={onNavigate}
            className={cn(
                'flex items-center justify-center gap-1.5 rounded-md px-2 py-1 text-sm transition-colors',
                active
                    ? 'bg-sidebar font-semibold text-foreground shadow-sm'
                    : 'text-muted-foreground hover:text-foreground',
            )}
        >
            <Icon aria-hidden="true" className="size-3.5" />
            {label}
        </Link>
    );
}

/**
 * Chats grouped by when they were last used, newest first.
 */
function ChatList({
    chats,
    onNavigate,
}: {
    chats: BiteChatSummary[];
    onNavigate?: () => void;
}) {
    const workspace = useWorkspace();
    const activeId = useActiveBiteChatId();

    if (chats.length === 0) {
        return (
            <p className="px-2 text-sm text-muted-foreground">
                No chats yet. Ask Bite anything to start one.
            </p>
        );
    }

    return (
        <nav aria-label="Bite chats" className="space-y-4">
            {groupByAge(chats).map(([heading, rows]) => (
                <div key={heading}>
                    <p className="px-2 py-1 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                        {heading}
                    </p>
                    <ul className="space-y-0.5">
                        {rows.map((chat) => {
                            const active = chat.id === activeId;

                            return (
                                <li key={chat.id}>
                                    <Link
                                        href={showChat.url({
                                            workspace: workspace.slug,
                                            conversation: chat.id,
                                        })}
                                        onClick={onNavigate}
                                        aria-current={
                                            active ? 'page' : undefined
                                        }
                                        title={chat.title}
                                        className={cn(
                                            'block w-full truncate rounded-lg px-2 py-1.5 text-sm transition-colors',
                                            active
                                                ? 'bg-sidebar-accent font-semibold text-foreground'
                                                : 'text-muted-foreground hover:bg-sidebar-accent hover:text-foreground',
                                        )}
                                    >
                                        {chat.title}
                                    </Link>
                                </li>
                            );
                        })}
                    </ul>
                </div>
            ))}
        </nav>
    );
}

function MediaFilters({ onNavigate }: { onNavigate?: () => void }) {
    const workspace = useWorkspace();
    const { currentUrl } = useCurrentUrl();
    const kind = new URLSearchParams(currentUrl.split('?')[1] ?? '').get(
        'kind',
    );

    const filters: { label: string; icon: LucideIcon; kind: string | null }[] =
        [
            { label: 'Everything', icon: LayoutGrid, kind: null },
            { label: 'Images', icon: Image, kind: 'image' },
            { label: 'Videos', icon: Film, kind: 'video' },
        ];

    return (
        <ul className="space-y-0.5">
            {filters.map((filter) => {
                const active = filter.kind === kind;

                return (
                    <li key={filter.label}>
                        <Link
                            href={mediaIndex.url(workspace.slug, {
                                query: filter.kind ? { kind: filter.kind } : {},
                            })}
                            onClick={onNavigate}
                            aria-current={active ? 'page' : undefined}
                            className={cn(
                                'flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-sm transition-colors',
                                active
                                    ? 'bg-sidebar-accent font-semibold text-foreground'
                                    : 'text-muted-foreground hover:bg-sidebar-accent hover:text-foreground',
                            )}
                        >
                            <filter.icon
                                aria-hidden="true"
                                className="size-4"
                            />
                            {filter.label}
                        </Link>
                    </li>
                );
            })}
        </ul>
    );
}

/**
 * Today, Yesterday, Previous 7 days, Older — by the chat's last message, or
 * when it was started if nobody has written in it yet.
 */
export function groupByAge(
    chats: BiteChatSummary[],
    now: Date = new Date(),
): [string, BiteChatSummary[]][] {
    const startOfToday = new Date(now);
    startOfToday.setHours(0, 0, 0, 0);

    const day = 24 * 60 * 60 * 1000;
    const groups = new Map<string, BiteChatSummary[]>();

    for (const chat of chats) {
        const stamp = chat.last_message_at ?? chat.created_at;
        const time = stamp ? new Date(stamp).getTime() : 0;

        const heading =
            time >= startOfToday.getTime()
                ? 'Today'
                : time >= startOfToday.getTime() - day
                  ? 'Yesterday'
                  : time >= startOfToday.getTime() - 7 * day
                    ? 'Previous 7 days'
                    : 'Older';

        groups.set(heading, [...(groups.get(heading) ?? []), chat]);
    }

    return [...groups.entries()];
}
