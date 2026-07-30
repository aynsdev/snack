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
import { update as updateConversation } from '@/routes/conversations';
import type { Conversation } from '@/types';

/**
 * Naming or renaming a group conversation.
 *
 * Submitting an empty field clears the name, returning the conversation to being
 * titled by whoever is in it — which is why the field is not required and the
 * hint says so out loud.
 *
 * Only rendered for a group; a direct message is titled by the other person and
 * the server refuses to name it.
 */
export function RenameConversationDialog({
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
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Rename conversation</DialogTitle>
                    <DialogDescription>
                        Leave this empty to go back to naming it after the
                        people in it.
                    </DialogDescription>
                </DialogHeader>

                <Form
                    action={updateConversation({
                        workspace: workspaceSlug,
                        conversation: conversation.id,
                    })}
                    options={{ preserveScroll: true }}
                    onSuccess={() => onOpenChange(false)}
                    className="space-y-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="space-y-2">
                                <Label htmlFor="conversation-name">Name</Label>

                                <Input
                                    id="conversation-name"
                                    name="name"
                                    autoFocus
                                    defaultValue={conversation.name ?? ''}
                                    placeholder="Launch plans"
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
                                    Save name
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
