import { router, useForm } from '@inertiajs/react';
import { FileText, Plus } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { DeleteButton, FormDialog } from '@/components/hr/hr-dialogs';
import {
    EmptyNote,
    Field,
    formatDate,
    formatDateRange,
    Section,
    SelectField,
    StatusPill,
    todayString,
} from '@/components/hr/hr-ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { destroy, download, store } from '@/routes/hr/employees/disciplinary';
import type { DisciplinaryAction, Option } from '@/types/hr';

type Ids = { workspace: string; employee: number };

/**
 * Warnings, suspensions, and dismissals on an employee's file. The employee
 * reads their own; HR issues and removes them.
 */
export function DisciplinaryTab({
    actions,
    types,
    ids,
    canManage,
}: {
    actions: DisciplinaryAction[];
    types: Option[];
    ids: Ids;
    canManage: boolean;
}) {
    const [open, setOpen] = useState(false);

    return (
        <Section
            title="Disciplinary actions"
            action={
                canManage && (
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() => setOpen(true)}
                    >
                        <Plus aria-hidden="true" />
                        Issue action
                    </Button>
                )
            }
        >
            {actions.length === 0 ? (
                <EmptyNote>Nothing on file.</EmptyNote>
            ) : (
                <ul className="divide-y divide-border">
                    {actions.map((action) => (
                        <li
                            key={action.id}
                            className="flex items-start justify-between gap-3 py-3 first:pt-0 last:pb-0"
                        >
                            <div className="min-w-0">
                                <p className="flex flex-wrap items-center gap-2 text-sm font-semibold">
                                    <StatusPill
                                        status={action.type}
                                        label={action.type_label}
                                    />
                                    {action.ends_on
                                        ? formatDateRange(
                                              action.starts_on,
                                              action.ends_on,
                                          )
                                        : formatDate(action.starts_on)}
                                </p>
                                <p className="mt-1 text-sm whitespace-pre-line">
                                    {action.description}
                                </p>
                                {action.issued_by && (
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        Issued by {action.issued_by}
                                    </p>
                                )}
                            </div>
                            <div className="flex items-center gap-1">
                                {action.has_file && (
                                    <Button size="sm" variant="ghost" asChild>
                                        <a
                                            href={
                                                download({
                                                    ...ids,
                                                    disciplinaryAction:
                                                        action.id,
                                                }).url
                                            }
                                        >
                                            <FileText aria-hidden="true" />
                                            <span className="max-w-32 truncate">
                                                {action.file_name}
                                            </span>
                                        </a>
                                    </Button>
                                )}
                                {canManage && (
                                    <DeleteButton
                                        label="Remove disciplinary action"
                                        onConfirm={() =>
                                            router.delete(
                                                destroy({
                                                    ...ids,
                                                    disciplinaryAction:
                                                        action.id,
                                                }).url,
                                                { preserveScroll: true },
                                            )
                                        }
                                    />
                                )}
                            </div>
                        </li>
                    ))}
                </ul>
            )}

            {open && (
                <IssueDialog
                    types={types}
                    ids={ids}
                    onClose={() => setOpen(false)}
                />
            )}
        </Section>
    );
}

function IssueDialog({
    types,
    ids,
    onClose,
}: {
    types: Option[];
    ids: Ids;
    onClose: () => void;
}) {
    const form = useForm<{
        type: string;
        description: string;
        starts_on: string;
        duration_days: string;
        file: File | null;
    }>({
        type: 'verbal_warning',
        description: '',
        starts_on: todayString(),
        duration_days: '',
        file: null,
    });
    const isSuspension = form.data.type === 'suspension';

    function submit(event: FormEvent) {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            duration_days: isSuspension ? Number(data.duration_days) : null,
        }));
        form.post(store(ids).url, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: onClose,
        });
    }

    return (
        <FormDialog
            title="Issue a disciplinary action"
            description="The employee can see this on their own file."
            onClose={onClose}
            onSubmit={submit}
            processing={form.processing}
            submitLabel="Issue"
        >
            <div className="grid gap-4 sm:grid-cols-2">
                <SelectField
                    id="disciplinary-type"
                    label="Action"
                    value={form.data.type}
                    onChange={(value) => form.setData('type', value)}
                    options={types}
                    error={form.errors.type}
                />
                <Field
                    label="Effective"
                    htmlFor="disciplinary-starts"
                    error={form.errors.starts_on}
                >
                    <Input
                        id="disciplinary-starts"
                        type="date"
                        value={form.data.starts_on}
                        onChange={(event) =>
                            form.setData('starts_on', event.target.value)
                        }
                        required
                    />
                </Field>
                {isSuspension && (
                    <Field
                        label="Days suspended"
                        htmlFor="disciplinary-days"
                        error={form.errors.duration_days}
                    >
                        <Input
                            id="disciplinary-days"
                            type="number"
                            min={1}
                            max={365}
                            value={form.data.duration_days}
                            onChange={(event) =>
                                form.setData(
                                    'duration_days',
                                    event.target.value,
                                )
                            }
                            required
                        />
                    </Field>
                )}
            </div>
            <Field
                label="What happened"
                htmlFor="disciplinary-description"
                error={form.errors.description}
            >
                <Textarea
                    id="disciplinary-description"
                    rows={4}
                    value={form.data.description}
                    onChange={(event) =>
                        form.setData('description', event.target.value)
                    }
                    required
                />
            </Field>
            <Field
                label="Letter (optional)"
                htmlFor="disciplinary-file"
                error={form.errors.file}
            >
                <Input
                    id="disciplinary-file"
                    type="file"
                    accept=".pdf,.doc,.docx,.png,.jpg,.jpeg"
                    onChange={(event) =>
                        form.setData('file', event.target.files?.[0] ?? null)
                    }
                />
            </Field>
        </FormDialog>
    );
}
