import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { Message } from '@/types';

/**
 * Asked before deleting something an agent posted. A thread start is where
 * the agent works a ticket, so deleting it makes the agent post it again;
 * anything else is gone for good.
 */
export function AgentDeleteDialog({
    message,
    open,
    onOpenChange,
    onConfirm,
}: {
    message: Pick<Message, 'parent_message_id' | 'sender'>;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onConfirm: () => void;
}) {
    const isThreadStart = message.parent_message_id === null;
    const owner = message.sender?.name ?? 'Somebody';

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>
                        {isThreadStart
                            ? 'Delete the agent’s thread start?'
                            : 'Delete agent message?'}
                    </DialogTitle>
                    <DialogDescription>
                        {isThreadStart
                            ? `This message belongs to ${owner}'s agent. Deleting the thread start will make the agent re-post it.`
                            : `This message was posted by ${owner}'s agent.`}
                    </DialogDescription>
                </DialogHeader>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                    >
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        variant="destructive"
                        onClick={() => {
                            onOpenChange(false);
                            onConfirm();
                        }}
                    >
                        Delete
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
