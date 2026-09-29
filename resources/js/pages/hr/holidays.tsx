import { router, useForm } from '@inertiajs/react';
import { CalendarDays, Pencil, Plus, Repeat } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { DeleteButton, FormDialog } from '@/components/hr/hr-dialogs';
import {
    EmptyNote,
    Field,
    formatDate,
    HrPage,
    Section,
    todayString,
} from '@/components/hr/hr-ui';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { useWorkspace } from '@/hooks/use-workspace';
import {
    destroy as destroyHoliday,
    store as storeHoliday,
    update as updateHoliday,
} from '@/routes/hr/holidays';
import type { Holiday } from '@/types/hr';

type Props = {
    holidays: Holiday[];
    can_manage: boolean;
};

/**
 * The company's days off. Leave requests skip these automatically.
 */
export default function HolidaysPage({ holidays, can_manage }: Props) {
    const workspace = useWorkspace();
    const [editing, setEditing] = useState<Holiday | 'new' | null>(null);

    const upcoming = holidays.filter((holiday) => holiday.next_on !== null);
    const past = holidays.filter((holiday) => holiday.next_on === null);

    return (
        <HrPage
            title="Holidays"
            description="Days the company is closed. They are not deducted from anybody's leave."
            width="max-w-3xl"
            actions={
                can_manage && (
                    <Button onClick={() => setEditing('new')}>
                        <Plus aria-hidden="true" />
                        Add holiday
                    </Button>
                )
            }
        >
            <Section title="Upcoming">
                {upcoming.length === 0 ? (
                    <EmptyNote>No holidays scheduled.</EmptyNote>
                ) : (
                    <HolidayList
                        holidays={upcoming}
                        canManage={can_manage}
                        onEdit={setEditing}
                        workspaceSlug={workspace.slug}
                    />
                )}
            </Section>

            {past.length > 0 && (
                <Section title="Past">
                    <HolidayList
                        holidays={past}
                        canManage={can_manage}
                        onEdit={setEditing}
                        workspaceSlug={workspace.slug}
                    />
                </Section>
            )}

            {editing !== null && (
                <HolidayDialog
                    key={editing === 'new' ? 'new' : editing.id}
                    holiday={editing === 'new' ? null : editing}
                    onClose={() => setEditing(null)}
                />
            )}
        </HrPage>
    );
}

function HolidayList({
    holidays,
    canManage,
    onEdit,
    workspaceSlug,
}: {
    holidays: Holiday[];
    canManage: boolean;
    onEdit: (holiday: Holiday) => void;
    workspaceSlug: string;
}) {
    return (
        <ul className="divide-y divide-border">
            {holidays.map((holiday) => (
                <li
                    key={holiday.id}
                    className="flex items-center gap-3 py-2.5 first:pt-0 last:pb-0"
                >
                    <span className="flex size-10 shrink-0 flex-col items-center justify-center rounded-lg bg-muted text-center leading-none">
                        <span className="text-[0.625rem] font-semibold text-muted-foreground uppercase">
                            {formatDate(holiday.next_on ?? holiday.date, {
                                month: 'short',
                            })}
                        </span>
                        <span className="text-sm font-bold">
                            {formatDate(holiday.next_on ?? holiday.date, {
                                day: 'numeric',
                            })}
                        </span>
                    </span>
                    <div className="min-w-0 flex-1">
                        <p className="truncate text-sm font-medium">
                            {holiday.name}
                        </p>
                        <p className="flex items-center gap-1 text-xs text-muted-foreground">
                            {holiday.is_recurring ? (
                                <>
                                    <Repeat
                                        aria-hidden="true"
                                        className="size-3"
                                    />
                                    Every year
                                </>
                            ) : (
                                <>
                                    <CalendarDays
                                        aria-hidden="true"
                                        className="size-3"
                                    />
                                    {formatDate(holiday.date, {
                                        weekday: 'long',
                                        month: 'long',
                                        day: 'numeric',
                                        year: 'numeric',
                                    })}
                                </>
                            )}
                        </p>
                    </div>
                    {canManage && (
                        <div className="flex">
                            <Button
                                size="icon"
                                variant="ghost"
                                aria-label={`Edit ${holiday.name}`}
                                onClick={() => onEdit(holiday)}
                            >
                                <Pencil aria-hidden="true" />
                            </Button>
                            <DeleteButton
                                label={`Remove ${holiday.name}`}
                                onConfirm={() =>
                                    router.delete(
                                        destroyHoliday({
                                            workspace: workspaceSlug,
                                            holiday: holiday.id,
                                        }).url,
                                        { preserveScroll: true },
                                    )
                                }
                            />
                        </div>
                    )}
                </li>
            ))}
        </ul>
    );
}

function HolidayDialog({
    holiday,
    onClose,
}: {
    holiday: Holiday | null;
    onClose: () => void;
}) {
    const workspace = useWorkspace();
    const form = useForm({
        name: holiday?.name ?? '',
        date: holiday?.date ?? todayString(),
        is_recurring: holiday?.is_recurring ?? false,
    });

    function submit(event: FormEvent) {
        event.preventDefault();

        if (holiday) {
            form.patch(
                updateHoliday({
                    workspace: workspace.slug,
                    holiday: holiday.id,
                }).url,
                { preserveScroll: true, onSuccess: onClose },
            );
        } else {
            form.post(storeHoliday(workspace.slug).url, {
                preserveScroll: true,
                onSuccess: onClose,
            });
        }
    }

    return (
        <FormDialog
            title={holiday ? 'Edit holiday' : 'Add a holiday'}
            onClose={onClose}
            onSubmit={submit}
            processing={form.processing}
        >
            <div className="grid gap-4 sm:grid-cols-2">
                <Field
                    label="Name"
                    htmlFor="holiday-name"
                    error={form.errors.name}
                >
                    <Input
                        id="holiday-name"
                        value={form.data.name}
                        onChange={(event) =>
                            form.setData('name', event.target.value)
                        }
                        required
                    />
                </Field>
                <Field
                    label="Date"
                    htmlFor="holiday-date"
                    error={form.errors.date}
                >
                    <Input
                        id="holiday-date"
                        type="date"
                        value={form.data.date}
                        onChange={(event) =>
                            form.setData('date', event.target.value)
                        }
                        required
                    />
                </Field>
            </div>
            <label className="flex items-center gap-2 text-sm">
                <Checkbox
                    checked={form.data.is_recurring}
                    onCheckedChange={(checked) =>
                        form.setData('is_recurring', checked === true)
                    }
                />
                Repeats on this date every year
            </label>
        </FormDialog>
    );
}
