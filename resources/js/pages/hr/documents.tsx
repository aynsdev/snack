import { router, useForm } from '@inertiajs/react';
import {
    Check,
    ChevronDown,
    FilePlus2,
    FileText,
    Upload,
    X,
} from 'lucide-react';
import type { FormEvent } from 'react';
import { useMemo, useState } from 'react';
import { DeleteButton, FormDialog } from '@/components/hr/hr-dialogs';
import {
    EmptyNote,
    Field,
    formatDate,
    HrPage,
    PersonAvatar,
    Section,
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
    approve,
    destroy,
    download,
    reject,
    store,
    upload,
} from '@/routes/hr/documents';
import type { DocumentRequest, DocumentSubmission } from '@/types/hr';

type Props = {
    my_documents: DocumentSubmission[];
    requests: DocumentRequest[];
    employees: { id: number; name: string; department: string | null }[];
    formats: string[];
    can_manage: boolean;
};

type Dialog =
    | { kind: 'request' }
    | { kind: 'upload'; submission: DocumentSubmission }
    | {
          kind: 'review';
          submission: DocumentSubmission;
          title: string;
          decision: 'approve' | 'reject';
      };

/**
 * Paperwork HR asks for. Everybody uploads their own; HR asks, follows who
 * has answered, and approves or sends documents back.
 */
export default function DocumentsPage({
    my_documents,
    requests,
    employees,
    formats,
    can_manage,
}: Props) {
    const [dialog, setDialog] = useState<Dialog | null>(null);
    const close = () => setDialog(null);
    const outstanding = my_documents.filter((document) => document.can.upload);

    return (
        <HrPage
            title="Documents"
            description="Paperwork HR has asked you for, and what you have sent."
            actions={
                can_manage && (
                    <Button onClick={() => setDialog({ kind: 'request' })}>
                        <FilePlus2 aria-hidden="true" />
                        Request documents
                    </Button>
                )
            }
        >
            <Section
                title={
                    outstanding.length > 0
                        ? `My documents (${outstanding.length} to upload)`
                        : 'My documents'
                }
            >
                {my_documents.length === 0 ? (
                    <EmptyNote>HR has not asked you for anything.</EmptyNote>
                ) : (
                    <ul className="divide-y divide-border">
                        {my_documents.map((document) => (
                            <li
                                key={document.id}
                                className="flex flex-wrap items-center gap-3 py-3 first:pt-0 last:pb-0"
                            >
                                <div className="min-w-0 flex-1">
                                    <p className="text-sm font-medium">
                                        {document.request?.title}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {document.request?.allowed_formats
                                            .join(', ')
                                            .toUpperCase()}{' '}
                                        · up to {document.request?.max_size_mb}{' '}
                                        MB
                                        {document.request?.due_on &&
                                            ` · due ${formatDate(document.request.due_on)}`}
                                        {document.expires_on &&
                                            ` · expires ${formatDate(document.expires_on)}`}
                                    </p>
                                    {document.request?.description && (
                                        <p className="mt-0.5 text-xs whitespace-pre-line text-muted-foreground">
                                            {document.request.description}
                                        </p>
                                    )}
                                    {document.status === 'rejected' &&
                                        document.review_note && (
                                            <p className="mt-1 text-xs text-rose-600 dark:text-rose-400">
                                                {document.reviewer}: “
                                                {document.review_note}”
                                            </p>
                                        )}
                                </div>
                                <StatusPill
                                    status={document.status}
                                    label={document.status_label}
                                />
                                <FileLink submission={document} />
                                {document.can.upload && (
                                    <Button
                                        size="sm"
                                        onClick={() =>
                                            setDialog({
                                                kind: 'upload',
                                                submission: document,
                                            })
                                        }
                                    >
                                        <Upload aria-hidden="true" />
                                        {document.status === 'rejected'
                                            ? 'Upload again'
                                            : 'Upload'}
                                    </Button>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </Section>

            {can_manage && (
                <Section title="Requests">
                    {requests.length === 0 ? (
                        <EmptyNote>
                            You have not asked anybody for documents yet.
                        </EmptyNote>
                    ) : (
                        <ul className="space-y-3">
                            {requests.map((request) => (
                                <RequestCard
                                    key={request.id}
                                    request={request}
                                    onReview={(submission, decision) =>
                                        setDialog({
                                            kind: 'review',
                                            submission,
                                            title: request.title,
                                            decision,
                                        })
                                    }
                                />
                            ))}
                        </ul>
                    )}
                </Section>
            )}

            {dialog?.kind === 'request' && (
                <RequestDialog
                    employees={employees}
                    formats={formats}
                    onClose={close}
                />
            )}
            {dialog?.kind === 'upload' && (
                <UploadDialog submission={dialog.submission} onClose={close} />
            )}
            {dialog?.kind === 'review' && (
                <ReviewDialog
                    submission={dialog.submission}
                    title={dialog.title}
                    decision={dialog.decision}
                    onClose={close}
                />
            )}
        </HrPage>
    );
}

function FileLink({ submission }: { submission: DocumentSubmission }) {
    const workspace = useWorkspace();

    if (!submission.has_file) {
        return null;
    }

    return (
        <Button size="sm" variant="ghost" asChild>
            <a
                href={
                    download({
                        workspace: workspace.slug,
                        documentSubmission: submission.id,
                    }).url
                }
            >
                <FileText aria-hidden="true" />
                <span className="max-w-32 truncate">
                    {submission.file_name}
                </span>
            </a>
        </Button>
    );
}

/**
 * One request, its progress, and — opened — everybody's answer to it.
 */
function RequestCard({
    request,
    onReview,
}: {
    request: DocumentRequest;
    onReview: (
        submission: DocumentSubmission,
        decision: 'approve' | 'reject',
    ) => void;
}) {
    const workspace = useWorkspace();
    const awaiting = request.submissions.filter(
        (submission) => submission.status === 'submitted',
    ).length;
    const [open, setOpen] = useState(awaiting > 0);

    return (
        <li className="rounded-lg border border-border">
            <div className="flex flex-wrap items-center gap-3 p-3">
                <button
                    type="button"
                    onClick={() => setOpen(!open)}
                    aria-expanded={open}
                    className="flex min-w-0 flex-1 items-center gap-2 text-left"
                >
                    <ChevronDown
                        aria-hidden="true"
                        className={cn(
                            'size-4 shrink-0 text-muted-foreground transition-transform',
                            !open && '-rotate-90',
                        )}
                    />
                    <span className="min-w-0">
                        <span className="block truncate text-sm font-medium">
                            {request.title}
                        </span>
                        <span className="block text-xs text-muted-foreground">
                            {request.uploaded} of {request.total} uploaded ·{' '}
                            {request.approved} approved
                            {awaiting > 0 && ` · ${awaiting} to review`}
                            {request.due_on &&
                                ` · due ${formatDate(request.due_on)}`}
                        </span>
                    </span>
                </button>
                <div
                    className="h-1.5 w-24 overflow-hidden rounded-full bg-muted"
                    role="img"
                    aria-label={`${request.approved} of ${request.total} approved`}
                >
                    <div
                        className="h-full rounded-full bg-emerald-500"
                        style={{
                            width: `${request.total === 0 ? 0 : (request.approved / request.total) * 100}%`,
                        }}
                    />
                </div>
                <DeleteButton
                    label={`Delete ${request.title}`}
                    onConfirm={() =>
                        router.delete(
                            destroy({
                                workspace: workspace.slug,
                                documentRequest: request.id,
                            }).url,
                            { preserveScroll: true },
                        )
                    }
                />
            </div>

            {open && (
                <ul className="divide-y divide-border border-t border-border">
                    {request.submissions.map((submission) => (
                        <li
                            key={submission.id}
                            className="flex flex-wrap items-center gap-3 px-3 py-2"
                        >
                            {submission.employee && (
                                <PersonAvatar
                                    person={submission.employee}
                                    className="size-7"
                                />
                            )}
                            <span className="min-w-0 flex-1 truncate text-sm">
                                {submission.employee?.name}
                                {submission.expires_on && (
                                    <span className="text-xs text-muted-foreground">
                                        {' '}
                                        · expires{' '}
                                        {formatDate(submission.expires_on)}
                                    </span>
                                )}
                            </span>
                            <StatusPill
                                status={submission.status}
                                label={submission.status_label}
                            />
                            <FileLink submission={submission} />
                            {submission.can.review && (
                                <div className="flex gap-1.5">
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() =>
                                            onReview(submission, 'reject')
                                        }
                                    >
                                        <X aria-hidden="true" />
                                        Reject
                                    </Button>
                                    <Button
                                        size="sm"
                                        onClick={() =>
                                            onReview(submission, 'approve')
                                        }
                                    >
                                        <Check aria-hidden="true" />
                                        Approve
                                    </Button>
                                </div>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </li>
    );
}

function RequestDialog({
    employees,
    formats,
    onClose,
}: {
    employees: Props['employees'];
    formats: string[];
    onClose: () => void;
}) {
    const workspace = useWorkspace();
    const [search, setSearch] = useState('');
    const form = useForm<{
        title: string;
        description: string;
        allowed_formats: string[];
        max_size_mb: string;
        due_on: string;
        employee_ids: number[];
    }>({
        title: '',
        description: '',
        allowed_formats: ['pdf', 'jpg', 'jpeg', 'png'],
        max_size_mb: '5',
        due_on: '',
        employee_ids: [],
    });

    const visible = useMemo(() => {
        const needle = search.trim().toLowerCase();

        return needle === ''
            ? employees
            : employees.filter((person) =>
                  `${person.name} ${person.department ?? ''}`
                      .toLowerCase()
                      .includes(needle),
              );
    }, [employees, search]);

    const allVisibleChosen =
        visible.length > 0 &&
        visible.every((person) => form.data.employee_ids.includes(person.id));

    function toggle<T>(list: T[], value: T): T[] {
        return list.includes(value)
            ? list.filter((item) => item !== value)
            : [...list, value];
    }

    function toggleVisible() {
        const ids = visible.map((person) => person.id);

        form.setData(
            'employee_ids',
            allVisibleChosen
                ? form.data.employee_ids.filter((id) => !ids.includes(id))
                : [...new Set([...form.data.employee_ids, ...ids])],
        );
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            max_size_mb: Number(data.max_size_mb),
            due_on: data.due_on === '' ? null : data.due_on,
        }));
        form.post(store(workspace.slug).url, {
            preserveScroll: true,
            onSuccess: onClose,
        });
    }

    return (
        <FormDialog
            title="Request documents"
            description="Everybody you choose gets a message asking them to upload."
            onClose={onClose}
            onSubmit={submit}
            processing={form.processing}
            submitLabel={`Ask ${form.data.employee_ids.length || ''} ${form.data.employee_ids.length === 1 ? 'person' : 'people'}`}
        >
            <Field
                label="What"
                htmlFor="document-title"
                error={form.errors.title}
            >
                <Input
                    id="document-title"
                    value={form.data.title}
                    onChange={(event) =>
                        form.setData('title', event.target.value)
                    }
                    placeholder="Government ID"
                    required
                />
            </Field>
            <Field
                label="Instructions"
                htmlFor="document-description"
                error={form.errors.description}
            >
                <Textarea
                    id="document-description"
                    rows={2}
                    value={form.data.description}
                    onChange={(event) =>
                        form.setData('description', event.target.value)
                    }
                    placeholder="Optional"
                />
            </Field>
            <fieldset>
                <legend className="mb-2 text-sm font-medium">
                    Accepted formats
                </legend>
                <div className="flex flex-wrap gap-1.5">
                    {formats.map((format) => {
                        const selected =
                            form.data.allowed_formats.includes(format);

                        return (
                            <button
                                key={format}
                                type="button"
                                aria-pressed={selected}
                                onClick={() =>
                                    form.setData(
                                        'allowed_formats',
                                        toggle(
                                            form.data.allowed_formats,
                                            format,
                                        ),
                                    )
                                }
                                className={cn(
                                    'rounded-md border px-2.5 py-1 text-xs font-medium uppercase transition-colors',
                                    selected
                                        ? 'border-primary bg-primary text-primary-foreground'
                                        : 'border-border text-muted-foreground hover:bg-accent',
                                )}
                            >
                                {format}
                            </button>
                        );
                    })}
                </div>
                {form.errors.allowed_formats && (
                    <p className="mt-1 text-sm text-destructive">
                        {form.errors.allowed_formats}
                    </p>
                )}
            </fieldset>
            <div className="grid gap-4 sm:grid-cols-2">
                <Field
                    label="Max size (MB)"
                    htmlFor="document-size"
                    error={form.errors.max_size_mb}
                >
                    <Input
                        id="document-size"
                        type="number"
                        min={1}
                        max={20}
                        value={form.data.max_size_mb}
                        onChange={(event) =>
                            form.setData('max_size_mb', event.target.value)
                        }
                        required
                    />
                </Field>
                <Field
                    label="Due"
                    htmlFor="document-due"
                    error={form.errors.due_on}
                >
                    <Input
                        id="document-due"
                        type="date"
                        min={todayString()}
                        value={form.data.due_on}
                        onChange={(event) =>
                            form.setData('due_on', event.target.value)
                        }
                    />
                </Field>
            </div>
            <fieldset>
                <div className="mb-2 flex items-center justify-between gap-2">
                    <legend className="text-sm font-medium">
                        From ({form.data.employee_ids.length} chosen)
                    </legend>
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        onClick={toggleVisible}
                    >
                        {allVisibleChosen ? 'Clear these' : 'Choose all shown'}
                    </Button>
                </div>
                <Input
                    value={search}
                    onChange={(event) => setSearch(event.target.value)}
                    placeholder="Search by name or department"
                    aria-label="Search employees"
                    className="mb-2"
                />
                <ul className="max-h-48 space-y-1 overflow-y-auto rounded-md border border-border p-2">
                    {visible.map((person) => (
                        <li key={person.id}>
                            <label className="flex items-center gap-2 text-sm">
                                <Checkbox
                                    checked={form.data.employee_ids.includes(
                                        person.id,
                                    )}
                                    onCheckedChange={() =>
                                        form.setData(
                                            'employee_ids',
                                            toggle(
                                                form.data.employee_ids,
                                                person.id,
                                            ),
                                        )
                                    }
                                />
                                <span className="truncate">{person.name}</span>
                                {person.department && (
                                    <span className="truncate text-xs text-muted-foreground">
                                        {person.department}
                                    </span>
                                )}
                            </label>
                        </li>
                    ))}
                </ul>
                {form.errors.employee_ids && (
                    <p className="mt-1 text-sm text-destructive">
                        {form.errors.employee_ids}
                    </p>
                )}
            </fieldset>
        </FormDialog>
    );
}

function UploadDialog({
    submission,
    onClose,
}: {
    submission: DocumentSubmission;
    onClose: () => void;
}) {
    const workspace = useWorkspace();
    const form = useForm<{ file: File | null; expires_on: string }>({
        file: null,
        expires_on: '',
    });
    const request = submission.request;

    function submit(event: FormEvent) {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            expires_on: data.expires_on === '' ? null : data.expires_on,
        }));
        form.post(
            upload({
                workspace: workspace.slug,
                documentSubmission: submission.id,
            }).url,
            { forceFormData: true, preserveScroll: true, onSuccess: onClose },
        );
    }

    return (
        <FormDialog
            title={`Upload ${request?.title ?? 'document'}`}
            description={`${request?.allowed_formats.join(', ').toUpperCase()}, up to ${request?.max_size_mb} MB.`}
            onClose={onClose}
            onSubmit={submit}
            processing={form.processing}
            submitLabel="Upload"
        >
            <Field label="File" htmlFor="upload-file" error={form.errors.file}>
                <Input
                    id="upload-file"
                    type="file"
                    accept={request?.allowed_formats
                        .map((format) => `.${format}`)
                        .join(',')}
                    onChange={(event) =>
                        form.setData('file', event.target.files?.[0] ?? null)
                    }
                    required
                />
            </Field>
            <Field
                label="Expires (if it does)"
                htmlFor="upload-expires"
                error={form.errors.expires_on}
            >
                <Input
                    id="upload-expires"
                    type="date"
                    min={todayString()}
                    value={form.data.expires_on}
                    onChange={(event) =>
                        form.setData('expires_on', event.target.value)
                    }
                />
            </Field>
        </FormDialog>
    );
}

function ReviewDialog({
    submission,
    title,
    decision,
    onClose,
}: {
    submission: DocumentSubmission;
    title: string;
    decision: 'approve' | 'reject';
    onClose: () => void;
}) {
    const workspace = useWorkspace();
    const form = useForm({ note: '' });
    const route = decision === 'approve' ? approve : reject;

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(
            route({
                workspace: workspace.slug,
                documentSubmission: submission.id,
            }).url,
            { preserveScroll: true, onSuccess: onClose },
        );
    }

    return (
        <FormDialog
            title={decision === 'approve' ? 'Approve document' : 'Send back'}
            description={`${submission.employee?.name} · ${title}`}
            onClose={onClose}
            onSubmit={submit}
            processing={form.processing}
            submitLabel={decision === 'approve' ? 'Approve' : 'Send back'}
        >
            <Field
                label={
                    decision === 'approve'
                        ? 'Note to the employee'
                        : 'What needs fixing'
                }
                htmlFor="document-review-note"
                error={form.errors.note}
            >
                <Textarea
                    id="document-review-note"
                    rows={3}
                    value={form.data.note}
                    onChange={(event) =>
                        form.setData('note', event.target.value)
                    }
                    placeholder={decision === 'approve' ? 'Optional' : ''}
                />
            </Field>
        </FormDialog>
    );
}
