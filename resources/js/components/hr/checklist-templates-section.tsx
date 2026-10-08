import { router, useForm } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Pencil, Plus, Trash2 } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { DeleteButton, FormDialog } from '@/components/hr/hr-dialogs';
import { Field, Section, SelectField } from '@/components/hr/hr-ui';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { useWorkspace } from '@/hooks/use-workspace';
import { destroy, store, update } from '@/routes/hr/checklist-templates';
import { update as updateSettings } from '@/routes/hr/settings';
import type { ChecklistAssignee, ChecklistTemplate } from '@/types/hr';

const ASSIGNEES: { value: ChecklistAssignee; label: string }[] = [
    { value: 'hr', label: 'HR' },
    { value: 'manager', label: 'Manager' },
    { value: 'employee', label: 'Employee' },
];

type Kind = ChecklistTemplate['kind'];
type TaskRow = ChecklistTemplate['tasks'][number];

/**
 * The onboarding and offboarding templates, and whether new members are
 * onboarded automatically. Lives in HR settings.
 */
export function ChecklistTemplatesSection({
    templates,
    autoOnboarding,
}: {
    templates: ChecklistTemplate[];
    autoOnboarding: boolean;
}) {
    const workspace = useWorkspace();
    const [editing, setEditing] = useState<
        ChecklistTemplate | { kind: Kind } | null
    >(null);

    return (
        <Section title="Onboarding and offboarding">
            <label className="mb-4 flex items-start gap-2 text-sm">
                <Checkbox
                    checked={autoOnboarding}
                    onCheckedChange={(checked) =>
                        router.patch(
                            updateSettings(workspace.slug).url,
                            { auto_onboarding: checked === true },
                            { preserveScroll: true },
                        )
                    }
                    className="mt-0.5"
                />
                <span>
                    Start the default onboarding when somebody joins the
                    workspace
                    <span className="block text-xs text-muted-foreground">
                        HR steps go to the workspace owner.
                    </span>
                </span>
            </label>

            {(['onboarding', 'offboarding'] as const).map((kind) => (
                <div key={kind} className="mb-4 last:mb-0">
                    <div className="mb-1 flex items-center justify-between">
                        <h3 className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                            {kind === 'onboarding'
                                ? 'Onboarding templates'
                                : 'Offboarding templates'}
                        </h3>
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={() => setEditing({ kind })}
                        >
                            <Plus aria-hidden="true" />
                            Add
                        </Button>
                    </div>
                    <ul className="divide-y divide-border">
                        {templates
                            .filter((template) => template.kind === kind)
                            .map((template) => (
                                <li
                                    key={template.id}
                                    className="flex items-center gap-3 py-2"
                                >
                                    <div className="min-w-0 flex-1">
                                        <p className="text-sm font-medium">
                                            {template.name}
                                            {template.is_default && (
                                                <span className="ml-2 rounded-full bg-muted px-2 py-0.5 text-xs font-normal text-muted-foreground">
                                                    Default
                                                </span>
                                            )}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {template.tasks.length} steps
                                        </p>
                                    </div>
                                    <Button
                                        size="icon"
                                        variant="ghost"
                                        aria-label={`Edit ${template.name}`}
                                        onClick={() => setEditing(template)}
                                    >
                                        <Pencil aria-hidden="true" />
                                    </Button>
                                    <DeleteButton
                                        label={`Delete ${template.name}`}
                                        onConfirm={() =>
                                            router.delete(
                                                destroy({
                                                    workspace: workspace.slug,
                                                    checklistTemplate:
                                                        template.id,
                                                }).url,
                                                { preserveScroll: true },
                                            )
                                        }
                                    />
                                </li>
                            ))}
                    </ul>
                </div>
            ))}

            {editing !== null && (
                <TemplateDialog
                    key={'id' in editing ? editing.id : `new-${editing.kind}`}
                    template={'id' in editing ? editing : null}
                    kind={editing.kind}
                    onClose={() => setEditing(null)}
                />
            )}
        </Section>
    );
}

function TemplateDialog({
    template,
    kind,
    onClose,
}: {
    template: ChecklistTemplate | null;
    kind: Kind;
    onClose: () => void;
}) {
    const workspace = useWorkspace();
    const form = useForm<{
        name: string;
        is_default: boolean;
        tasks: TaskRow[];
    }>({
        name: template?.name ?? '',
        is_default: template?.is_default ?? false,
        tasks: template?.tasks ?? [
            {
                title: '',
                description: null,
                assignee_role: 'hr',
                due_offset_days: 0,
            },
        ],
    });

    function setTask(index: number, changes: Partial<TaskRow>) {
        form.setData(
            'tasks',
            form.data.tasks.map((task, position) =>
                position === index ? { ...task, ...changes } : task,
            ),
        );
    }

    function move(index: number, by: -1 | 1) {
        const tasks = [...form.data.tasks];
        [tasks[index], tasks[index + by]] = [tasks[index + by], tasks[index]];
        form.setData('tasks', tasks);
    }

    function submit(event: FormEvent) {
        event.preventDefault();

        if (template) {
            form.patch(
                update({
                    workspace: workspace.slug,
                    checklistTemplate: template.id,
                }).url,
                { preserveScroll: true, onSuccess: onClose },
            );
        } else {
            form.transform((data) => ({ ...data, kind }));
            form.post(store(workspace.slug).url, {
                preserveScroll: true,
                onSuccess: onClose,
            });
        }
    }

    const offsetHint =
        kind === 'offboarding'
            ? 'Days from the last day (negative = before)'
            : 'Days from the first day (negative = before)';

    return (
        <FormDialog
            title={template ? `Edit ${template.name}` : `New ${kind} template`}
            description="Changes apply to checklists started from now on."
            onClose={onClose}
            onSubmit={submit}
            processing={form.processing}
        >
            <Field
                label="Name"
                htmlFor="template-name"
                error={form.errors.name}
            >
                <Input
                    id="template-name"
                    value={form.data.name}
                    onChange={(event) =>
                        form.setData('name', event.target.value)
                    }
                    required
                />
            </Field>

            <fieldset className="space-y-2">
                <legend className="mb-1 text-sm font-medium">Steps</legend>
                <p className="text-xs text-muted-foreground">{offsetHint}</p>
                <ol className="max-h-[50vh] space-y-2 overflow-y-auto">
                    {form.data.tasks.map((task, index) => (
                        <li
                            key={index}
                            className="grid grid-cols-[minmax(0,1fr)_auto] gap-2 rounded-lg border border-border p-2"
                        >
                            <div className="grid gap-2 sm:grid-cols-[minmax(0,1fr)_7rem_5rem]">
                                <Input
                                    aria-label={`Step ${index + 1}`}
                                    value={task.title}
                                    onChange={(event) =>
                                        setTask(index, {
                                            title: event.target.value,
                                        })
                                    }
                                    placeholder="What needs doing"
                                    required
                                />
                                <SelectField
                                    id={`template-assignee-${index}`}
                                    label="Who"
                                    value={task.assignee_role}
                                    onChange={(value) =>
                                        setTask(index, {
                                            assignee_role:
                                                value as ChecklistAssignee,
                                        })
                                    }
                                    options={ASSIGNEES}
                                    className="[&>label]:sr-only"
                                />
                                <Input
                                    aria-label={`Step ${index + 1} due (days)`}
                                    type="number"
                                    min={-365}
                                    max={365}
                                    value={task.due_offset_days}
                                    onChange={(event) =>
                                        setTask(index, {
                                            due_offset_days: Number(
                                                event.target.value,
                                            ),
                                        })
                                    }
                                />
                            </div>
                            <div className="flex items-start">
                                <Button
                                    type="button"
                                    size="icon"
                                    variant="ghost"
                                    aria-label="Move up"
                                    disabled={index === 0}
                                    onClick={() => move(index, -1)}
                                >
                                    <ArrowUp aria-hidden="true" />
                                </Button>
                                <Button
                                    type="button"
                                    size="icon"
                                    variant="ghost"
                                    aria-label="Move down"
                                    disabled={
                                        index === form.data.tasks.length - 1
                                    }
                                    onClick={() => move(index, 1)}
                                >
                                    <ArrowDown aria-hidden="true" />
                                </Button>
                                <Button
                                    type="button"
                                    size="icon"
                                    variant="ghost"
                                    aria-label="Remove step"
                                    disabled={form.data.tasks.length === 1}
                                    onClick={() =>
                                        form.setData(
                                            'tasks',
                                            form.data.tasks.filter(
                                                (_, position) =>
                                                    position !== index,
                                            ),
                                        )
                                    }
                                >
                                    <Trash2 aria-hidden="true" />
                                </Button>
                            </div>
                        </li>
                    ))}
                </ol>
                {form.errors.tasks && (
                    <p className="text-sm text-destructive">
                        {form.errors.tasks}
                    </p>
                )}
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    onClick={() =>
                        form.setData('tasks', [
                            ...form.data.tasks,
                            {
                                title: '',
                                description: null,
                                assignee_role: 'hr',
                                due_offset_days: 0,
                            },
                        ])
                    }
                >
                    <Plus aria-hidden="true" />
                    Add step
                </Button>
            </fieldset>

            <label className="flex items-center gap-2 text-sm">
                <Checkbox
                    checked={form.data.is_default}
                    onCheckedChange={(checked) =>
                        form.setData('is_default', checked === true)
                    }
                />
                Default {kind} template
            </label>
        </FormDialog>
    );
}
