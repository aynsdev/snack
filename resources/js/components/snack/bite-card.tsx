import { Check, Sparkles, X } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { useWorkspace } from '@/hooks/use-workspace';
import { ApiError, api } from '@/lib/api';
import { store as decide } from '@/routes/bite/approvals';
import type { BiteCard as BiteCardData } from '@/types';

/**
 * "Shall I create this?" — the one place Bite stops and waits.
 *
 * # Why there is a card here at all
 *
 * Bite reads without asking and writes only with permission. Filing a task is
 * the one thing it can do that other people see: it lands on a board, it
 * notifies an assignee, and it is the sort of thing that is annoying rather
 * than dangerous to get wrong — which is exactly the class of action that
 * deserves a button rather than either a refusal or a silent write.
 *
 * The value is in the summary, not the button. What the person is checking is
 * that the model understood: the right project, the right title, the right
 * person. A confirmation that only said "the assistant would like to use a tool"
 * would be a dialog everybody clicks through, which is worse than no dialog.
 *
 * # Why the state is an override rather than seeded
 *
 * `useState(card)` freezes at first render, and the prop genuinely changes
 * underneath this component — a reload re-reads the decided card from the
 * message row. Holding the local decision as an override and preferring it
 * keeps the optimistic path without going stale. The same shape, and the same
 * reason, as the standup card.
 */
export function BiteCard({
    card,
    messageId,
}: {
    card: BiteCardData;
    messageId: number;
}) {
    const workspace = useWorkspace();

    const [decided, setDecided] = useState<BiteCardData | null>(null);
    const [submitting, setSubmitting] = useState<'approve' | 'reject' | null>(
        null,
    );
    const [error, setError] = useState<string | null>(null);

    const state = decided ?? card;

    async function submit(decision: 'approve' | 'reject') {
        setSubmitting(decision);
        setError(null);

        try {
            const response = await api.post<{ bite: BiteCardData }>(
                decide.url({ workspace: workspace.slug, message: messageId }),
                { decision },
            );

            setDecided(response.bite);
        } catch (failure) {
            /*
             * The buttons come back on failure. Approving runs a chain of
             * provider calls that can time out, and a card stuck showing a
             * spinner forever is one the person cannot retry or dismiss.
             */
            setError(
                failure instanceof ApiError
                    ? failure.userMessage
                    : 'That did not go through. Try again.',
            );
            setSubmitting(null);
        }
    }

    if (state.state !== 'pending') {
        return <DecidedState state={state.state} />;
    }

    return (
        <section className="mt-1.5 max-w-xl overflow-hidden rounded-lg border border-border bg-card">
            <header className="flex items-center gap-2 border-b border-border bg-muted/40 px-3 py-2">
                <Sparkles
                    aria-hidden="true"
                    className="size-3.5 shrink-0 text-muted-foreground"
                />

                <h3 className="text-sm font-semibold">Needs your go-ahead</h3>
            </header>

            <div className="space-y-3 px-3 py-3">
                <ul className="space-y-1.5">
                    {state.approvals.map((approval) => (
                        <li key={approval.id} className="text-sm">
                            {approval.summary}
                        </li>
                    ))}
                </ul>

                {error !== null && (
                    <p role="alert" className="text-xs text-destructive">
                        {error}
                    </p>
                )}

                <div className="flex gap-2">
                    <Button
                        size="sm"
                        onClick={() => submit('approve')}
                        disabled={submitting !== null}
                    >
                        {submitting === 'approve' ? 'Creating…' : 'Create'}
                    </Button>

                    <Button
                        size="sm"
                        variant="ghost"
                        onClick={() => submit('reject')}
                        disabled={submitting !== null}
                    >
                        Cancel
                    </Button>
                </div>
            </div>
        </section>
    );
}

/**
 * What the card becomes once decided.
 *
 * Deliberately small and quiet. What actually happened is in Bite's next
 * message — with the task reference, which is the part worth reading — so this
 * only has to say the buttons are spent and which way it went.
 */
function DecidedState({ state }: { state: 'approved' | 'rejected' }) {
    const approved = state === 'approved';
    const Icon = approved ? Check : X;

    return (
        <p className="mt-1.5 flex items-center gap-1.5 text-xs text-muted-foreground">
            <Icon aria-hidden="true" className="size-3.5 shrink-0" />
            {approved ? 'You approved this.' : 'You cancelled this.'}
        </p>
    );
}
