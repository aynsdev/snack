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
import { Input } from '@/components/ui/input';
import { store as startConversation } from '@/routes/conversations';
import type { SnackUser } from '@/types';

/**
 * Starting a new direct or group conversation.
 *
 * Choosing one person opens a direct message; choosing several opens a group, and
 * only then does a name field appear. There is no mode to pick first, because the
 * number of people you chose already says which you meant.
 */
export function NewMessageDialog({
    workspaceSlug,
    open,
    onOpenChange,
}: {
    workspaceSlug: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>New message</DialogTitle>
                    <DialogDescription>
                        Choose one person for a direct message, or several to
                        start a group.
                    </DialogDescription>
                </DialogHeader>

                {/*
                 * The form is a child so that its selection unmounts with the
                 * dialog. Holding that state out here would mean clearing it in
                 * an effect on every close — a cascading render to undo
                 * something React will discard for free.
                 */}
                <NewMessageForm
                    workspaceSlug={workspaceSlug}
                    onDone={() => onOpenChange(false)}
                />
            </DialogContent>
        </Dialog>
    );
}

function NewMessageForm({
    workspaceSlug,
    onDone,
}: {
    workspaceSlug: string;
    onDone: () => void;
}) {
    const [selected, setSelected] = useState<SnackUser[]>([]);
    const [name, setName] = useState('');
    const [submitting, setSubmitting] = useState(false);

    const isGroup = selected.length > 1;

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
            startConversation.url(workspaceSlug),
            {
                participant_ids: selected.map((person) => person.id),
                // A direct conversation is never named. The server drops the
                // field rather than trusting this; not sending it just keeps the
                // request honest about what was asked for.
                name: isGroup && name.trim() !== '' ? name.trim() : null,
            },
            {
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
            />

            {isGroup && (
                <Input
                    value={name}
                    onChange={(event) => setName(event.target.value)}
                    placeholder="Name this group (optional)"
                    aria-label="Group name"
                />
            )}

            <DialogFooter>
                <Button type="button" variant="ghost" onClick={onDone}>
                    Cancel
                </Button>

                <Button
                    type="button"
                    onClick={submit}
                    disabled={selected.length === 0 || submitting}
                >
                    {isGroup ? 'Start group' : 'Start message'}
                </Button>
            </DialogFooter>
        </>
    );
}
