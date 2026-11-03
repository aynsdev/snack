import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { FormDialog } from '@/components/hr/hr-dialogs';
import { Field, SelectField } from '@/components/hr/hr-ui';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { useWorkspace } from '@/hooks/use-workspace';
import {
    store as storeAnnouncement,
    update as updateAnnouncement,
} from '@/routes/hr/announcements';
import type { Announcement } from '@/types/hr';

/** A channel the author is in and may post the announcement to. */
export type PostableChannel = {
    id: number;
    name: string;
    type: string;
    is_default: boolean;
};

/**
 * Writing a new announcement, or editing one. A new one can start from a
 * draft — a holiday's, say — and be posted to a channel as it is published.
 */
export function AnnouncementDialog({
    announcement,
    channels,
    draft,
    onClose,
}: {
    announcement: Announcement | null;
    channels: PostableChannel[];
    draft?: { title: string; body: string };
    onClose: () => void;
}) {
    const workspace = useWorkspace();
    const form = useForm({
        title: announcement?.title ?? draft?.title ?? '',
        body: announcement?.body ?? draft?.body ?? '',
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
