import { router } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { destroy as removeMember } from '@/routes/channels/members';
import type { Channel, ChannelMembership } from '@/types';

export function RemoveChannelMemberButton({
    channel,
    membership,
    workspaceSlug,
}: {
    channel: Channel;
    membership: ChannelMembership;
    workspaceSlug: string;
}) {
    const [open, setOpen] = useState(false);
    const [removing, setRemoving] = useState(false);

    const remove = () => {
        if (removing) {
            return;
        }

        setRemoving(true);

        router.delete(
            removeMember.url({
                workspace: workspaceSlug,
                channel: channel.slug,
                member: membership.id,
            }),
            {
                preserveScroll: true,
                onSuccess: () => setOpen(false),
                onFinish: () => setRemoving(false),
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <Button
                type="button"
                variant="ghost"
                size="icon"
                className="size-7 shrink-0 text-muted-foreground hover:text-destructive"
                aria-label={`Remove ${membership.user.name} from channel`}
                onClick={() => setOpen(true)}
            >
                <Trash2 aria-hidden="true" className="size-3.5" />
            </Button>

            <DialogContent role="alertdialog">
                <DialogHeader>
                    <DialogTitle className="text-balance">
                        Remove {membership.user.name} from #{channel.slug}?
                    </DialogTitle>
                    <DialogDescription className="text-pretty">
                        They will lose access to this channel and its message
                        history. You can add them again later.
                    </DialogDescription>
                </DialogHeader>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        disabled={removing}
                        onClick={() => setOpen(false)}
                    >
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        variant="destructive"
                        disabled={removing}
                        onClick={remove}
                    >
                        {removing ? 'Removing…' : 'Remove member'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
