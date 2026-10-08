import { router, useForm } from '@inertiajs/react';
import { LogOut } from 'lucide-react';
import type { FormEvent } from 'react';
import { FormDialog } from '@/components/hr/hr-dialogs';
import { Field, formatDate, todayString } from '@/components/hr/hr-ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { useWorkspace } from '@/hooks/use-workspace';
import { cn } from '@/lib/utils';
import { store, withdraw } from '@/routes/hr/resignations';
import type { Resignation } from '@/types/hr';

/**
 * Where somebody's resignation stands, shown on their file — with a way to
 * withdraw it while HR has not yet decided.
 */
export function ResignationBanner({
    resignation,
}: {
    resignation: Resignation;
}) {
    const workspace = useWorkspace();
    const pending = resignation.status === 'pending';

    return (
        <div
            className={cn(
                'flex flex-wrap items-center gap-3 rounded-xl border p-4 text-sm',
                pending
                    ? 'border-amber-500/40 bg-amber-500/5'
                    : 'border-border bg-muted/40',
            )}
        >
            <LogOut aria-hidden="true" className="size-4 shrink-0" />
            <p className="min-w-0 flex-1">
                {pending ? 'Resignation with HR' : 'Resignation accepted'}
                {' · last day '}
                <span className="font-medium">
                    {formatDate(resignation.last_day_on)}
                </span>
                {resignation.review_note && (
                    <span className="block text-xs text-muted-foreground">
                        {resignation.reviewer}: “{resignation.review_note}”
                    </span>
                )}
            </p>
            {resignation.can.withdraw && (
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() => {
                        if (confirm('Withdraw your resignation?')) {
                            router.post(
                                withdraw({
                                    workspace: workspace.slug,
                                    resignation: resignation.id,
                                }).url,
                                {},
                                { preserveScroll: true },
                            );
                        }
                    }}
                >
                    Withdraw
                </Button>
            )}
        </div>
    );
}

export function ResignDialog({ onClose }: { onClose: () => void }) {
    const workspace = useWorkspace();
    const form = useForm({ reason: '', last_day_on: '' });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(store(workspace.slug).url, {
            preserveScroll: true,
            onSuccess: onClose,
        });
    }

    return (
        <FormDialog
            title="Resign"
            description="Your resignation goes to HR. You can withdraw it until they decide."
            onClose={onClose}
            onSubmit={submit}
            processing={form.processing}
            submitLabel="Send to HR"
        >
            <Field
                label="Last day"
                htmlFor="resign-last-day"
                error={form.errors.last_day_on}
            >
                <Input
                    id="resign-last-day"
                    type="date"
                    min={todayString()}
                    value={form.data.last_day_on}
                    onChange={(event) =>
                        form.setData('last_day_on', event.target.value)
                    }
                    required
                />
            </Field>
            <Field
                label="Letter"
                htmlFor="resign-reason"
                error={form.errors.reason}
            >
                <Textarea
                    id="resign-reason"
                    rows={6}
                    value={form.data.reason}
                    onChange={(event) =>
                        form.setData('reason', event.target.value)
                    }
                    required
                />
            </Field>
        </FormDialog>
    );
}
