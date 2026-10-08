import { useForm } from '@inertiajs/react';
import { Check, Plus, X } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import {
    ChecklistCard,
    StartChecklistDialog,
} from '@/components/hr/checklist-card';
import { FormDialog } from '@/components/hr/hr-dialogs';
import {
    EmptyNote,
    Field,
    formatDate,
    HrPage,
    PersonAvatar,
    Section,
} from '@/components/hr/hr-ui';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { useWorkspace } from '@/hooks/use-workspace';
import { accept, reject } from '@/routes/hr/resignations';
import type {
    ChecklistTemplate,
    EmployeeChecklist,
    Option,
    Resignation,
} from '@/types/hr';

type Props = {
    kind: 'onboarding' | 'offboarding';
    active: EmployeeChecklist[];
    recent: EmployeeChecklist[];
    templates: ChecklistTemplate[];
    employees: Option[];
    resignations: Resignation[];
};

/**
 * HR's onboarding or offboarding board: who is coming or going, how far along
 * they are, and — for offboarding — resignations waiting on a decision.
 */
export default function ChecklistsPage({
    kind,
    active,
    recent,
    templates,
    employees,
    resignations,
}: Props) {
    const [starting, setStarting] = useState(false);
    const [reviewing, setReviewing] = useState<{
        resignation: Resignation;
        decision: 'accept' | 'reject';
    } | null>(null);
    const isOffboarding = kind === 'offboarding';
    const label = isOffboarding ? 'Offboarding' : 'Onboarding';

    return (
        <HrPage
            title={label}
            description={
                isOffboarding
                    ? 'People leaving, and the steps to see them out.'
                    : 'New starters, and the steps to bring them in.'
            }
            actions={
                <Button onClick={() => setStarting(true)}>
                    <Plus aria-hidden="true" />
                    Start {label.toLowerCase()}
                </Button>
            }
        >
            {isOffboarding && resignations.length > 0 && (
                <Section title={`Resignations (${resignations.length})`}>
                    <ul className="divide-y divide-border">
                        {resignations.map((resignation) => (
                            <li
                                key={resignation.id}
                                className="flex flex-wrap items-start gap-3 py-3 first:pt-0 last:pb-0"
                            >
                                <PersonAvatar
                                    person={resignation.employee}
                                    className="size-8"
                                />
                                <div className="min-w-0 flex-1">
                                    <p className="text-sm font-medium">
                                        {resignation.employee.name}
                                        <span className="font-normal text-muted-foreground">
                                            {' '}
                                            · last day{' '}
                                            {formatDate(
                                                resignation.last_day_on,
                                            )}
                                        </span>
                                    </p>
                                    <p className="mt-0.5 text-sm whitespace-pre-line text-muted-foreground">
                                        {resignation.reason}
                                    </p>
                                </div>
                                {resignation.can.review && (
                                    <div className="flex gap-1.5">
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() =>
                                                setReviewing({
                                                    resignation,
                                                    decision: 'reject',
                                                })
                                            }
                                        >
                                            <X aria-hidden="true" />
                                            Reject
                                        </Button>
                                        <Button
                                            size="sm"
                                            onClick={() =>
                                                setReviewing({
                                                    resignation,
                                                    decision: 'accept',
                                                })
                                            }
                                        >
                                            <Check aria-hidden="true" />
                                            Accept
                                        </Button>
                                    </div>
                                )}
                            </li>
                        ))}
                    </ul>
                </Section>
            )}

            <Section title={`In progress (${active.length})`}>
                {active.length === 0 ? (
                    <EmptyNote>
                        Nobody is being {isOffboarding ? 'off' : 'on'}boarded
                        right now.
                    </EmptyNote>
                ) : (
                    <ul className="space-y-2">
                        {active.map((checklist) => (
                            <ChecklistCard
                                key={checklist.id}
                                checklist={checklist}
                                defaultOpen={active.length === 1}
                            />
                        ))}
                    </ul>
                )}
            </Section>

            {recent.length > 0 && (
                <Section title="Recently closed">
                    <ul className="space-y-2">
                        {recent.map((checklist) => (
                            <ChecklistCard
                                key={checklist.id}
                                checklist={checklist}
                            />
                        ))}
                    </ul>
                </Section>
            )}

            {starting && (
                <StartChecklistDialog
                    kind={kind}
                    templates={templates}
                    employees={employees}
                    onClose={() => setStarting(false)}
                />
            )}
            {reviewing && (
                <ResignationReviewDialog
                    resignation={reviewing.resignation}
                    decision={reviewing.decision}
                    onClose={() => setReviewing(null)}
                />
            )}
        </HrPage>
    );
}

function ResignationReviewDialog({
    resignation,
    decision,
    onClose,
}: {
    resignation: Resignation;
    decision: 'accept' | 'reject';
    onClose: () => void;
}) {
    const workspace = useWorkspace();
    const form = useForm({ note: '' });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(
            (decision === 'accept' ? accept : reject)({
                workspace: workspace.slug,
                resignation: resignation.id,
            }).url,
            { preserveScroll: true, onSuccess: onClose },
        );
    }

    return (
        <FormDialog
            title={
                decision === 'accept'
                    ? 'Accept resignation'
                    : 'Reject resignation'
            }
            description={
                decision === 'accept'
                    ? `${resignation.employee.name}'s offboarding will start, counting down to ${formatDate(resignation.last_day_on)}.`
                    : resignation.employee.name
            }
            onClose={onClose}
            onSubmit={submit}
            processing={form.processing}
            submitLabel={decision === 'accept' ? 'Accept' : 'Reject'}
        >
            <Field
                label="Note to the employee"
                htmlFor="resignation-note"
                error={form.errors.note}
            >
                <Textarea
                    id="resignation-note"
                    rows={3}
                    value={form.data.note}
                    onChange={(event) =>
                        form.setData('note', event.target.value)
                    }
                    placeholder="Optional"
                />
            </Field>
        </FormDialog>
    );
}
