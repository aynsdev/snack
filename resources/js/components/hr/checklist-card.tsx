import { router, useForm } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { FormDialog } from '@/components/hr/hr-dialogs';
import {
    Field,
    formatDate,
    PersonAvatar,
    SelectField,
    StatusPill,
    todayString,
} from '@/components/hr/hr-ui';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { useWorkspace } from '@/hooks/use-workspace';
import { cn } from '@/lib/utils';
import { cancel, complete, store } from '@/routes/hr/checklists';
import { toggle } from '@/routes/hr/checklists/tasks';
import type {
    ChecklistTask,
    ChecklistTemplate,
    EmployeeChecklist,
    Option,
} from '@/types/hr';

/**
 * Ticks a checklist step off, or back on. Shared by the boards, the
 * employee's file, and the HR home.
 */
export function TaskCheckbox({ task }: { task: ChecklistTask }) {
    const workspace = useWorkspace();

    return (
        <Checkbox
            checked={task.completed_at !== null}
            disabled={!task.can_toggle}
            aria-label={`${task.completed_at ? 'Reopen' : 'Complete'} ${task.title}`}
            onCheckedChange={() =>
                router.post(
                    toggle({
                        workspace: workspace.slug,
                        employeeChecklistTask: task.id,
                    }).url,
                    {},
                    { preserveScroll: true },
                )
            }
        />
    );
}

/** "Due Oct 3", red once it has passed. */
export function TaskDue({ task }: { task: ChecklistTask }) {
    if (!task.due_on || task.completed_at) {
        return null;
    }

    return (
        <span
            className={cn(
                'text-xs tabular-nums',
                task.is_overdue
                    ? 'text-rose-600 dark:text-rose-400'
                    : 'text-muted-foreground',
            )}
        >
            {task.is_overdue ? 'Overdue · ' : 'Due '}
            {formatDate(task.due_on, { month: 'short', day: 'numeric' })}
        </span>
    );
}

/**
 * One person's onboarding or offboarding: progress, every step, and — for
 * HR — completing or cancelling it.
 */
export function ChecklistCard({
    checklist,
    defaultOpen = false,
}: {
    checklist: EmployeeChecklist;
    defaultOpen?: boolean;
}) {
    const workspace = useWorkspace();
    const [open, setOpen] = useState(defaultOpen);
    const ids = {
        workspace: workspace.slug,
        employeeChecklist: checklist.id,
    };
    const percent =
        checklist.total === 0 ? 0 : (checklist.done / checklist.total) * 100;
    const isOffboarding = checklist.kind === 'offboarding';

    function close(action: 'complete' | 'cancel') {
        const message =
            action === 'complete'
                ? isOffboarding
                    ? `Complete ${checklist.employee.name}'s offboarding? This marks them terminated as of their last day.`
                    : `Complete ${checklist.employee.name}'s onboarding?`
                : `Cancel ${checklist.employee.name}'s ${checklist.kind_label.toLowerCase()}?`;

        if (!confirm(message)) {
            return;
        }

        router.post(
            (action === 'complete' ? complete : cancel)(ids).url,
            {},
            { preserveScroll: true },
        );
    }

    return (
        <li className="rounded-xl border border-border bg-card">
            <div className="flex flex-wrap items-center gap-3 p-3">
                <button
                    type="button"
                    onClick={() => setOpen(!open)}
                    aria-expanded={open}
                    className="flex min-w-0 flex-1 items-center gap-3 text-left"
                >
                    <ChevronDown
                        aria-hidden="true"
                        className={cn(
                            'size-4 shrink-0 text-muted-foreground transition-transform',
                            !open && '-rotate-90',
                        )}
                    />
                    <PersonAvatar
                        person={checklist.employee}
                        className="size-8"
                    />
                    <span className="min-w-0">
                        <span className="block truncate text-sm font-medium">
                            {checklist.employee.name}
                        </span>
                        <span className="block truncate text-xs text-muted-foreground">
                            {checklist.name}
                            {checklist.target_on &&
                                ` · ${isOffboarding ? 'last day' : 'starts'} ${formatDate(checklist.target_on)}`}
                        </span>
                    </span>
                </button>
                <span className="flex items-center gap-2">
                    <span
                        className="h-1.5 w-20 overflow-hidden rounded-full bg-muted"
                        role="img"
                        aria-label={`${checklist.done} of ${checklist.total} steps done`}
                    >
                        <span
                            className="block h-full rounded-full bg-emerald-500"
                            style={{ width: `${percent}%` }}
                        />
                    </span>
                    <span className="text-xs text-muted-foreground tabular-nums">
                        {checklist.done}/{checklist.total}
                    </span>
                </span>
                {checklist.status !== 'in_progress' && (
                    <StatusPill
                        status={
                            checklist.status === 'completed'
                                ? 'approved'
                                : 'cancelled'
                        }
                        label={checklist.status_label}
                    />
                )}
            </div>

            {checklist.equipment_held !== null &&
                checklist.equipment_held > 0 && (
                    <p className="-mt-1 px-3 pb-3 text-xs text-amber-600 dark:text-amber-400">
                        Still has {checklist.equipment_held} company{' '}
                        {checklist.equipment_held === 1 ? 'item' : 'items'} to
                        hand back.
                    </p>
                )}

            {open && (
                <div className="border-t border-border">
                    <ul className="divide-y divide-border">
                        {checklist.tasks.map((task) => (
                            <li
                                key={task.id}
                                className="flex items-start gap-3 px-3 py-2"
                            >
                                <span className="pt-0.5">
                                    <TaskCheckbox task={task} />
                                </span>
                                <span className="min-w-0 flex-1">
                                    <span
                                        className={cn(
                                            'block text-sm',
                                            task.completed_at &&
                                                'text-muted-foreground line-through',
                                        )}
                                    >
                                        {task.title}
                                    </span>
                                    <span className="block text-xs text-muted-foreground">
                                        {task.assignee ?? 'Unassigned'}
                                        {task.completed_by &&
                                            ` · done by ${task.completed_by}`}
                                    </span>
                                </span>
                                <TaskDue task={task} />
                            </li>
                        ))}
                    </ul>
                    {checklist.can_close && (
                        <div className="flex justify-end gap-2 border-t border-border p-3">
                            <Button
                                size="sm"
                                variant="ghost"
                                onClick={() => close('cancel')}
                            >
                                Cancel {checklist.kind_label.toLowerCase()}
                            </Button>
                            <Button
                                size="sm"
                                variant={
                                    checklist.done === checklist.total
                                        ? 'default'
                                        : 'outline'
                                }
                                onClick={() => close('complete')}
                            >
                                Complete {checklist.kind_label.toLowerCase()}
                            </Button>
                        </div>
                    )}
                </div>
            )}
        </li>
    );
}

/**
 * Starting somebody's onboarding or offboarding from one of the templates.
 * With `employeeId` the person is fixed — the dialog is opened from their
 * file.
 */
export function StartChecklistDialog({
    kind,
    templates,
    employees = [],
    employeeId,
    onClose,
}: {
    kind: 'onboarding' | 'offboarding';
    templates: ChecklistTemplate[];
    employees?: Option[];
    employeeId?: number;
    onClose: () => void;
}) {
    const workspace = useWorkspace();
    const ofKind = templates.filter((template) => template.kind === kind);
    const isOffboarding = kind === 'offboarding';
    const form = useForm({
        employee_id: employeeId ? String(employeeId) : '',
        checklist_template_id: ofKind[0] ? String(ofKind[0].id) : '',
        starts_on: todayString(),
        target_on: '',
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            employee_id: Number(data.employee_id),
            checklist_template_id: Number(data.checklist_template_id),
            target_on: data.target_on === '' ? null : data.target_on,
        }));
        form.post(store(workspace.slug).url, {
            preserveScroll: true,
            onSuccess: onClose,
        });
    }

    return (
        <FormDialog
            title={isOffboarding ? 'Start offboarding' : 'Start onboarding'}
            description="Everybody given a step gets a message with what to do and by when."
            onClose={onClose}
            onSubmit={submit}
            processing={form.processing}
            submitLabel="Start"
        >
            {employeeId === undefined && (
                <SelectField
                    id="checklist-employee"
                    label="Employee"
                    value={form.data.employee_id}
                    onChange={(value) => form.setData('employee_id', value)}
                    options={employees}
                    error={form.errors.employee_id}
                />
            )}
            {employeeId !== undefined && form.errors.employee_id && (
                <p className="text-sm text-destructive">
                    {form.errors.employee_id}
                </p>
            )}
            <SelectField
                id="checklist-template"
                label="Template"
                value={form.data.checklist_template_id}
                onChange={(value) =>
                    form.setData('checklist_template_id', value)
                }
                options={ofKind.map((template) => ({
                    value: String(template.id),
                    label: `${template.name} (${template.tasks.length} steps)`,
                }))}
                error={form.errors.checklist_template_id}
            />
            <div className="grid gap-4 sm:grid-cols-2">
                <Field
                    label="Start"
                    htmlFor="checklist-starts"
                    error={form.errors.starts_on}
                >
                    <Input
                        id="checklist-starts"
                        type="date"
                        value={form.data.starts_on}
                        onChange={(event) =>
                            form.setData('starts_on', event.target.value)
                        }
                        required
                    />
                </Field>
                <Field
                    label={isOffboarding ? 'Last day' : 'First day'}
                    htmlFor="checklist-target"
                    error={form.errors.target_on}
                >
                    <Input
                        id="checklist-target"
                        type="date"
                        value={form.data.target_on}
                        onChange={(event) =>
                            form.setData('target_on', event.target.value)
                        }
                        required={isOffboarding}
                    />
                </Field>
            </div>
            <p className="text-xs text-muted-foreground">
                Step due dates count from the {isOffboarding ? 'last' : 'first'}{' '}
                day.
            </p>
        </FormDialog>
    );
}
