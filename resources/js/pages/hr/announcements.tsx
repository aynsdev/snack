import { Link, router } from '@inertiajs/react';
import { Megaphone, Pencil, Pin } from 'lucide-react';
import { useState } from 'react';
import { AnnouncementDialog } from '@/components/hr/announcement-dialog';
import type { PostableChannel } from '@/components/hr/announcement-dialog';
import { DeleteButton } from '@/components/hr/hr-dialogs';
import { EmptyNote, HrPage, PersonAvatar } from '@/components/hr/hr-ui';
import { Button } from '@/components/ui/button';
import { useWorkspace } from '@/hooks/use-workspace';
import { show as showChannel } from '@/routes/channels';
import { destroy as destroyAnnouncement } from '@/routes/hr/announcements';
import type { Announcement, Paginated } from '@/types/hr';

type Props = {
    announcements: Paginated<Announcement>;
    can_manage: boolean;
    channels: PostableChannel[];
};

/**
 * Company-wide notices. Pinned ones stay at the top until unpinned.
 */
export default function AnnouncementsPage({
    announcements,
    can_manage,
    channels,
}: Props) {
    const workspace = useWorkspace();
    const [editing, setEditing] = useState<Announcement | 'new' | null>(null);

    return (
        <HrPage
            title="Announcements"
            description="News and notices for everyone in the workspace."
            width="max-w-3xl"
            actions={
                can_manage && (
                    <Button onClick={() => setEditing('new')}>
                        <Megaphone aria-hidden="true" />
                        New announcement
                    </Button>
                )
            }
        >
            {announcements.data.length === 0 ? (
                <EmptyNote>No announcements yet.</EmptyNote>
            ) : (
                <ul className="space-y-4">
                    {announcements.data.map((announcement) => (
                        <li key={announcement.id}>
                            <article className="rounded-xl border border-border bg-card p-4 sm:p-5">
                                <header className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <h2 className="flex items-center gap-2 text-base font-semibold">
                                            {announcement.is_pinned && (
                                                <Pin
                                                    aria-label="Pinned"
                                                    className="size-4 shrink-0 text-primary"
                                                />
                                            )}
                                            {announcement.title}
                                        </h2>
                                        <div className="mt-1 flex items-center gap-2 text-xs text-muted-foreground">
                                            {announcement.author && (
                                                <PersonAvatar
                                                    person={announcement.author}
                                                    className="size-5"
                                                />
                                            )}
                                            <span>
                                                {announcement.author?.name ??
                                                    'Someone'}
                                                {announcement.created_at &&
                                                    ` · ${new Date(announcement.created_at).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })}`}
                                            </span>
                                            {announcement.channel && (
                                                <>
                                                    <span aria-hidden="true">
                                                        ·
                                                    </span>
                                                    <Link
                                                        href={showChannel({
                                                            workspace:
                                                                workspace.slug,
                                                            channel:
                                                                announcement
                                                                    .channel
                                                                    .slug,
                                                        })}
                                                        className="font-medium hover:text-foreground hover:underline"
                                                    >
                                                        #
                                                        {
                                                            announcement.channel
                                                                .name
                                                        }
                                                    </Link>
                                                    {announcement.notified_everyone && (
                                                        <span>
                                                            · everyone notified
                                                        </span>
                                                    )}
                                                </>
                                            )}
                                        </div>
                                    </div>
                                    {can_manage && (
                                        <div className="flex">
                                            <Button
                                                size="icon"
                                                variant="ghost"
                                                aria-label="Edit announcement"
                                                onClick={() =>
                                                    setEditing(announcement)
                                                }
                                            >
                                                <Pencil aria-hidden="true" />
                                            </Button>
                                            <DeleteButton
                                                label="Delete announcement"
                                                onConfirm={() =>
                                                    router.delete(
                                                        destroyAnnouncement({
                                                            workspace:
                                                                workspace.slug,
                                                            announcement:
                                                                announcement.id,
                                                        }).url,
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            />
                                        </div>
                                    )}
                                </header>
                                <p className="mt-3 text-sm leading-relaxed whitespace-pre-line">
                                    {announcement.body}
                                </p>
                            </article>
                        </li>
                    ))}
                </ul>
            )}

            {announcements.meta.last_page > 1 && (
                <nav
                    className="flex items-center justify-between text-sm"
                    aria-label="Pagination"
                >
                    {announcements.links.prev ? (
                        <Link
                            href={announcements.links.prev}
                            className="font-medium"
                        >
                            Newer
                        </Link>
                    ) : (
                        <span />
                    )}
                    <span className="text-muted-foreground">
                        Page {announcements.meta.current_page} of{' '}
                        {announcements.meta.last_page}
                    </span>
                    {announcements.links.next ? (
                        <Link
                            href={announcements.links.next}
                            className="font-medium"
                        >
                            Older
                        </Link>
                    ) : (
                        <span />
                    )}
                </nav>
            )}

            {editing !== null && (
                <AnnouncementDialog
                    key={editing === 'new' ? 'new' : editing.id}
                    announcement={editing === 'new' ? null : editing}
                    channels={channels}
                    onClose={() => setEditing(null)}
                />
            )}
        </HrPage>
    );
}
