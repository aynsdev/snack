import { router, useForm } from '@inertiajs/react';
import { Check, History, Pencil, Plus, Undo2, UserPlus, X } from 'lucide-react';
import type { FormEvent } from 'react';
import { Fragment, useMemo, useState } from 'react';
import { DeleteButton, FormDialog } from '@/components/hr/hr-dialogs';
import {
    EmptyNote,
    Field,
    formatDate,
    HrPage,
    PersonAvatar,
    Section,
    SelectField,
    StatusPill,
    todayString,
} from '@/components/hr/hr-ui';
import { StackedBar } from '@/components/snack/projects/summary-charts';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { useWorkspace } from '@/hooks/use-workspace';
import { cn } from '@/lib/utils';
import { assign, destroy, store, update } from '@/routes/hr/equipment';
import { returnMethod as takeBack } from '@/routes/hr/equipment-assignments';
import {
    destroy as destroyCategory,
    store as storeCategory,
    update as updateCategory,
} from '@/routes/hr/equipment-categories';
import {
    approve as approveRequest,
    reject as rejectRequest,
} from '@/routes/hr/equipment-requests';
import type {
    Equipment,
    EquipmentAssignment,
    EquipmentCategory,
    HrRequest,
    Option,
} from '@/types/hr';

type Props = {
    equipment: Equipment[];
    categories: EquipmentCategory[];
    status_counts: { key: string; label: string; count: number }[];
    statuses: Option[];
    requests: HrRequest[];
    employees: Option[];
};

type Dialog =
    | { kind: 'item'; item: Equipment | null }
    | { kind: 'assign'; item: Equipment }
    | { kind: 'return'; item: Equipment; assignment: EquipmentAssignment }
    | { kind: 'category'; category: EquipmentCategory | null }
    | { kind: 'approve' | 'reject'; request: HrRequest };

const SETTABLE: Option[] = [
    { value: 'available', label: 'Available' },
    { value: 'in_repair', label: 'In repair' },
    { value: 'retired', label: 'Retired' },
];

/**
 * Company assets — laptops, phones, keys: what there is, who has it, what
 * came back in what state, and who has asked for something.
 */
export default function EquipmentPage({
    equipment,
    categories,
    status_counts,
    requests,
    employees,
}: Props) {
    const workspace = useWorkspace();
    const [dialog, setDialog] = useState<Dialog | null>(null);
    const [search, setSearch] = useState('');
    const [status, setStatus] = useState('');
    const [category, setCategory] = useState('');
    const [openHistory, setOpenHistory] = useState<number | null>(null);
    const close = () => setDialog(null);

    const returns = equipment.filter(
        (item) => item.holder?.return_requested_at,
    );

    const shown = useMemo(() => {
        const needle = search.trim().toLowerCase();

        return equipment.filter(
            (item) =>
                (status === '' || item.status === status) &&
                (category === '' || String(item.category_id) === category) &&
                (needle === '' ||
                    [
                        item.name,
                        item.tracking_code,
                        item.serial_number,
                        item.holder?.employee?.name,
                    ]
                        .filter(Boolean)
                        .join(' ')
                        .toLowerCase()
                        .includes(needle)),
        );
    }, [equipment, search, status, category]);

    return (
        <HrPage
            title="Company assets"
            description="Equipment the company owns, and who has it."
            width="max-w-6xl"
            actions={
                <Button
                    onClick={() => setDialog({ kind: 'item', item: null })}
                    disabled={categories.length === 0}
                >
                    <Plus aria-hidden="true" />
                    Add asset
                </Button>
            }
        >
            <Section title={`${equipment.length} assets`}>
                <StackedBar
                    segments={status_counts}
                    total={Math.max(1, equipment.length)}
                />
            </Section>

            {(requests.length > 0 || returns.length > 0) && (
                <Section title="Waiting on HR">
                    <ul className="divide-y divide-border">
                        {requests.map((request) => (
                            <li
                                key={`request-${request.id}`}
                                className="flex flex-wrap items-center gap-3 py-2.5 first:pt-0 last:pb-0"
                            >
                                <PersonAvatar
                                    person={request.employee}
                                    className="size-8"
                                />
                                <div className="min-w-0 flex-1">
                                    <p className="text-sm font-medium">
                                        {request.employee.name}
                                        <span className="font-normal text-muted-foreground">
                                            {' '}
                                            asked for {request.summary}
                                        </span>
                                    </p>
                                    {request.reason && (
                                        <p className="text-xs text-muted-foreground">
                                            “{request.reason}”
                                        </p>
                                    )}
                                </div>
                                {request.can.review && (
                                    <div className="flex gap-1.5">
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() =>
                                                setDialog({
                                                    kind: 'reject',
                                                    request,
                                                })
                                            }
                                        >
                                            <X aria-hidden="true" />
                                            Reject
                                        </Button>
                                        <Button
                                            size="sm"
                                            onClick={() =>
                                                setDialog({
                                                    kind: 'approve',
                                                    request,
                                                })
                                            }
                                        >
                                            <Check aria-hidden="true" />
                                            Approve
                                        </Button>
                                    </div>
                                )}
                            </li>
                        ))}
                        {returns.map((item) => (
                            <li
                                key={`return-${item.id}`}
                                className="flex flex-wrap items-center gap-3 py-2.5 first:pt-0 last:pb-0"
                            >
                                {item.holder?.employee && (
                                    <PersonAvatar
                                        person={item.holder.employee}
                                        className="size-8"
                                    />
                                )}
                                <p className="min-w-0 flex-1 text-sm">
                                    <span className="font-medium">
                                        {item.holder?.employee?.name}
                                    </span>
                                    <span className="text-muted-foreground">
                                        {' '}
                                        wants to return {item.name}
                                    </span>
                                </p>
                                <Button
                                    size="sm"
                                    onClick={() =>
                                        setDialog({
                                            kind: 'return',
                                            item,
                                            assignment: item.holder!,
                                        })
                                    }
                                >
                                    <Undo2 aria-hidden="true" />
                                    Take back
                                </Button>
                            </li>
                        ))}
                    </ul>
                </Section>
            )}

            <Section title="Assets">
                <div className="mb-4 flex flex-wrap items-end gap-2">
                    <Field label="Search" htmlFor="equipment-search">
                        <Input
                            id="equipment-search"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Name, code, serial, holder"
                            className="w-56"
                        />
                    </Field>
                    <SelectField
                        id="equipment-status"
                        label="Status"
                        value={status}
                        onChange={setStatus}
                        options={status_counts.map((row) => ({
                            value: row.key,
                            label: row.label,
                        }))}
                        allowEmpty
                        emptyLabel="Any"
                        className="w-36"
                    />
                    <SelectField
                        id="equipment-category"
                        label="Category"
                        value={category}
                        onChange={setCategory}
                        options={categories.map((row) => ({
                            value: String(row.id),
                            label: row.name,
                        }))}
                        allowEmpty
                        emptyLabel="Any"
                        className="w-40"
                    />
                </div>

                {shown.length === 0 ? (
                    <EmptyNote>
                        {equipment.length === 0
                            ? categories.length === 0
                                ? 'Add a category below, then your first asset.'
                                : 'No assets yet.'
                            : 'Nothing matches.'}
                    </EmptyNote>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[44rem] text-sm">
                            <thead className="text-left text-xs text-muted-foreground">
                                <tr>
                                    <th
                                        scope="col"
                                        className="py-2 pr-3 font-medium"
                                    >
                                        Asset
                                    </th>
                                    <th
                                        scope="col"
                                        className="py-2 pr-3 font-medium"
                                    >
                                        Category
                                    </th>
                                    <th
                                        scope="col"
                                        className="py-2 pr-3 font-medium"
                                    >
                                        Status
                                    </th>
                                    <th
                                        scope="col"
                                        className="py-2 pr-3 font-medium"
                                    >
                                        With
                                    </th>
                                    <th scope="col" className="py-2">
                                        <span className="sr-only">Actions</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-border">
                                {shown.map((item) => (
                                    <Fragment key={item.id}>
                                        <tr>
                                            <td className="py-2 pr-3">
                                                <p className="font-medium">
                                                    {item.name}
                                                </p>
                                                <p className="text-xs text-muted-foreground">
                                                    {[
                                                        item.tracking_code,
                                                        item.serial_number &&
                                                            `S/N ${item.serial_number}`,
                                                    ]
                                                        .filter(Boolean)
                                                        .join(' · ') || '—'}
                                                </p>
                                            </td>
                                            <td className="py-2 pr-3 text-muted-foreground">
                                                {item.category}
                                            </td>
                                            <td className="py-2 pr-3">
                                                <StatusPill
                                                    status={item.status}
                                                    label={item.status_label}
                                                />
                                            </td>
                                            <td className="py-2 pr-3">
                                                {item.holder?.employee ? (
                                                    <span className="flex items-center gap-2">
                                                        <PersonAvatar
                                                            person={
                                                                item.holder
                                                                    .employee
                                                            }
                                                            className="size-6"
                                                        />
                                                        <span className="min-w-0">
                                                            <span className="block truncate">
                                                                {
                                                                    item.holder
                                                                        .employee
                                                                        .name
                                                                }
                                                            </span>
                                                            <span className="block text-xs text-muted-foreground">
                                                                since{' '}
                                                                {formatDate(
                                                                    item.holder
                                                                        .assigned_on,
                                                                )}
                                                            </span>
                                                        </span>
                                                    </span>
                                                ) : (
                                                    <span className="text-muted-foreground">
                                                        —
                                                    </span>
                                                )}
                                            </td>
                                            <td className="py-2 text-right whitespace-nowrap">
                                                {item.status ===
                                                    'available' && (
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() =>
                                                            setDialog({
                                                                kind: 'assign',
                                                                item,
                                                            })
                                                        }
                                                    >
                                                        <UserPlus aria-hidden="true" />
                                                        Hand out
                                                    </Button>
                                                )}
                                                {item.holder && (
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() =>
                                                            setDialog({
                                                                kind: 'return',
                                                                item,
                                                                assignment:
                                                                    item.holder!,
                                                            })
                                                        }
                                                    >
                                                        <Undo2 aria-hidden="true" />
                                                        Take back
                                                    </Button>
                                                )}
                                                <Button
                                                    size="icon"
                                                    variant="ghost"
                                                    aria-label={`History of ${item.name}`}
                                                    aria-expanded={
                                                        openHistory === item.id
                                                    }
                                                    onClick={() =>
                                                        setOpenHistory(
                                                            openHistory ===
                                                                item.id
                                                                ? null
                                                                : item.id,
                                                        )
                                                    }
                                                >
                                                    <History aria-hidden="true" />
                                                </Button>
                                                <Button
                                                    size="icon"
                                                    variant="ghost"
                                                    aria-label={`Edit ${item.name}`}
                                                    onClick={() =>
                                                        setDialog({
                                                            kind: 'item',
                                                            item,
                                                        })
                                                    }
                                                >
                                                    <Pencil aria-hidden="true" />
                                                </Button>
                                                <DeleteButton
                                                    label={`Delete ${item.name}`}
                                                    onConfirm={() =>
                                                        router.delete(
                                                            destroy({
                                                                workspace:
                                                                    workspace.slug,
                                                                equipment:
                                                                    item.id,
                                                            }).url,
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                />
                                            </td>
                                        </tr>
                                        {openHistory === item.id && (
                                            <tr>
                                                <td
                                                    colSpan={5}
                                                    className="bg-muted/40 px-3 py-2"
                                                >
                                                    <HistoryList item={item} />
                                                </td>
                                            </tr>
                                        )}
                                    </Fragment>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Section>

            <Section
                title="Categories"
                action={
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() =>
                            setDialog({ kind: 'category', category: null })
                        }
                    >
                        <Plus aria-hidden="true" />
                        Add category
                    </Button>
                }
            >
                {categories.length === 0 ? (
                    <EmptyNote>
                        No categories yet. Start with something like Laptops or
                        Phones.
                    </EmptyNote>
                ) : (
                    <ul className="divide-y divide-border">
                        {categories.map((row) => (
                            <li
                                key={row.id}
                                className="flex items-center gap-3 py-2 first:pt-0 last:pb-0"
                            >
                                <div className="min-w-0 flex-1">
                                    <p className="text-sm font-medium">
                                        {row.name}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {row.count}{' '}
                                        {row.count === 1 ? 'asset' : 'assets'}
                                        {row.description &&
                                            ` · ${row.description}`}
                                    </p>
                                </div>
                                <Button
                                    size="icon"
                                    variant="ghost"
                                    aria-label={`Edit ${row.name}`}
                                    onClick={() =>
                                        setDialog({
                                            kind: 'category',
                                            category: row,
                                        })
                                    }
                                >
                                    <Pencil aria-hidden="true" />
                                </Button>
                                <DeleteButton
                                    label={`Delete ${row.name}`}
                                    onConfirm={() =>
                                        router.delete(
                                            destroyCategory({
                                                workspace: workspace.slug,
                                                equipmentCategory: row.id,
                                            }).url,
                                            { preserveScroll: true },
                                        )
                                    }
                                />
                            </li>
                        ))}
                    </ul>
                )}
            </Section>

            {dialog?.kind === 'item' && (
                <ItemDialog
                    item={dialog.item}
                    categories={categories}
                    onClose={close}
                />
            )}
            {dialog?.kind === 'assign' && (
                <AssignDialog
                    item={dialog.item}
                    employees={employees}
                    onClose={close}
                />
            )}
            {dialog?.kind === 'return' && (
                <ReturnDialog
                    item={dialog.item}
                    assignment={dialog.assignment}
                    onClose={close}
                />
            )}
            {dialog?.kind === 'category' && (
                <CategoryDialog category={dialog.category} onClose={close} />
            )}
            {(dialog?.kind === 'approve' || dialog?.kind === 'reject') && (
                <RequestDialog
                    request={dialog.request}
                    decision={dialog.kind}
                    available={equipment.filter(
                        (item) => item.status === 'available',
                    )}
                    onClose={close}
                />
            )}
        </HrPage>
    );
}

function HistoryList({ item }: { item: Equipment }) {
    if (item.history.length === 0) {
        return (
            <p className="text-xs text-muted-foreground">Never handed out.</p>
        );
    }

    return (
        <ul className="space-y-1.5 text-xs">
            {item.history.map((entry) => (
                <li key={entry.id}>
                    <span className="font-medium">{entry.employee?.name}</span>
                    <span className="text-muted-foreground">
                        {' · '}
                        {formatDate(entry.assigned_on)} –{' '}
                        {entry.returned_on
                            ? formatDate(entry.returned_on)
                            : 'now'}
                        {entry.condition_out &&
                            ` · out: ${entry.condition_out}`}
                        {entry.condition_in && ` · back: ${entry.condition_in}`}
                    </span>
                </li>
            ))}
        </ul>
    );
}

function ItemDialog({
    item,
    categories,
    onClose,
}: {
    item: Equipment | null;
    categories: EquipmentCategory[];
    onClose: () => void;
}) {
    const workspace = useWorkspace();
    const assigned = item?.status === 'assigned';
    const form = useForm({
        equipment_category_id: item
            ? String(item.category_id)
            : String(categories[0]?.id ?? ''),
        name: item?.name ?? '',
        tracking_code: item?.tracking_code ?? '',
        serial_number: item?.serial_number ?? '',
        purchased_on: item?.purchased_on ?? '',
        cost: item?.cost ?? '',
        warranty_expires_on: item?.warranty_expires_on ?? '',
        notes: item?.notes ?? '',
        status: String(item && !assigned ? item.status : 'available'),
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.transform((data) => {
            const blankToNull = Object.fromEntries(
                Object.entries(data).map(([key, value]) => [
                    key,
                    value === '' ? null : value,
                ]),
            );
            const { status, ...rest } = blankToNull;

            return {
                ...rest,
                equipment_category_id: Number(data.equipment_category_id),
                ...(assigned ? {} : { status }),
            };
        });

        if (item) {
            form.patch(
                update({ workspace: workspace.slug, equipment: item.id }).url,
                { preserveScroll: true, onSuccess: onClose },
            );
        } else {
            form.post(store(workspace.slug).url, {
                preserveScroll: true,
                onSuccess: onClose,
            });
        }
    }

    const text = (
        key: 'name' | 'tracking_code' | 'serial_number' | 'cost',
        label: string,
        props: Record<string, unknown> = {},
    ) => (
        <Field
            label={label}
            htmlFor={`equipment-${key}`}
            error={form.errors[key]}
        >
            <Input
                id={`equipment-${key}`}
                value={form.data[key]}
                onChange={(event) => form.setData(key, event.target.value)}
                {...props}
            />
        </Field>
    );

    return (
        <FormDialog
            title={item ? `Edit ${item.name}` : 'Add an asset'}
            onClose={onClose}
            onSubmit={submit}
            processing={form.processing}
        >
            <div className="grid gap-4 sm:grid-cols-2">
                {text('name', 'Name', {
                    required: true,
                    placeholder: 'MacBook Air 13"',
                })}
                <SelectField
                    id="equipment-category-id"
                    label="Category"
                    value={form.data.equipment_category_id}
                    onChange={(value) =>
                        form.setData('equipment_category_id', value)
                    }
                    options={categories.map((row) => ({
                        value: String(row.id),
                        label: row.name,
                    }))}
                    error={form.errors.equipment_category_id}
                />
                {text('tracking_code', 'Asset tag', {
                    placeholder: 'LAP-0042',
                })}
                {text('serial_number', 'Serial number')}
                <Field
                    label="Purchased"
                    htmlFor="equipment-purchased"
                    error={form.errors.purchased_on}
                >
                    <Input
                        id="equipment-purchased"
                        type="date"
                        value={form.data.purchased_on}
                        onChange={(event) =>
                            form.setData('purchased_on', event.target.value)
                        }
                    />
                </Field>
                {text('cost', 'Cost', {
                    type: 'number',
                    min: 0,
                    step: '0.01',
                })}
                <Field
                    label="Warranty until"
                    htmlFor="equipment-warranty"
                    error={form.errors.warranty_expires_on}
                >
                    <Input
                        id="equipment-warranty"
                        type="date"
                        value={form.data.warranty_expires_on}
                        onChange={(event) =>
                            form.setData(
                                'warranty_expires_on',
                                event.target.value,
                            )
                        }
                    />
                </Field>
                {assigned ? (
                    <p className="self-end text-xs text-muted-foreground">
                        Handed out — take it back to change its status.
                    </p>
                ) : (
                    <SelectField
                        id="equipment-status-field"
                        label="Status"
                        value={form.data.status}
                        onChange={(value) => form.setData('status', value)}
                        options={SETTABLE}
                        error={form.errors.status}
                    />
                )}
            </div>
            <Field
                label="Notes"
                htmlFor="equipment-notes"
                error={form.errors.notes}
            >
                <Textarea
                    id="equipment-notes"
                    rows={2}
                    value={form.data.notes}
                    onChange={(event) =>
                        form.setData('notes', event.target.value)
                    }
                />
            </Field>
        </FormDialog>
    );
}

function AssignDialog({
    item,
    employees,
    onClose,
}: {
    item: Equipment;
    employees: Option[];
    onClose: () => void;
}) {
    const workspace = useWorkspace();
    const form = useForm({
        employee_id: '',
        assigned_on: todayString(),
        condition: '',
    });

    // Raised by the server when the item was handed out meanwhile.
    const equipmentError = (form.errors as Record<string, string | undefined>)
        .equipment_id;

    function submit(event: FormEvent) {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            employee_id: Number(data.employee_id),
        }));
        form.post(
            assign({ workspace: workspace.slug, equipment: item.id }).url,
            { preserveScroll: true, onSuccess: onClose },
        );
    }

    return (
        <FormDialog
            title={`Hand out ${item.name}`}
            description="They get a message saying it is theirs to look after."
            onClose={onClose}
            onSubmit={submit}
            processing={form.processing}
            submitLabel="Hand out"
        >
            <SelectField
                id="assign-employee"
                label="To"
                value={form.data.employee_id}
                onChange={(value) => form.setData('employee_id', value)}
                options={employees}
                error={form.errors.employee_id}
            />
            {equipmentError && (
                <p className="text-sm text-destructive">{equipmentError}</p>
            )}
            <div className="grid gap-4 sm:grid-cols-2">
                <Field
                    label="On"
                    htmlFor="assign-on"
                    error={form.errors.assigned_on}
                >
                    <Input
                        id="assign-on"
                        type="date"
                        value={form.data.assigned_on}
                        onChange={(event) =>
                            form.setData('assigned_on', event.target.value)
                        }
                    />
                </Field>
                <Field
                    label="Condition"
                    htmlFor="assign-condition"
                    error={form.errors.condition}
                >
                    <Input
                        id="assign-condition"
                        value={form.data.condition}
                        onChange={(event) =>
                            form.setData('condition', event.target.value)
                        }
                        placeholder="New, minor scratches…"
                    />
                </Field>
            </div>
        </FormDialog>
    );
}

function ReturnDialog({
    item,
    assignment,
    onClose,
}: {
    item: Equipment;
    assignment: EquipmentAssignment;
    onClose: () => void;
}) {
    const workspace = useWorkspace();
    const form = useForm({
        status: 'available',
        returned_on: todayString(),
        condition: '',
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(
            takeBack({
                workspace: workspace.slug,
                equipmentAssignment: assignment.id,
            }).url,
            { preserveScroll: true, onSuccess: onClose },
        );
    }

    return (
        <FormDialog
            title={`Take back ${item.name}`}
            description={`From ${assignment.employee?.name}, who has had it since ${formatDate(assignment.assigned_on)}.`}
            onClose={onClose}
            onSubmit={submit}
            processing={form.processing}
            submitLabel="Take back"
        >
            <div className="grid gap-4 sm:grid-cols-2">
                <SelectField
                    id="return-status"
                    label="Now"
                    value={form.data.status}
                    onChange={(value) => form.setData('status', value)}
                    options={SETTABLE}
                    error={form.errors.status}
                />
                <Field
                    label="Returned on"
                    htmlFor="return-on"
                    error={form.errors.returned_on}
                >
                    <Input
                        id="return-on"
                        type="date"
                        value={form.data.returned_on}
                        onChange={(event) =>
                            form.setData('returned_on', event.target.value)
                        }
                    />
                </Field>
            </div>
            <Field
                label="Condition"
                htmlFor="return-condition"
                error={form.errors.condition}
            >
                <Input
                    id="return-condition"
                    value={form.data.condition}
                    onChange={(event) =>
                        form.setData('condition', event.target.value)
                    }
                    placeholder="Good, cracked screen…"
                />
            </Field>
        </FormDialog>
    );
}

function CategoryDialog({
    category,
    onClose,
}: {
    category: EquipmentCategory | null;
    onClose: () => void;
}) {
    const workspace = useWorkspace();
    const form = useForm({
        name: category?.name ?? '',
        description: category?.description ?? '',
    });

    function submit(event: FormEvent) {
        event.preventDefault();

        if (category) {
            form.patch(
                updateCategory({
                    workspace: workspace.slug,
                    equipmentCategory: category.id,
                }).url,
                { preserveScroll: true, onSuccess: onClose },
            );
        } else {
            form.post(storeCategory(workspace.slug).url, {
                preserveScroll: true,
                onSuccess: onClose,
            });
        }
    }

    return (
        <FormDialog
            title={category ? 'Edit category' : 'Add a category'}
            onClose={onClose}
            onSubmit={submit}
            processing={form.processing}
        >
            <Field
                label="Name"
                htmlFor="category-name"
                error={form.errors.name}
            >
                <Input
                    id="category-name"
                    value={form.data.name}
                    onChange={(event) =>
                        form.setData('name', event.target.value)
                    }
                    placeholder="Laptops"
                    required
                />
            </Field>
            <Field
                label="Description"
                htmlFor="category-description"
                error={form.errors.description}
            >
                <Input
                    id="category-description"
                    value={form.data.description}
                    onChange={(event) =>
                        form.setData('description', event.target.value)
                    }
                    placeholder="Optional"
                />
            </Field>
        </FormDialog>
    );
}

function RequestDialog({
    request,
    decision,
    available,
    onClose,
}: {
    request: HrRequest;
    decision: 'approve' | 'reject';
    available: Equipment[];
    onClose: () => void;
}) {
    const workspace = useWorkspace();
    const form = useForm({ note: '', equipment_id: '' });
    const candidates = available.filter(
        (item) => item.category_id === request.equipment_category_id,
    );

    function submit(event: FormEvent) {
        event.preventDefault();
        form.transform((data) => ({
            note: data.note,
            equipment_id:
                data.equipment_id === '' ? null : Number(data.equipment_id),
        }));
        form.post(
            (decision === 'approve' ? approveRequest : rejectRequest)({
                workspace: workspace.slug,
                equipmentRequest: request.id,
            }).url,
            { preserveScroll: true, onSuccess: onClose },
        );
    }

    return (
        <FormDialog
            title={
                decision === 'approve' ? 'Approve request' : 'Reject request'
            }
            description={`${request.employee.name} asked for ${request.summary}.`}
            onClose={onClose}
            onSubmit={submit}
            processing={form.processing}
            submitLabel={decision === 'approve' ? 'Approve' : 'Reject'}
        >
            {decision === 'approve' && (
                <SelectField
                    id="request-equipment"
                    label="Hand over now"
                    value={form.data.equipment_id}
                    onChange={(value) => form.setData('equipment_id', value)}
                    options={candidates.map((item) => ({
                        value: String(item.id),
                        label: [item.name, item.tracking_code]
                            .filter(Boolean)
                            .join(' · '),
                    }))}
                    error={form.errors.equipment_id}
                    allowEmpty
                    emptyLabel={
                        candidates.length === 0
                            ? 'Nothing available — hand over later'
                            : 'Not yet — hand over later'
                    }
                />
            )}
            <Field
                label="Note to the employee"
                htmlFor="request-note"
                error={form.errors.note}
            >
                <Textarea
                    id="request-note"
                    rows={3}
                    value={form.data.note}
                    onChange={(event) =>
                        form.setData('note', event.target.value)
                    }
                    placeholder="Optional"
                    className={cn(decision === 'reject' && 'min-h-20')}
                />
            </Field>
        </FormDialog>
    );
}
