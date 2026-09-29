import { Link, router, useForm } from '@inertiajs/react';
import { Megaphone, Pencil, Pin } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { DeleteButton, FormDialog } from '@/components/hr/hr-dialogs';
import {
    EmptyNote,
    Field,
    HrPage,
    PersonAvatar,
    SelectField,
} from '@/components/hr/hr-ui';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { useWorkspace } from '@/hooks/use-workspace';
import { show as showChannel } from '@/routes/channels';
import {
    destroy as destroyAnnouncement,
    store as storeAnnouncement,
    update as updateAnnouncement,
} from '@/routes/hr/announcements';
import type { Announcement, Paginated } from '@/types/hr';

type PostableChannel = {
    id: number;
    name: string;
    type: string;
    is_default: boolean;
};

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

function AnnouncementDialog({
    announcement,
    channels,
    onClose,
}: {
    announcement: Announcement | null;
    channels: PostableChannel[];
    onClose: () => void;
}) {
    const workspace = useWorkspace();
    const form = useForm({
        title: announcement?.title ?? '',
        body: announcement?.body ?? '',
        is_pinned: announcement?.is_pinned ?? false,
        // The server lists announcement channels, then default ones, first —
        // so the first entry is the natural place for a company-wide notice.
        channel_id: channels[0] ? String(channels[0].id) : '',
        notify_everyone: true,
    });

    function submit(event: FormEvent) {
        event.preventDefault();

        form.transform((data) => ({
            ...data,
            channel_id: data.channel_id === '' ? null : Number(data.channel_id),
            notify_everyone: data.channel_id !== '' && data.notify_everyone,
        }));

        if (announcement) {
            form.patch(
                updateAnnouncement({
                    workspace: workspace.slug,
                    announcement: announcement.id,
                }).url,
                { preserveScroll: true, onSuccess: onClose },
            );
        } else {
            form.post(storeAnnouncement(workspace.slug).url, {
                preserveScroll: true,
                onSuccess: onClose,
            });
        }
    }

    return (
        <FormDialog
            title={announcement ? 'Edit announcement' : 'New announcement'}
            onClose={onClose}
            onSubmit={submit}
            processing={form.processing}
            submitLabel={announcement ? 'Save' : 'Post'}
        >
            <Field
                label="Title"
                htmlFor="announcement-title"
                error={form.errors.title}
            >
                <Input
                    id="announcement-title"
                    value={form.data.title}
                    onChange={(event) =>
                        form.setData('title', event.target.value)
                    }
                    required
                />
            </Field>
            <Field
                label="Message"
                htmlFor="announcement-body"
                error={form.errors.body}
            >
                <Textarea
                    id="announcement-body"
                    value={form.data.body}
                    onChange={(event) =>
                        form.setData('body', event.target.value)
                    }
                    rows={8}
                    required
                />
            </Field>
            <label className="flex items-center gap-2 text-sm">
                <Checkbox
                    checked={form.data.is_pinned}
                    onCheckedChange={(checked) =>
                        form.setData('is_pinned', checked === true)
                    }
                />
                Pin to the top
            </label>

            {announcement ? (
                <p className="rounded-lg bg-muted px-3 py-2 text-xs text-muted-foreground">
                    {announcement.channel
                        ? announcement.is_posted
                            ? `Your changes also update the post in #${announcement.channel.name}. Nobody is notified again.`
                            : `The post in #${announcement.channel.name} was deleted, so only HR is updated.`
                        : 'Published in HR only.'}
                </p>
            ) : (
                <div className="space-y-3 rounded-lg border border-border p-3">
                    <SelectField
                        id="announcement-channel"
                        label="Also post to"
                        value={form.data.channel_id}
                        onChange={(value) => form.setData('channel_id', value)}
                        options={channels.map((channel) => ({
                            value: String(channel.id),
                            label: `#${channel.name}`,
                        }))}
                        error={form.errors.channel_id}
                        allowEmpty
                        emptyLabel="Don't post — publish in HR only"
                    />
                    {form.data.channel_id !== '' && (
                        <label className="flex items-start gap-2 text-sm">
                            <Checkbox
                                className="mt-0.5"
                                checked={form.data.notify_everyone}
                                onCheckedChange={(checked) =>
                                    form.setData(
                                        'notify_everyone',
                                        checked === true,
                                    )
                                }
                            />
                            <span>
                                Notify everyone in the channel
                                <span className="block text-xs text-muted-foreground">
                                    Adds @everyone, so people get a notification
                                    (and an email if they are away), following
                                    their own notification settings.
                                </span>
                            </span>
                        </label>
                    )}
                    {channels.length === 0 && (
                        <p className="text-xs text-muted-foreground">
                            Join a channel to post announcements to it.
                        </p>
                    )}
                </div>
            )}
        </FormDialog>
    );
}
