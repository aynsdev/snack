import { Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, Download, FileText, Pencil, Plus } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import {
    DeleteButton,
    FormDialog,
    LocationLink,
} from '@/components/hr/hr-dialogs';
import {
    EmptyNote,
    Field,
    formatDate,
    formatDateRange,
    formatDateTime,
    formatDays,
    formatMinutes,
    HrPage,
    LEAVE_COLORS,
    PersonAvatar,
    Section,
    SelectField,
    StatusPill,
    todayString,
} from '@/components/hr/hr-ui';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { useWorkspace } from '@/hooks/use-workspace';
import { cn } from '@/lib/utils';
import {
    index as employeesIndex,
    update as updateEmployee,
} from '@/routes/hr/employees';
import {
    destroy as destroyAbsence,
    store as storeAbsence,
} from '@/routes/hr/employees/absences';
import {
    destroy as destroyContract,
    download as downloadContract,
    store as storeContract,
    update as updateContract,
} from '@/routes/hr/employees/contracts';
import {
    destroy as destroyHistory,
    store as storeHistory,
} from '@/routes/hr/employees/history';
import {
    destroy as destroyRecord,
    download as downloadRecord,
    store as storeRecord,
} from '@/routes/hr/employees/records';
import type {
    Absence,
    Contract,
    Employee,
    EmployeeRecord,
    EmploymentHistoryEntry,
    LeaveBalance,
    LeaveRequest,
    Option,
    TimeEntry,
} from '@/types/hr';

type Props = {
    employee: Employee;
    history: EmploymentHistoryEntry[];
    contracts: Contract[];
    records: EmployeeRecord[];
    absences: Absence[];
    leave_requests: LeaveRequest[];
    balances: LeaveBalance[];
    time_entries: TimeEntry[];
    managers: { id: number; name: string }[];
    shifts: Option[];
    options: {
        employment_types: Option[];
        work_types: Option[];
        statuses: Option[];
        changes: Option[];
        contract_statuses: Option[];
        record_types: Option[];
        absence_types: Option[];
    };
    can_manage: boolean;
};

type Tab = 'profile' | 'history' | 'contracts' | 'records' | 'leave' | 'time';

const PAY_FREQUENCIES: Option[] = [
    { value: 'hourly', label: 'Hourly' },
    { value: 'daily', label: 'Daily' },
    { value: 'weekly', label: 'Weekly' },
    { value: 'semi_monthly', label: 'Semi-monthly' },
    { value: 'monthly', label: 'Monthly' },
    { value: 'yearly', label: 'Yearly' },
];

/**
 * One person's HR file. HR sees and edits all of it; the person themselves
 * sees their own profile, history, contracts, leave, and time — but not HR's
 * records, which the server does not send them.
 */
export default function EmployeeShow(props: Props) {
    const { employee, can_manage } = props;
    const workspace = useWorkspace();
    const [tab, setTab] = useState<Tab>('profile');

    const tabs: { id: Tab; label: string; count?: number }[] = [
        { id: 'profile', label: 'Profile' },
        {
            id: 'history',
            label: 'Employment history',
            count: props.history.length,
        },
        { id: 'contracts', label: 'Contracts', count: props.contracts.length },
        ...(can_manage
            ? [
                  {
                      id: 'records' as Tab,
                      label: 'Records',
                      count: props.records.length,
                  },
              ]
            : []),
        { id: 'leave', label: 'Leave & absences' },
        { id: 'time', label: 'Time' },
    ];

    const ids = { workspace: workspace.slug, employee: employee.id };

    return (
        <HrPage
            title={employee.user.name}
            description={
                <span className="flex flex-wrap items-center gap-2">
                    {[employee.job_title, employee.department]
                        .filter(Boolean)
                        .join(' · ') || 'No title set'}
                    <StatusPill
                        status={employee.status}
                        label={employee.status_label}
                    />
                </span>
            }
            actions={
                can_manage && (
                    <Button variant="ghost" size="sm" asChild>
                        <Link href={employeesIndex(workspace.slug)}>
                            <ArrowLeft aria-hidden="true" />
                            All employees
                        </Link>
                    </Button>
                )
            }
        >
            <div className="flex items-center gap-3">
                <PersonAvatar person={employee.user} className="size-12" />
                <div className="text-sm text-muted-foreground">
                    <p>{employee.email}</p>
                    <p>
                        {employee.employment_type_label}
                        {employee.hired_on &&
                            ` · since ${formatDate(employee.hired_on)}`}
                        {employee.employee_number &&
                            ` · #${employee.employee_number}`}
                    </p>
                </div>
            </div>

            <div
                role="tablist"
                aria-label="Employee file"
                className="flex gap-1 overflow-x-auto overflow-y-hidden border-b border-border"
            >
                {tabs.map((item) => (
                    <button
                        key={item.id}
                        type="button"
                        role="tab"
                        aria-selected={tab === item.id}
                        onClick={() => setTab(item.id)}
                        className={cn(
                            '-mb-px shrink-0 border-b-2 px-3 py-2 text-sm font-medium transition-colors',
                            tab === item.id
                                ? 'border-primary text-foreground'
                                : 'border-transparent text-muted-foreground hover:text-foreground',
                        )}
                    >
                        {item.label}
                        {item.count !== undefined && item.count > 0 && (
                            <span className="ml-1.5 text-xs text-muted-foreground tabular-nums">
                                {item.count}
                            </span>
                        )}
                    </button>
                ))}
            </div>

            <div role="tabpanel">
                {tab === 'profile' && <ProfileTab {...props} />}
                {tab === 'history' && <HistoryTab {...props} ids={ids} />}
                {tab === 'contracts' && <ContractsTab {...props} ids={ids} />}
                {tab === 'records' && can_manage && (
                    <RecordsTab {...props} ids={ids} />
                )}
                {tab === 'leave' && <LeaveTab {...props} ids={ids} />}
                {tab === 'time' && <TimeTab entries={props.time_entries} />}
            </div>
        </HrPage>
    );
}

type Ids = { workspace: string; employee: number };

// -- Profile ----------------------------------------------------------------

function ProfileTab({
    employee,
    managers,
    shifts,
    options,
    can_manage,
}: Props) {
    const workspace = useWorkspace();
    const form = useForm({
        employee_number: employee.employee_number ?? '',
        job_title: employee.job_title ?? '',
        department: employee.department ?? '',
        employment_type: employee.employment_type,
        status: employee.status,
        manager_id: employee.manager ? String(employee.manager.id) : '',
        shift_id: employee.shift ? String(employee.shift.id) : '',
        work_type: employee.work_type,
        hired_on: employee.hired_on ?? '',
        terminated_on: employee.terminated_on ?? '',
        birth_date: employee.birth_date ?? '',
        phone: employee.phone ?? '',
        address: employee.address ?? '',
        emergency_contact_name: employee.emergency_contact_name ?? '',
        emergency_contact_phone: employee.emergency_contact_phone ?? '',
    });

    if (!can_manage) {
        return (
            <div className="grid gap-6 md:grid-cols-2">
                <Section title="Job">
                    <Details
                        rows={[
                            ['Title', employee.job_title],
                            ['Department', employee.department],
                            ['Type', employee.employment_type_label],
                            ['Manager', employee.manager?.name],
                            [
                                'Shift',
                                employee.shift
                                    ? `${employee.shift.name} (${employee.shift.hours})`
                                    : 'Workspace default',
                            ],
                            ['Works', employee.work_type_label],
                            ['Hired', formatDate(employee.hired_on)],
                            ['Employee number', employee.employee_number],
                        ]}
                    />
                </Section>
                <Section title="Personal">
                    <Details
                        rows={[
                            ['Phone', employee.phone],
                            ['Birth date', formatDate(employee.birth_date)],
                            ['Address', employee.address],
                            [
                                'Emergency contact',
                                [
                                    employee.emergency_contact_name,
                                    employee.emergency_contact_phone,
                                ]
                                    .filter(Boolean)
                                    .join(' · '),
                            ],
                        ]}
                    />
                    <p className="mt-3 text-xs text-muted-foreground">
                        Ask HR to correct anything here.
                    </p>
                </Section>
            </div>
        );
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            manager_id: data.manager_id === '' ? null : Number(data.manager_id),
            shift_id: data.shift_id === '' ? null : Number(data.shift_id),
        }));
        form.patch(
            updateEmployee({ workspace: workspace.slug, employee: employee.id })
                .url,
            { preserveScroll: true },
        );
    }

    const text = (
        key: keyof typeof form.data,
        label: string,
        type = 'text',
    ) => (
        <Field
            label={label}
            htmlFor={`employee-${key}`}
            error={form.errors[key]}
        >
            <Input
                id={`employee-${key}`}
                type={type}
                value={form.data[key]}
                onChange={(event) => form.setData(key, event.target.value)}
            />
        </Field>
    );

    return (
        <form onSubmit={submit} className="space-y-6">
            <Section title="Job">
                <div className="grid gap-4 sm:grid-cols-2">
                    {text('job_title', 'Job title')}
                    {text('department', 'Department')}
                    <SelectField
                        id="employee-employment_type"
                        label="Employment type"
                        value={form.data.employment_type}
                        onChange={(value) =>
                            form.setData('employment_type', value)
                        }
                        options={options.employment_types}
                        error={form.errors.employment_type}
                    />
                    <SelectField
                        id="employee-status"
                        label="Status"
                        value={form.data.status}
                        onChange={(value) => form.setData('status', value)}
                        options={options.statuses}
                        error={form.errors.status}
                    />
                    <SelectField
                        id="employee-manager"
                        label="Manager"
                        value={form.data.manager_id}
                        onChange={(value) => form.setData('manager_id', value)}
                        options={managers.map((manager) => ({
                            value: String(manager.id),
                            label: manager.name,
                        }))}
                        error={form.errors.manager_id}
                        allowEmpty
                        emptyLabel="No manager"
                    />
                    <SelectField
                        id="employee-shift"
                        label="Shift"
                        value={form.data.shift_id}
                        onChange={(value) => form.setData('shift_id', value)}
                        options={shifts}
                        error={form.errors.shift_id}
                        allowEmpty
                        emptyLabel="Workspace default"
                    />
                    <SelectField
                        id="employee-work_type"
                        label="Work type"
                        value={form.data.work_type}
                        onChange={(value) => form.setData('work_type', value)}
                        options={options.work_types}
                        error={form.errors.work_type}
                    />
                    {text('employee_number', 'Employee number')}
                    {text('hired_on', 'Hired on', 'date')}
                    {text('terminated_on', 'Terminated on', 'date')}
                </div>
            </Section>

            <Section title="Personal">
                <div className="grid gap-4 sm:grid-cols-2">
                    {text('phone', 'Phone', 'tel')}
                    {text('birth_date', 'Birth date', 'date')}
                    <Field
                        label="Address"
                        htmlFor="employee-address"
                        error={form.errors.address}
                        className="sm:col-span-2"
                    >
                        <Textarea
                            id="employee-address"
                            value={form.data.address}
                            onChange={(event) =>
                                form.setData('address', event.target.value)
                            }
                            rows={2}
                        />
                    </Field>
                    {text('emergency_contact_name', 'Emergency contact')}
                    {text(
                        'emergency_contact_phone',
                        'Emergency contact phone',
                        'tel',
                    )}
                </div>
            </Section>

            <div className="flex justify-end">
                <Button
                    type="submit"
                    disabled={form.processing || !form.isDirty}
                >
                    Save changes
                </Button>
            </div>
        </form>
    );
}

function Details({ rows }: { rows: [string, string | null | undefined][] }) {
    return (
        <dl className="grid grid-cols-[8rem_minmax(0,1fr)] gap-x-4 gap-y-2 text-sm">
            {rows.map(([label, value]) => (
                <div key={label} className="contents">
                    <dt className="text-muted-foreground">{label}</dt>
                    <dd className="break-words">
                        {value && value !== '—' ? (
                            value
                        ) : (
                            <span className="text-muted-foreground">—</span>
                        )}
                    </dd>
                </div>
            ))}
        </dl>
    );
}

// -- Employment history ----------------------------------------------------

function HistoryTab({
    history,
    options,
    can_manage,
    ids,
}: Props & { ids: Ids }) {
    const [open, setOpen] = useState(false);

    return (
        <Section
            title="Employment history"
            action={
                can_manage && (
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() => setOpen(true)}
                    >
                        <Plus aria-hidden="true" />
                        Record change
                    </Button>
                )
            }
        >
            {history.length === 0 ? (
                <EmptyNote>No history yet.</EmptyNote>
            ) : (
                <ol className="relative space-y-5 border-l border-border pl-5">
                    {history.map((entry) => (
                        <li key={entry.id} className="relative">
                            <span
                                aria-hidden="true"
                                className="absolute top-1.5 -left-[1.4rem] size-2.5 rounded-full bg-primary ring-4 ring-card"
                            />
                            <div className="flex items-start justify-between gap-3">
                                <div className="min-w-0">
                                    <p className="text-sm font-semibold">
                                        {entry.change_label}
                                        <span className="ml-2 font-normal text-muted-foreground">
                                            {formatDate(entry.effective_on)}
                                        </span>
                                    </p>
                                    <p className="text-sm text-muted-foreground">
                                        {[
                                            entry.job_title,
                                            entry.department,
                                            entry.employment_type_label,
                                        ]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </p>
                                    {entry.notes && (
                                        <p className="mt-1 text-sm whitespace-pre-line">
                                            {entry.notes}
                                        </p>
                                    )}
                                    {entry.recorded_by && (
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            Recorded by {entry.recorded_by}
                                        </p>
                                    )}
                                </div>
                                {can_manage && (
                                    <DeleteButton
                                        label="Delete history entry"
                                        onConfirm={() =>
                                            router.delete(
                                                destroyHistory({
                                                    ...ids,
                                                    employmentHistory: entry.id,
                                                }).url,
                                                { preserveScroll: true },
                                            )
                                        }
                                    />
                                )}
                            </div>
                        </li>
                    ))}
                </ol>
            )}

            {open && (
                <HistoryDialog
                    ids={ids}
                    options={options}
                    onClose={() => setOpen(false)}
                />
            )}
        </Section>
    );
}

function HistoryDialog({
    ids,
    options,
    onClose,
}: {
    ids: Ids;
    options: Props['options'];
    onClose: () => void;
}) {
    const form = useForm({
        change: 'promoted',
        effective_on: todayString(),
        job_title: '',
        department: '',
        employment_type: '',
        notes: '',
        apply_to_profile: true,
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            employment_type: data.employment_type || null,
        }));
        form.post(storeHistory(ids).url, {
            preserveScroll: true,
            onSuccess: onClose,
        });
    }

    return (
        <FormDialog
            title="Record an employment change"
            description="A promotion, transfer, or other change to this person's job."
            onClose={onClose}
            onSubmit={submit}
            processing={form.processing}
        >
            <div className="grid gap-4 sm:grid-cols-2">
                <SelectField
                    id="history-change"
                    label="Change"
                    value={form.data.change}
                    onChange={(value) => form.setData('change', value)}
                    options={options.changes}
                    error={form.errors.change}
                />
                <Field
                    label="Effective on"
                    htmlFor="history-effective_on"
                    error={form.errors.effective_on}
                >
                    <Input
                        id="history-effective_on"
                        type="date"
                        value={form.data.effective_on}
                        onChange={(event) =>
                            form.setData('effective_on', event.target.value)
                        }
                        required
                    />
                </Field>
                <Field
                    label="New job title"
                    htmlFor="history-job_title"
                    error={form.errors.job_title}
                >
                    <Input
                        id="history-job_title"
                        value={form.data.job_title}
                        onChange={(event) =>
                            form.setData('job_title', event.target.value)
                        }
                    />
                </Field>
                <Field
                    label="New department"
                    htmlFor="history-department"
                    error={form.errors.department}
                >
                    <Input
                        id="history-department"
                        value={form.data.department}
                        onChange={(event) =>
                            form.setData('department', event.target.value)
                        }
                    />
                </Field>
                <SelectField
                    id="history-employment_type"
                    label="Employment type"
                    value={form.data.employment_type}
                    onChange={(value) => form.setData('employment_type', value)}
                    options={options.employment_types}
                    error={form.errors.employment_type}
                    allowEmpty
                    emptyLabel="Unchanged"
                    className="sm:col-span-2"
                />
                <Field
                    label="Notes"
                    htmlFor="history-notes"
                    error={form.errors.notes}
                    className="sm:col-span-2"
                >
                    <Textarea
                        id="history-notes"
                        value={form.data.notes}
                        onChange={(event) =>
                            form.setData('notes', event.target.value)
                        }
                        rows={3}
                    />
                </Field>
            </div>
            <label className="flex items-center gap-2 text-sm">
                <Checkbox
                    checked={form.data.apply_to_profile}
                    onCheckedChange={(checked) =>
                        form.setData('apply_to_profile', checked === true)
                    }
                />
                Also update their current profile
            </label>
        </FormDialog>
    );
}

// -- Contracts -------------------------------------------------------------

function ContractsTab({
    contracts,
    options,
    can_manage,
    ids,
}: Props & { ids: Ids }) {
    const [editing, setEditing] = useState<Contract | 'new' | null>(null);

    return (
        <Section
            title="Contracts"
            action={
                can_manage && (
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() => setEditing('new')}
                    >
                        <Plus aria-hidden="true" />
                        Add contract
                    </Button>
                )
            }
        >
            {contracts.length === 0 ? (
                <EmptyNote>No contracts on file.</EmptyNote>
            ) : (
                <ul className="divide-y divide-border">
                    {contracts.map((contract) => (
                        <li
                            key={contract.id}
                            className="flex flex-wrap items-start justify-between gap-3 py-3 first:pt-0 last:pb-0"
                        >
                            <div className="min-w-0">
                                <p className="flex flex-wrap items-center gap-2 text-sm font-semibold">
                                    {contract.title}
                                    <StatusPill
                                        status={contract.effective_status}
                                        label={contract.effective_status_label}
                                    />
                                </p>
                                <p className="text-sm text-muted-foreground">
                                    {contract.employment_type_label} ·{' '}
                                    {formatDate(contract.starts_on)} –{' '}
                                    {contract.ends_on
                                        ? formatDate(contract.ends_on)
                                        : 'open-ended'}
                                </p>
                                {contract.salary_amount && (
                                    <p className="text-sm tabular-nums">
                                        {Number(
                                            contract.salary_amount,
                                        ).toLocaleString(undefined, {
                                            style: 'currency',
                                            currency:
                                                contract.salary_currency ??
                                                'USD',
                                        })}
                                        {contract.pay_frequency && (
                                            <span className="text-muted-foreground">
                                                {' '}
                                                /{' '}
                                                {PAY_FREQUENCIES.find(
                                                    (frequency) =>
                                                        frequency.value ===
                                                        contract.pay_frequency,
                                                )?.label.toLowerCase()}
                                            </span>
                                        )}
                                    </p>
                                )}
                                {contract.notes && (
                                    <p className="mt-1 text-sm whitespace-pre-line text-muted-foreground">
                                        {contract.notes}
                                    </p>
                                )}
                            </div>
                            <div className="flex items-center gap-1">
                                {contract.has_file && (
                                    <Button size="sm" variant="ghost" asChild>
                                        <a
                                            href={
                                                downloadContract({
                                                    ...ids,
                                                    contract: contract.id,
                                                }).url
                                            }
                                        >
                                            <Download aria-hidden="true" />
                                            <span className="max-w-32 truncate">
                                                {contract.file_name}
                                            </span>
                                        </a>
                                    </Button>
                                )}
                                {can_manage && (
                                    <>
                                        <Button
                                            size="icon"
                                            variant="ghost"
                                            onClick={() => setEditing(contract)}
                                            aria-label="Edit contract"
                                        >
                                            <Pencil aria-hidden="true" />
                                        </Button>
                                        <DeleteButton
                                            label="Delete contract"
                                            onConfirm={() =>
                                                router.delete(
                                                    destroyContract({
                                                        ...ids,
                                                        contract: contract.id,
                                                    }).url,
                                                    { preserveScroll: true },
                                                )
                                            }
                                        />
                                    </>
                                )}
                            </div>
                        </li>
                    ))}
                </ul>
            )}

            {editing !== null && (
                <ContractDialog
                    key={editing === 'new' ? 'new' : editing.id}
                    contract={editing === 'new' ? null : editing}
                    ids={ids}
                    options={options}
                    onClose={() => setEditing(null)}
                />
            )}
        </Section>
    );
}

function ContractDialog({
    contract,
    ids,
    options,
    onClose,
}: {
    contract: Contract | null;
    ids: Ids;
    options: Props['options'];
    onClose: () => void;
}) {
    const form = useForm<{
        title: string;
        employment_type: string;
        status: string;
        starts_on: string;
        ends_on: string;
        salary_amount: string;
        salary_currency: string;
        pay_frequency: string;
        notes: string;
        file: File | null;
    }>({
        title: contract?.title ?? 'Employment contract',
        employment_type: contract?.employment_type ?? 'full_time',
        status: contract?.status ?? 'active',
        starts_on: contract?.starts_on ?? todayString(),
        ends_on: contract?.ends_on ?? '',
        salary_amount: contract?.salary_amount ?? '',
        salary_currency: contract?.salary_currency ?? '',
        pay_frequency: contract?.pay_frequency ?? '',
        notes: contract?.notes ?? '',
        file: null,
    });

    function submit(event: FormEvent) {
        event.preventDefault();

        // Files need a multipart POST; an edit spoofs PATCH over it.
        form.transform((data) => ({
            ...data,
            ends_on: data.ends_on || null,
            salary_amount: data.salary_amount || null,
            salary_currency: data.salary_currency
                ? data.salary_currency.toUpperCase()
                : null,
            pay_frequency: data.pay_frequency || null,
            ...(contract ? { _method: 'patch' } : {}),
        }));

        const url = contract
            ? updateContract({ ...ids, contract: contract.id }).url
            : storeContract(ids).url;

        form.post(url, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: onClose,
        });
    }

    return (
        <FormDialog
            title={contract ? 'Edit contract' : 'Add a contract'}
            onClose={onClose}
            onSubmit={submit}
            processing={form.processing}
        >
            <div className="grid gap-4 sm:grid-cols-2">
                <Field
                    label="Title"
                    htmlFor="contract-title"
                    error={form.errors.title}
                    className="sm:col-span-2"
                >
                    <Input
                        id="contract-title"
                        value={form.data.title}
                        onChange={(event) =>
                            form.setData('title', event.target.value)
                        }
                        required
                    />
                </Field>
                <SelectField
                    id="contract-type"
                    label="Employment type"
                    value={form.data.employment_type}
                    onChange={(value) => form.setData('employment_type', value)}
                    options={options.employment_types}
                    error={form.errors.employment_type}
                />
                <SelectField
                    id="contract-status"
                    label="Status"
                    value={form.data.status}
                    onChange={(value) => form.setData('status', value)}
                    options={options.contract_statuses}
                    error={form.errors.status}
                />
                <Field
                    label="Starts on"
                    htmlFor="contract-starts_on"
                    error={form.errors.starts_on}
                >
                    <Input
                        id="contract-starts_on"
                        type="date"
                        value={form.data.starts_on}
                        onChange={(event) =>
                            form.setData('starts_on', event.target.value)
                        }
                        required
                    />
                </Field>
                <Field
                    label="Ends on"
                    htmlFor="contract-ends_on"
                    error={form.errors.ends_on}
                >
                    <Input
                        id="contract-ends_on"
                        type="date"
                        value={form.data.ends_on}
                        onChange={(event) =>
                            form.setData('ends_on', event.target.value)
                        }
                    />
                </Field>
                <Field
                    label="Salary"
                    htmlFor="contract-salary"
                    error={form.errors.salary_amount}
                >
                    <Input
                        id="contract-salary"
                        type="number"
                        min="0"
                        step="0.01"
                        value={form.data.salary_amount}
                        onChange={(event) =>
                            form.setData('salary_amount', event.target.value)
                        }
                    />
                </Field>
                <Field
                    label="Currency"
                    htmlFor="contract-currency"
                    error={form.errors.salary_currency}
                >
                    <Input
                        id="contract-currency"
                        maxLength={3}
                        placeholder="PHP"
                        value={form.data.salary_currency}
                        onChange={(event) =>
                            form.setData('salary_currency', event.target.value)
                        }
                    />
                </Field>
                <SelectField
                    id="contract-frequency"
                    label="Pay frequency"
                    value={form.data.pay_frequency}
                    onChange={(value) => form.setData('pay_frequency', value)}
                    options={PAY_FREQUENCIES}
                    error={form.errors.pay_frequency}
                    allowEmpty
                    emptyLabel="Not set"
                    className="sm:col-span-2"
                />
                <Field
                    label="Notes"
                    htmlFor="contract-notes"
                    error={form.errors.notes}
                    className="sm:col-span-2"
                >
                    <Textarea
                        id="contract-notes"
                        value={form.data.notes}
                        onChange={(event) =>
                            form.setData('notes', event.target.value)
                        }
                        rows={3}
                    />
                </Field>
                <Field
                    label={
                        contract?.has_file
                            ? 'Replace signed copy'
                            : 'Signed copy'
                    }
                    htmlFor="contract-file"
                    error={form.errors.file}
                    className="sm:col-span-2"
                >
                    <Input
                        id="contract-file"
                        type="file"
                        accept=".pdf,.doc,.docx,.png,.jpg,.jpeg"
                        onChange={(event) =>
                            form.setData(
                                'file',
                                event.target.files?.[0] ?? null,
                            )
                        }
                    />
                </Field>
            </div>
        </FormDialog>
    );
}

// -- Records ---------------------------------------------------------------

function RecordsTab({ records, options, ids }: Props & { ids: Ids }) {
    const [open, setOpen] = useState(false);

    return (
        <Section
            title="Records"
            action={
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() => setOpen(true)}
                >
                    <Plus aria-hidden="true" />
                    File a record
                </Button>
            }
        >
            <p className="-mt-1 mb-3 text-xs text-muted-foreground">
                Only HR can see these.
            </p>
            {records.length === 0 ? (
                <EmptyNote>Nothing on file.</EmptyNote>
            ) : (
                <ul className="divide-y divide-border">
                    {records.map((record) => (
                        <li
                            key={record.id}
                            className="flex items-start justify-between gap-3 py-3 first:pt-0 last:pb-0"
                        >
                            <div className="min-w-0">
                                <p className="flex flex-wrap items-center gap-2 text-sm font-semibold">
                                    <StatusPill
                                        status={record.type}
                                        label={record.type_label}
                                    />
                                    {record.title}
                                </p>
                                {record.body && (
                                    <p className="mt-1 text-sm whitespace-pre-line">
                                        {record.body}
                                    </p>
                                )}
                                <p className="mt-1 text-xs text-muted-foreground">
                                    {formatDate(record.recorded_on)}
                                    {record.recorded_by &&
                                        ` · ${record.recorded_by}`}
                                </p>
                            </div>
                            <div className="flex items-center gap-1">
                                {record.has_file && (
                                    <Button size="sm" variant="ghost" asChild>
                                        <a
                                            href={
                                                downloadRecord({
                                                    ...ids,
                                                    record: record.id,
                                                }).url
                                            }
                                        >
                                            <FileText aria-hidden="true" />
                                            <span className="max-w-32 truncate">
                                                {record.file_name}
                                            </span>
                                        </a>
                                    </Button>
                                )}
                                <DeleteButton
                                    label="Delete record"
                                    onConfirm={() =>
                                        router.delete(
                                            destroyRecord({
                                                ...ids,
                                                record: record.id,
                                            }).url,
                                            { preserveScroll: true },
                                        )
                                    }
                                />
                            </div>
                        </li>
                    ))}
                </ul>
            )}

            {open && (
                <RecordDialog
                    ids={ids}
                    options={options}
                    onClose={() => setOpen(false)}
                />
            )}
        </Section>
    );
}

function RecordDialog({
    ids,
    options,
    onClose,
}: {
    ids: Ids;
    options: Props['options'];
    onClose: () => void;
}) {
    const form = useForm<{
        type: string;
        title: string;
        body: string;
        recorded_on: string;
        file: File | null;
    }>({
        type: 'note',
        title: '',
        body: '',
        recorded_on: todayString(),
        file: null,
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(storeRecord(ids).url, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: onClose,
        });
    }

    return (
        <FormDialog
            title="File a record"
            onClose={onClose}
            onSubmit={submit}
            processing={form.processing}
        >
            <div className="grid gap-4 sm:grid-cols-2">
                <SelectField
                    id="record-type"
                    label="Type"
                    value={form.data.type}
                    onChange={(value) => form.setData('type', value)}
                    options={options.record_types}
                    error={form.errors.type}
                />
                <Field
                    label="Date"
                    htmlFor="record-date"
                    error={form.errors.recorded_on}
                >
                    <Input
                        id="record-date"
                        type="date"
                        value={form.data.recorded_on}
                        onChange={(event) =>
                            form.setData('recorded_on', event.target.value)
                        }
                        required
                    />
                </Field>
                <Field
                    label="Title"
                    htmlFor="record-title"
                    error={form.errors.title}
                    className="sm:col-span-2"
                >
                    <Input
                        id="record-title"
                        value={form.data.title}
                        onChange={(event) =>
                            form.setData('title', event.target.value)
                        }
                        required
                    />
                </Field>
                <Field
                    label="Details"
                    htmlFor="record-body"
                    error={form.errors.body}
                    className="sm:col-span-2"
                >
                    <Textarea
                        id="record-body"
                        value={form.data.body}
                        onChange={(event) =>
                            form.setData('body', event.target.value)
                        }
                        rows={4}
                    />
                </Field>
                <Field
                    label="Attachment"
                    htmlFor="record-file"
                    error={form.errors.file}
                    className="sm:col-span-2"
                >
                    <Input
                        id="record-file"
                        type="file"
                        onChange={(event) =>
                            form.setData(
                                'file',
                                event.target.files?.[0] ?? null,
                            )
                        }
                    />
                </Field>
            </div>
        </FormDialog>
    );
}

// -- Leave and absences ----------------------------------------------------

function LeaveTab({
    leave_requests,
    balances,
    absences,
    options,
    can_manage,
    ids,
}: Props & { ids: Ids }) {
    const [recording, setRecording] = useState(false);

    return (
        <div className="space-y-6">
            {balances.length > 0 && (
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    {balances.map((balance) => (
                        <div
                            key={balance.leave_type_id}
                            className="rounded-xl border border-border bg-card p-3"
                        >
                            <p className="flex items-center gap-2 text-xs text-muted-foreground">
                                <span
                                    aria-hidden="true"
                                    className={cn(
                                        'size-2 rounded-full',
                                        LEAVE_COLORS[balance.color] ??
                                            'bg-slate-500',
                                    )}
                                />
                                {balance.name}
                            </p>
                            <p className="mt-1 text-lg font-bold tabular-nums">
                                {balance.remaining === null
                                    ? `${balance.used} used`
                                    : `${balance.remaining} left`}
                            </p>
                            {balance.allowance !== null && (
                                <p className="text-xs text-muted-foreground">
                                    of {balance.allowance} this year
                                </p>
                            )}
                        </div>
                    ))}
                </div>
            )}

            <Section title="Leave requests">
                {leave_requests.length === 0 ? (
                    <EmptyNote>No leave requested.</EmptyNote>
                ) : (
                    <ul className="divide-y divide-border">
                        {leave_requests.map((request) => (
                            <li
                                key={request.id}
                                className="flex items-center justify-between gap-3 py-2.5 first:pt-0 last:pb-0"
                            >
                                <div className="min-w-0">
                                    <p className="text-sm font-medium">
                                        {request.leave_type.name} ·{' '}
                                        {formatDays(request.days)}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {formatDateRange(
                                            request.starts_on,
                                            request.ends_on,
                                        )}
                                        {request.reason &&
                                            ` · ${request.reason}`}
                                    </p>
                                </div>
                                <StatusPill
                                    status={request.status}
                                    label={request.status_label}
                                />
                            </li>
                        ))}
                    </ul>
                )}
            </Section>

            <Section
                title="Absences"
                action={
                    can_manage && (
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => setRecording(true)}
                        >
                            <Plus aria-hidden="true" />
                            Record absence
                        </Button>
                    )
                }
            >
                {absences.length === 0 ? (
                    <EmptyNote>No absences recorded.</EmptyNote>
                ) : (
                    <ul className="divide-y divide-border">
                        {absences.map((absence) => (
                            <li
                                key={absence.id}
                                className="flex items-center justify-between gap-3 py-2.5 first:pt-0 last:pb-0"
                            >
                                <div className="min-w-0">
                                    <p className="text-sm font-medium">
                                        {formatDate(absence.date, {
                                            weekday: 'short',
                                            month: 'short',
                                            day: 'numeric',
                                            year: 'numeric',
                                        })}
                                        <span className="ml-2 font-normal text-muted-foreground">
                                            {absence.type_label}
                                        </span>
                                    </p>
                                    {absence.note && (
                                        <p className="text-xs text-muted-foreground">
                                            {absence.note}
                                        </p>
                                    )}
                                </div>
                                {can_manage && (
                                    <DeleteButton
                                        label="Remove absence"
                                        onConfirm={() =>
                                            router.delete(
                                                destroyAbsence({
                                                    ...ids,
                                                    absence: absence.id,
                                                }).url,
                                                { preserveScroll: true },
                                            )
                                        }
                                    />
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </Section>

            {recording && (
                <AbsenceDialog
                    ids={ids}
                    options={options}
                    onClose={() => setRecording(false)}
                />
            )}
        </div>
    );
}

function AbsenceDialog({
    ids,
    options,
    onClose,
}: {
    ids: Ids;
    options: Props['options'];
    onClose: () => void;
}) {
    const form = useForm({ date: todayString(), type: 'unexcused', note: '' });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(storeAbsence(ids).url, {
            preserveScroll: true,
            onSuccess: onClose,
        });
    }

    return (
        <FormDialog
            title="Record an absence"
            description="A day away without approved leave."
            onClose={onClose}
            onSubmit={submit}
            processing={form.processing}
        >
            <div className="grid gap-4 sm:grid-cols-2">
                <Field
                    label="Date"
                    htmlFor="absence-date"
                    error={form.errors.date}
                >
                    <Input
                        id="absence-date"
                        type="date"
                        value={form.data.date}
                        onChange={(event) =>
                            form.setData('date', event.target.value)
                        }
                        required
                    />
                </Field>
                <SelectField
                    id="absence-type"
                    label="Type"
                    value={form.data.type}
                    onChange={(value) => form.setData('type', value)}
                    options={options.absence_types}
                    error={form.errors.type}
                />
                <Field
                    label="Note"
                    htmlFor="absence-note"
                    error={form.errors.note}
                    className="sm:col-span-2"
                >
                    <Textarea
                        id="absence-note"
                        value={form.data.note}
                        onChange={(event) =>
                            form.setData('note', event.target.value)
                        }
                        rows={2}
                    />
                </Field>
            </div>
        </FormDialog>
    );
}

// -- Time ------------------------------------------------------------------

function TimeTab({ entries }: { entries: TimeEntry[] }) {
    return (
        <Section title="Recent shifts">
            {entries.length === 0 ? (
                <EmptyNote>No time logged.</EmptyNote>
            ) : (
                <ul className="divide-y divide-border">
                    {entries.map((entry) => (
                        <li
                            key={entry.id}
                            className="flex flex-wrap items-center justify-between gap-3 py-2.5 first:pt-0 last:pb-0"
                        >
                            <div className="min-w-0">
                                <p className="text-sm font-medium tabular-nums">
                                    {formatDateTime(entry.clock_in_at)} –{' '}
                                    {entry.clock_out_at
                                        ? formatDateTime(entry.clock_out_at)
                                        : 'now'}
                                </p>
                                <p className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                    <LocationLink
                                        stamp={entry.clock_in_location}
                                        label="In"
                                    />
                                    <LocationLink
                                        stamp={entry.clock_out_location}
                                        label="Out"
                                    />
                                    {entry.note && <span>{entry.note}</span>}
                                </p>
                            </div>
                            <span
                                className={cn(
                                    'text-sm tabular-nums',
                                    entry.is_open &&
                                        'font-semibold text-emerald-600 dark:text-emerald-400',
                                )}
                            >
                                {formatMinutes(entry.minutes)}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </Section>
    );
}
