import { Form } from '@inertiajs/react';
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
    store as storeChannelGroup,
    update as updateChannelGroup,
} from '@/routes/channel-groups';
import type { Channel, ChannelGroup } from '@/types';

/**
 * Naming a section of the channel sidebar, or renaming one.
 *
 * One dialog for both because they are one form with one field: a null `group`
 * is the create case. Splitting them would be two components differing in a
 * verb.
 *
 * `channel` is the channel the section is being made *from* — the "New
 * section…" at the bottom of a channel's own menu, which files it there as part
 * of the same request. See StoreChannelGroupRequest for why that is one call and
 * not two.
 */
export function ChannelSectionDialog({
    workspaceSlug,
    group = null,
    channel = null,
    open,
    onOpenChange,
}: {
    workspaceSlug: string;
    group?: ChannelGroup | null;
    channel?: Channel | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const isRename = group !== null;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>
                        {isRename ? 'Rename section' : 'New section'}
                    </DialogTitle>

                    <DialogDescription>
                        {isRename
                            ? 'Only you see this. Renaming it keeps every channel where it is.'
                            : channel !== null
                              ? `A heading in your channel list. #${channel.slug} goes into it.`
                              : 'A heading in your channel list. Only you see it.'}
                    </DialogDescription>
                </DialogHeader>

                <Form
                    action={
                        isRename
                            ? updateChannelGroup({
                                  workspace: workspaceSlug,
                                  channelGroup: group.id,
                              })
                            : storeChannelGroup(workspaceSlug)
                    }
                    options={{ preserveScroll: true }}
                    onSuccess={() => onOpenChange(false)}
                    className="space-y-4"
                >
                    {({ processing, errors }) => (
                        <>
                            {/*
                             * The channel rides along as a hidden field rather
                             * than in the URL: it is what the new section is
                             * for, not what it is addressed by.
                             */}
                            {!isRename && channel !== null && (
                                <input
                                    type="hidden"
                                    name="channel_id"
                                    value={channel.id}
                                />
                            )}

                            <div className="space-y-2">
                                <Label htmlFor="channel-section-name">
                                    Name
                                </Label>

                                <Input
                                    id="channel-section-name"
                                    name="name"
                                    autoFocus
                                    required
                                    maxLength={60}
                                    defaultValue={group?.name ?? ''}
                                    placeholder="Team"
                                />

                                <InputError message={errors.name} />
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
                                    {isRename ? 'Save name' : 'Create section'}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
