import { Check, X } from 'lucide-react';
import { useCallback, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

type Decision = 'approve' | 'cancel';

/**
 * Approve and Cancel under an agent's plan. Each posts the same `/approve` or
 * `/cancel` reply the assignee could type, through the thread's normal send.
 */
export function PlanActions({
    onSend,
}: {
    onSend: (body: string) => Promise<unknown>;
}) {
    const [sent, setSent] = useState<Decision | null>(null);
    const [confirmingCancel, setConfirmingCancel] = useState(false);

    const decide = useCallback(
        (decision: Decision) => {
            setSent(decision);
            onSend(`/${decision}`).catch(() => setSent(null));
        },
        [onSend],
    );

    if (sent !== null) {
        return (
            <div className="mt-1.5 flex items-center gap-2 select-none">
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    disabled
                    className="h-7 px-2 text-xs"
                >
                    <Check aria-hidden="true" className="size-3.5" />
                    {sent === 'approve' ? 'Approval sent' : 'Cancel sent'}
                </Button>
            </div>
        );
    }

    return (
        <div
            role="group"
            aria-label="Respond to this plan"
            className="mt-1.5 flex items-center gap-2 select-none"
        >
            <Button
                type="button"
                size="sm"
                className="h-7 px-2 text-xs"
                onClick={() => decide('approve')}
            >
                <Check aria-hidden="true" className="size-3.5" />
                Approve
            </Button>
            <Button
                type="button"
                size="sm"
                variant="ghost"
                className="h-7 px-2 text-xs"
                onClick={() => setConfirmingCancel(true)}
            >
                <X aria-hidden="true" className="size-3.5" />
                Cancel
            </Button>

            <Dialog open={confirmingCancel} onOpenChange={setConfirmingCancel}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Cancel this task run?</DialogTitle>
                        <DialogDescription>
                            The agent stops and the plan is discarded. You can
                            start it again from the task.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setConfirmingCancel(false)}
                        >
                            Keep it
                        </Button>
                        <Button
                            type="button"
                            variant="destructive"
                            onClick={() => {
                                setConfirmingCancel(false);
                                decide('cancel');
                            }}
                        >
                            Cancel run
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}
