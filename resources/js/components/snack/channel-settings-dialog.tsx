import { Form, router } from '@inertiajs/react';
import { Archive, ArchiveRestore } from 'lucide-react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { update as updateChannel } from '@/routes/channels';
import {
    destroy as restoreChannel,
    store as archiveChannel,
} from '@/routes/channels/archive';
import type { Channel } from '@/types';

/**
 * A channel's shared settings.
 *
 * Shared, not personal: everything here changes the channel for everyone in it,
 * which is why it is gated on channel-admin rights while muting is not. The
 * mute controls live on the notification preferences screen.
 *
 * # Renaming changes the URL
 *
 * The slug follows the name — a channel called "Product Design" that still
 * answers to `#design` would contradict what the interface says it is called
 * everywhere — so the server redirects to the new slug on save. The hint below
 * the field says so, because a link pasted before the rename stops working and
 * that should not be a surprise.
 */
export function ChannelSettingsDialog({
    channel,
    workspaceSlug,
    canAdministerWorkspace,
    open,
    onOpenChange,
}: {
    channel: Channel;
    workspaceSlug: string;
    /**
     * Announcement channels silence everyone but admins, so only a workspace
     * admin may convert a channel into one. The server enforces this too — see
     * UpdateChannelRequest.
     */
    canAdministerWorkspace: boolean;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const args = { workspace: workspaceSlug, channel: channel.slug };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Channel settings</DialogTitle>
                    <DialogDescription>
                        These apply to everyone in #{channel.slug}.
                    </DialogDescription>
                </DialogHeader>

                <Form
                    {...updateChannel.form(args)}
                    options={{ preserveScroll: true }}
                    onSuccess={() => onOpenChange(false)}
                    className="space-y-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="space-y-2">
                                <Label htmlFor="channel-name">Name</Label>

                                <Input
                                    id="channel-name"
                                    name="name"
                                    autoFocus
                                    defaultValue={channel.name}
                                    required
                                />

                                <p className="text-xs text-muted-foreground">
                                    Renaming also changes the channel's address.
                                    Links to <code>#{channel.slug}</code> shared
                                    before the change will stop working.
                                </p>

                                <InputError message={errors.name} />
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="channel-topic">Topic</Label>

                                <Input
                                    id="channel-topic"
                                    name="topic"
                                    defaultValue={channel.topic ?? ''}
                                    placeholder="What is being worked on right now"
                                    maxLength={250}
                                />

                                <InputError message={errors.topic} />
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="channel-description">
                                    Description
                                </Label>

                                <Textarea
                                    id="channel-description"
                                    name="description"
                                    defaultValue={channel.description ?? ''}
                                    rows={3}
                                    maxLength={500}
                                />

                                <InputError message={errors.description} />
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="channel-type">Type</Label>

                                <Select name="type" defaultValue={channel.type}>
                                    <SelectTrigger id="channel-type">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="public">
                                            Public
                                        </SelectItem>
                                        <SelectItem value="private">
                                            Private
                                        </SelectItem>
                                        {/*
                                         * Only offered to workspace admins. A
                                         * channel admin who is an ordinary
                                         * member cannot silence their own
                                         * channel's participants.
                                         */}
                                        {canAdministerWorkspace && (
                                            <SelectItem value="announcement">
                                                Announcement
                                            </SelectItem>
                                        )}
                                    </SelectContent>
                                </Select>

                                <InputError message={errors.type} />
                            </div>

                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={() => onOpenChange(false)}
                                >
                                    Cancel
                                </Button>

                                <Button type="submit" disabled={processing}>
                                    Save changes
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>

                {/*
                 * Outside the form on purpose. Archiving is not an edit — it
                 * closes the channel — and nesting its button inside the form
                 * above would make it a second submit control for something
                 * else entirely.
                 */}
                {!channel.is_default && (
                    <div className="space-y-2 border-t border-border pt-4">
                        <p className="text-sm font-medium">
                            {channel.is_archived ? 'Restore' : 'Archive'}
                        </p>

                        <p className="text-xs text-muted-foreground">
                            {channel.is_archived
                                ? 'Bring this channel back into the sidebar and let people post in it again.'
                                : 'Closes the channel to new messages and takes it out of the sidebar. Nobody is removed and nothing is deleted — the history stays searchable.'}
                        </p>

                        <Button
                            type="button"
                            variant="outline"
                            onClick={() =>
                                channel.is_archived
                                    ? router.delete(restoreChannel.url(args))
                                    : router.post(archiveChannel.url(args))
                            }
                        >
                            {channel.is_archived ? (
                                <>
                                    <ArchiveRestore aria-hidden="true" />
                                    Restore channel
                                </>
                            ) : (
                                <>
                                    <Archive aria-hidden="true" />
                                    Archive channel
                                </>
                            )}
                        </Button>
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}
