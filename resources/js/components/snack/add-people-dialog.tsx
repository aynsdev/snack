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
import { store as addParticipants } from '@/routes/conversations/participants';
import type { Conversation, SnackUser } from '@/types';

/**
 * Adding people to a group conversation.
 *
 * Only ever rendered for a group: a direct message cannot gain a third person
 * without breaking the uniqueness its participant hash guarantees, and the server
 * refuses regardless of what the UI offers.
 *
 * People already in the conversation are filtered out of the picker rather than
 * shown as unavailable. Adding them again is harmless — the server treats it as a
 * no-op — but offering it invites the question of what it would do.
 */
export function AddPeopleDialog({
    conversation,
    workspaceSlug,
    open,
    onOpenChange,
}: {
    conversation: Conversation;
    workspaceSlug: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Add people</DialogTitle>
                    <DialogDescription>
                        They will see everything said from here on, and the
                        conversation will say who added them.
                    </DialogDescription>
                </DialogHeader>

                {/* A child, so its selection unmounts with the dialog. */}
                <AddPeopleForm
                    conversation={conversation}
                    workspaceSlug={workspaceSlug}
                    onDone={() => onOpenChange(false)}
                />
            </DialogContent>
        </Dialog>
    );
}

function AddPeopleForm({
    conversation,
    workspaceSlug,
    onDone,
}: {
    conversation: Conversation;
    workspaceSlug: string;
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
            addParticipants.url({
                workspace: workspaceSlug,
                conversation: conversation.id,
            }),
            { participant_ids: selected.map((person) => person.id) },
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
                excludeIds={conversation.participants.map(
                    (participant) => participant.id,
                )}
                emptyMessage="Everyone in this workspace is already here."
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
                    Add to conversation
                </Button>
            </DialogFooter>
        </>
    );
}
