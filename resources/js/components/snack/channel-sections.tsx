import { router } from '@inertiajs/react';
import {
    ChevronDown,
    ChevronUp,
    Compass,
    FolderPlus,
    MoreHorizontal,
    Plus,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import { ChannelSectionDialog } from '@/components/snack/channel-section-dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';
import {
    destroy as destroySection,
    move as moveSection,
} from '@/routes/channel-groups';
import { update as assignSection } from '@/routes/channels/group';
import type { Channel, ChannelGroup } from '@/types';

/**
 * The fold control that a section heading is built around, and that the
 * leftovers use on their own.
 *
 * Separate from {@see ChannelSectionHeading} because "Other" has a fold and
 * a name but nothing to rename, move, or delete — the leftovers are not a thing
 * anybody made.
 */
export function ChannelSectionToggle({
    label,
    collapsed,
    hiddenCount,
    onToggle,
}: {
    label: string;
    collapsed: boolean;
    /** Channels this fold is hiding right now; 0 when it is open. */
    hiddenCount: number;
    onToggle: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onToggle}
            aria-expanded={!collapsed}
            className="flex min-w-0 flex-1 items-center gap-1 rounded-md text-left text-[0.6875rem] font-medium tracking-wide text-muted-foreground/70 uppercase transition-colors hover:text-foreground"
        >
            <ChevronDown
                aria-hidden="true"
                className={cn(
                    'size-3 shrink-0 transition-transform',
                    collapsed && '-rotate-90',
                )}
            />

            <span className="min-w-0 truncate">{label}</span>

            {/*
             * What the fold is holding back. A folded section still shows any
             * channel with something unread in it — see the sidebar — so this
             * counts the quiet ones, and its absence means nothing is hidden.
             */}
            {hiddenCount > 0 && (
                <span className="shrink-0 tabular-nums opacity-70">
                    {hiddenCount}
                </span>
            )}
        </button>
    );
}

/**
 * A heading in the channel sidebar: the fold, the name, and what can be done to
 * the section.
 *
 * The sections are one person's own — nobody else sees them — so there is no
 * permission to check here. What the server checks is that the section being
 * renamed is yours, which is the only way it could be in your sidebar at all.
 *
 * The name *is* the fold control, as it is on the sidebar's own headings: a
 * separate chevron-sized target beside a word that does nothing when clicked is
 * a smaller target and a worse guess. `hiddenCount` is what the fold is holding
 * back, so a folded section can still say how much is in it.
 */
export function ChannelSectionHeading({
    group,
    workspaceSlug,
    canMoveUp,
    canMoveDown,
    collapsed,
    hiddenCount,
    onToggle,
}: {
    group: ChannelGroup;
    workspaceSlug: string;
    canMoveUp: boolean;
    canMoveDown: boolean;
    collapsed: boolean;
    /** Channels this fold is hiding right now; 0 when it is open. */
    hiddenCount: number;
    onToggle: () => void;
}) {
    const [renaming, setRenaming] = useState(false);
    const [menuOpen, setMenuOpen] = useState(false);

    const args = { workspace: workspaceSlug, channelGroup: group.id };

    const move = (direction: 'up' | 'down') => {
        router.post(
            moveSection.url(args),
            { direction },
            { preserveScroll: true },
        );
    };

    return (
        <>
            <div className="group/heading flex items-center gap-1 px-2 pt-1 pb-0.5">
                <ChannelSectionToggle
                    label={group.name}
                    collapsed={collapsed}
                    hiddenCount={hiddenCount}
                    onToggle={onToggle}
                />

                <DropdownMenu open={menuOpen} onOpenChange={setMenuOpen}>
                    <DropdownMenuTrigger asChild>
                        <button
                            type="button"
                            className={cn(
                                'rounded-md p-0.5 text-muted-foreground transition-colors hover:bg-sidebar-accent hover:text-foreground',
                                // Hidden until wanted, but never from the
                                // keyboard: focus-visible and the open state
                                // both pin it on.
                                'opacity-0 group-hover/heading:opacity-100 focus-visible:opacity-100',
                                menuOpen && 'opacity-100',
                            )}
                        >
                            <MoreHorizontal
                                aria-hidden="true"
                                className="size-3.5"
                            />
                            <span className="sr-only">Manage {group.name}</span>
                        </button>
                    </DropdownMenuTrigger>

                    <DropdownMenuContent align="start" className="w-48">
                        <DropdownMenuItem onSelect={() => setRenaming(true)}>
                            Rename
                        </DropdownMenuItem>

                        <DropdownMenuItem
                            disabled={!canMoveUp}
                            onSelect={() => move('up')}
                        >
                            <ChevronUp className="size-4" />
                            Move up
                        </DropdownMenuItem>

                        <DropdownMenuItem
                            disabled={!canMoveDown}
                            onSelect={() => move('down')}
                        >
                            <ChevronDown className="size-4" />
                            Move down
                        </DropdownMenuItem>

                        <DropdownMenuSeparator />

                        <DropdownMenuItem
                            /*
                             * `text-destructive` rather than the primitive's
                             * destructive variant, which paints the item in the
                             * colour meant to sit *on* a red background — see
                             * the same note in pages/projects/index.tsx.
                             */
                            className="text-destructive focus:text-destructive [&_svg]:text-destructive"
                            onSelect={() =>
                                router.delete(destroySection.url(args), {
                                    preserveScroll: true,
                                })
                            }
                        >
                            <Trash2 className="size-4" />
                            Delete section
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>

            <ChannelSectionDialog
                workspaceSlug={workspaceSlug}
                group={group}
                open={renaming}
                onOpenChange={setRenaming}
            />
        </>
    );
}

/**
 * A section of the sidebar as somewhere a dragged channel can be dropped.
 *
 * The whole section is the target — heading, rows, and the "empty" line — not a
 * seam between rows. Channels inside a section are ordered by name, so there is
 * no slot to aim at: the only thing a drop decides is *which* section, and a
 * target the size of the section is one nobody can miss in a column this narrow.
 * (The board needs per-slot zones because cards carry their own order; see
 * BoardColumnView.)
 *
 * `groupId` is null for the leftovers, which makes dragging a channel there the
 * way to unfile it — the same null the ⋯ menu's "Other" sends.
 */
export function ChannelSectionDropZone({
    groupId,
    workspaceSlug,
    draggingChannel,
    onDropped,
    children,
}: {
    groupId: number | null;
    workspaceSlug: string;
    /** The row being dragged right now, or null when nothing is. */
    draggingChannel: Channel | null;
    /**
     * Called with this section's id once a drop lands in it, so the sidebar can
     * put the drag down and unfold the section the channel just went into —
     * dropping something into a folded section and seeing nothing happen is the
     * one way this gesture can look broken.
     */
    onDropped: (groupId: number | null) => void;
    children: React.ReactNode;
}) {
    const [isOver, setIsOver] = useState(false);

    // Dropping a channel where it already is would be a request that changes
    // nothing, so the section it came from is not a target at all.
    const accepts =
        draggingChannel !== null &&
        draggingChannel.channel_group_id !== groupId;

    return (
        <div
            onDragOver={(event) => {
                if (!accepts) {
                    return;
                }

                // Without this the browser refuses the drop outright — the
                // default for dragover is "not a drop target".
                event.preventDefault();
                event.dataTransfer.dropEffect = 'move';
                setIsOver(true);
            }}
            onDragLeave={() => setIsOver(false)}
            onDrop={(event) => {
                if (!accepts) {
                    return;
                }

                event.preventDefault();
                setIsOver(false);
                onDropped(groupId);

                router.patch(
                    assignSection.url([workspaceSlug, draggingChannel.slug]),
                    { channel_group_id: groupId },
                    { preserveScroll: true },
                );
            }}
            className={cn(
                'mt-1.5 rounded-lg ring-1 transition-colors first:mt-0',
                isOver && accepts
                    ? 'bg-sidebar-accent/50 ring-brand'
                    : 'ring-transparent',
            )}
        >
            {children}
        </div>
    );
}

/**
 * The "+" at the top of the channel list.
 *
 * A menu rather than the plain link it used to be, because there are now two
 * things it could mean: another channel, or another heading to put channels
 * under. `canBrowse` is false for a guest, who has no channel directory — they
 * still get sections, since the handful of channels they were added to is still
 * a list they can arrange.
 */
export function ChannelListMenu({
    workspaceSlug,
    canBrowse,
    onBrowse,
}: {
    workspaceSlug: string;
    canBrowse: boolean;
    onBrowse: () => void;
}) {
    const [creating, setCreating] = useState(false);

    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <button
                        type="button"
                        className="rounded-md p-1 hover:bg-sidebar-accent"
                    >
                        <Plus aria-hidden="true" className="size-4" />
                        <span className="sr-only">
                            Add channels or sections
                        </span>
                    </button>
                </DropdownMenuTrigger>

                <DropdownMenuContent align="end" className="w-52">
                    {canBrowse && (
                        <DropdownMenuItem onSelect={onBrowse}>
                            <Compass aria-hidden="true" className="size-4" />
                            Add or browse channels
                        </DropdownMenuItem>
                    )}

                    <DropdownMenuItem onSelect={() => setCreating(true)}>
                        <FolderPlus aria-hidden="true" className="size-4" />
                        New section…
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>

            <ChannelSectionDialog
                workspaceSlug={workspaceSlug}
                open={creating}
                onOpenChange={setCreating}
            />
        </>
    );
}

/**
 * The control on a channel row that files it under a heading.
 *
 * Overlaid on the row rather than placed inside it: the row is a link, and a
 * menu button inside a link is a click target inside a click target. It sits
 * where the unread badge does and only appears on hover or focus, so the badge
 * is what you see when you are reading the list and the menu is what you see
 * when you have reached for it.
 *
 * Filing a channel writes the *viewer's* membership row, which is why this
 * needs no ability check — anyone who can see the channel in their sidebar is
 * already the only person this affects.
 */
export function ChannelSectionPicker({
    channel,
    groups,
    workspaceSlug,
    isActive,
}: {
    channel: Channel;
    groups: ChannelGroup[];
    workspaceSlug: string;
    isActive: boolean;
}) {
    const [menuOpen, setMenuOpen] = useState(false);
    const [creating, setCreating] = useState(false);

    const current =
        channel.channel_group_id === null
            ? 'none'
            : String(channel.channel_group_id);

    const assign = (value: string) => {
        if (value === current) {
            return;
        }

        router.patch(
            assignSection.url([workspaceSlug, channel.slug]),
            { channel_group_id: value === 'none' ? null : Number(value) },
            { preserveScroll: true },
        );
    };

    return (
        <>
            <div
                className={cn(
                    'absolute inset-y-0 right-1 flex items-center',
                    'opacity-0 transition-opacity group-hover/row:opacity-100 focus-within:opacity-100',
                    menuOpen && 'opacity-100',
                )}
                /*
                 * The row wrapper closes the mobile sidebar when it is clicked,
                 * on the assumption that a click in it is a navigation. This one
                 * is not: opening a menu must not slide the panel away under it.
                 */
                onClick={(event) => event.stopPropagation()}
            >
                <DropdownMenu open={menuOpen} onOpenChange={setMenuOpen}>
                    <DropdownMenuTrigger asChild>
                        <button
                            type="button"
                            className={cn(
                                'rounded-md p-1 transition-colors',
                                isActive
                                    ? 'bg-brand text-primary-foreground hover:bg-brand/80'
                                    : 'bg-sidebar-accent text-muted-foreground hover:text-foreground',
                            )}
                        >
                            <MoreHorizontal
                                aria-hidden="true"
                                className="size-3.5"
                            />
                            <span className="sr-only">
                                Section for #{channel.slug}
                            </span>
                        </button>
                    </DropdownMenuTrigger>

                    <DropdownMenuContent align="end" className="w-52">
                        <DropdownMenuLabel>Move to section</DropdownMenuLabel>

                        <DropdownMenuRadioGroup
                            value={current}
                            onValueChange={assign}
                        >
                            {groups.map((group) => (
                                <DropdownMenuRadioItem
                                    key={group.id}
                                    value={String(group.id)}
                                >
                                    {group.name}
                                </DropdownMenuRadioItem>
                            ))}

                            {groups.length > 0 && <DropdownMenuSeparator />}

                            <DropdownMenuRadioItem value="none">
                                Other
                            </DropdownMenuRadioItem>
                        </DropdownMenuRadioGroup>

                        <DropdownMenuSeparator />

                        {/*
                         * Making a section from the channel that wanted one.
                         * The name and the channel go up together — see
                         * StoreChannelGroupRequest — so this is one dialog and
                         * one request, not "create it, now go and find it".
                         */}
                        <DropdownMenuItem onSelect={() => setCreating(true)}>
                            New section…
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>

            <ChannelSectionDialog
                workspaceSlug={workspaceSlug}
                channel={channel}
                open={creating}
                onOpenChange={setCreating}
            />
        </>
    );
}
