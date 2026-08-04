import { router } from '@inertiajs/react';
import { useCallback, useState } from 'react';
import { PeoplePicker } from '@/components/snack/people-picker';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { store as addMembers } from '@/routes/channels/members';
import type { Channel, SnackUser } from '@/types';

/**
 * Adding people to a channel.
 *
 * The counterpart of {@see AddPeopleDialog} for conversations, and only rendered
 * for someone who administers the channel — the server refuses regardless, but
 * offering a control that always fails is worse than not offering it.
 *
 * The people already in the channel are filtered out of the picker rather than
 * shown as unavailable. Adding them again is harmless — the server treats it as
 * a no-op — but offering it invites the question of what it would do.
 */
export function AddChannelMembersDialog({
    channel,
    workspaceSlug,
    members,
    open,
    onOpenChange,
}: {
    channel: Channel;
    workspaceSlug: string;
    /** The roster, when it has arrived. Deferred, so it may not have. */
    members: SnackUser[] | undefined;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Add people to #{channel.slug}</DialogTitle>
                    <DialogDescription>
                        {channel.is_private
                            ? 'This channel is private, so being added is the only way in. They will see everything said from here on.'
                            : 'They will see everything said from here on, and the channel will say who added them.'}
                    </DialogDescription>
                </DialogHeader>

                {/* A child, so its selection unmounts with the dialog. */}
                <AddChannelMembersForm
                    channel={channel}
                    workspaceSlug={workspaceSlug}
                    members={members}
                    onDone={() => onOpenChange(false)}
                />
            </DialogContent>
        </Dialog>
    );
}

function AddChannelMembersForm({
    channel,
    workspaceSlug,
    members,
    onDone,
}: {
    channel: Channel;
    workspaceSlug: string;
    members: SnackUser[] | undefined;
    onDone: () => void;
}) {
    const [selected, setSelected] = useState<SnackUser[]>([]);
    const [submitting, setSubmitting] = useState(false);

    const toggle = useCallback((person: SnackUser) => {
        setSelected((current) =>
            current.some((chosen) => chosen.id === person.id)
                ? current.filter((chosen) => chosen.id !== person.id)
                : [...current, person],
        );
    }, []);

    const submit = () => {
        if (selected.length === 0 || submitting) {
            return;
        }

        setSubmitting(true);

        router.post(
            addMembers.url({
                workspace: workspaceSlug,
                channel: channel.slug,
            }),
            { user_ids: selected.map((person) => person.id) },
            {
                preserveScroll: true,
                onSuccess: onDone,
                onFinish: () => setSubmitting(false),
            },
        );
    };

    return (
        <>
            <PeoplePicker
                workspaceSlug={workspaceSlug}
                selected={selected}
                onToggle={toggle}
                /*
                 * The roster is capped by the server, so on a very large channel
                 * this hides most but not all of the existing members. The
                 * server is the authority either way: re-adding somebody is a
                 * no-op that says so.
                 */
                excludeIds={(members ?? []).map((member) => member.id)}
                emptyMessage="Everyone in this workspace is already in this channel."
            />

            <DialogFooter>
                <Button type="button" variant="ghost" onClick={onDone}>
                    Cancel
                </Button>

                <Button
                    type="button"
                    onClick={submit}
                    disabled={selected.length === 0 || submitting}
                >
                    Add to channel
                </Button>
            </DialogFooter>
        </>
    );
}
