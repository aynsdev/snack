import { router, useForm } from '@inertiajs/react';
import {
    BookOpenCheck,
    ChevronDown,
    CircleCheck,
    FileText,
    Pencil,
    Plus,
} from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { DeleteButton, FormDialog } from '@/components/hr/hr-dialogs';
import { EmptyNote, Field, formatDate, HrPage } from '@/components/hr/hr-ui';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { useWorkspace } from '@/hooks/use-workspace';
import { cn } from '@/lib/utils';
import {
    acknowledge,
    destroy,
    download,
    store,
    update,
} from '@/routes/hr/policies';
import type { CompanyPolicy } from '@/types/hr';

type Props = {
    policies: CompanyPolicy[];
    headcount: number | null;
    can_manage: boolean;
};

/**
 * The company handbook. Everybody reads it; some policies ask to be
 * acknowledged, and HR sees how many have.
 */
export default function PoliciesPage({
    policies,
    headcount,
    can_manage,
}: Props) {
    const [editing, setEditing] = useState<CompanyPolicy | 'new' | null>(null);
    const toAcknowledge = policies.filter(
        (policy) => policy.can.acknowledge && !policy.acknowledged,
    ).length;

    return (
        <HrPage
            title="Policies"
            description={
                toAcknowledge > 0
                    ? `${toAcknowledge} ${toAcknowledge === 1 ? 'policy needs' : 'policies need'} your acknowledgement.`
                    : 'The company handbook.'
            }
            actions={
                can_manage && (
                    <Button onClick={() => setEditing('new')}>
                        <Plus aria-hidden="true" />
                        New policy
                    </Button>
                )
            }
            width="max-w-3xl"
        >
            {policies.length === 0 ? (
                <EmptyNote>No policies have been published yet.</EmptyNote>
            ) : (
                <ul className="space-y-3">
                    {policies.map((policy) => (
                        <PolicyCard
                            key={policy.id}
                            policy={policy}
                            headcount={headcount}
                            canManage={can_manage}
                            onEdit={() => setEditing(policy)}
                        />
                    ))}
                </ul>
            )}

            {editing !== null && (
                <PolicyDialog
                    key={editing === 'new' ? 'new' : editing.id}
                    policy={editing === 'new' ? null : editing}
                    onClose={() => setEditing(null)}
                />
            )}
        </HrPage>
    );
}

function PolicyCard({
    policy,
    headcount,
    canManage,
    onEdit,
}: {
    policy: CompanyPolicy;
    headcount: number | null;
    canManage: boolean;
    onEdit: () => void;
}) {
    const workspace = useWorkspace();
    const needsYou = policy.can.acknowledge && !policy.acknowledged;
    const [open, setOpen] = useState(needsYou);
    const ids = { workspace: workspace.slug, companyPolicy: policy.id };

    return (
        <li
            className={cn(
                'rounded-xl border bg-card',
                needsYou ? 'border-amber-500/40' : 'border-border',
            )}
        >
            <div className="flex items-center gap-2 p-4">
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
                        <span className="block truncate text-sm font-semibold">
                            {policy.title}
                        </span>
                        <span className="block text-xs text-muted-foreground">
                            Updated{' '}
                            {policy.updated_at &&
                                formatDate(policy.updated_at.slice(0, 10))}
                            {policy.requires_acknowledgement &&
                                canManage &&
                                headcount !== null &&
                                ` · ${policy.acknowledged_count ?? 0} of ${headcount} acknowledged`}
                        </span>
                    </span>
                </button>
                {policy.acknowledged && (
                    <span className="flex items-center gap-1 text-xs font-medium text-emerald-600 dark:text-emerald-400">
                        <CircleCheck aria-hidden="true" className="size-4" />
                        Acknowledged
                    </span>
                )}
                {canManage && (
                    <>
                        <Button
                            size="icon"
                            variant="ghost"
                            aria-label={`Edit ${policy.title}`}
                            onClick={onEdit}
                        >
                            <Pencil aria-hidden="true" />
                        </Button>
                        <DeleteButton
                            label={`Delete ${policy.title}`}
                            onConfirm={() =>
                                router.delete(destroy(ids).url, {
                                    preserveScroll: true,
                                })
                            }
                        />
                    </>
                )}
            </div>

            {open && (
                <div className="space-y-3 border-t border-border p-4">
                    <p className="text-sm whitespace-pre-line">{policy.body}</p>
                    <div className="flex flex-wrap items-center gap-2">
                        {policy.has_file && (
                            <Button size="sm" variant="outline" asChild>
                                <a href={download(ids).url}>
                                    <FileText aria-hidden="true" />
                                    <span className="max-w-48 truncate">
                                        {policy.file_name}
                                    </span>
                                </a>
                            </Button>
                        )}
                        {needsYou && (
                            <Button
                                size="sm"
                                onClick={() =>
                                    router.post(
                                        acknowledge(ids).url,
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                <BookOpenCheck aria-hidden="true" />I have read
                                this
                            </Button>
                        )}
                    </div>
                </div>
            )}
        </li>
    );
}

function PolicyDialog({
    policy,
    onClose,
}: {
    policy: CompanyPolicy | null;
    onClose: () => void;
}) {
    const workspace = useWorkspace();
    const form = useForm<{
        title: string;
        body: string;
        requires_acknowledgement: boolean;
        file: File | null;
        remove_file: boolean;
    }>({
        title: policy?.title ?? '',
        body: policy?.body ?? '',
        requires_acknowledgement: policy?.requires_acknowledgement ?? true,
        file: null,
        remove_file: false,
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        const url = policy
            ? update({ workspace: workspace.slug, companyPolicy: policy.id })
                  .url
            : store(workspace.slug).url;

        form.post(url, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: onClose,
        });
    }

    return (
        <FormDialog
            title={policy ? 'Edit policy' : 'New policy'}
            description={
                policy?.requires_acknowledgement
                    ? 'Changing the text asks everybody to acknowledge it again.'
                    : undefined
            }
            onClose={onClose}
            onSubmit={submit}
            processing={form.processing}
            submitLabel={policy ? 'Save' : 'Publish'}
        >
            <Field
                label="Title"
                htmlFor="policy-title"
                error={form.errors.title}
            >
                <Input
                    id="policy-title"
                    value={form.data.title}
                    onChange={(event) =>
                        form.setData('title', event.target.value)
                    }
                    placeholder="Code of conduct"
                    required
                />
            </Field>
            <Field
                label="Policy"
                htmlFor="policy-body"
                error={form.errors.body}
            >
                <Textarea
                    id="policy-body"
                    rows={10}
                    value={form.data.body}
                    onChange={(event) =>
                        form.setData('body', event.target.value)
                    }
                    required
                />
            </Field>
            <Field
                label={policy?.has_file ? 'Replace attachment' : 'Attachment'}
                htmlFor="policy-file"
                error={form.errors.file}
            >
                <Input
                    id="policy-file"
                    type="file"
                    accept=".pdf,.doc,.docx"
                    onChange={(event) =>
                        form.setData('file', event.target.files?.[0] ?? null)
                    }
                />
            </Field>
            {policy?.has_file && (
                <label className="flex items-center gap-2 text-sm">
                    <Checkbox
                        checked={form.data.remove_file}
                        onCheckedChange={(checked) =>
                            form.setData('remove_file', checked === true)
                        }
                    />
                    Remove {policy.file_name}
                </label>
            )}
            <label className="flex items-center gap-2 text-sm">
                <Checkbox
                    checked={form.data.requires_acknowledgement}
                    onCheckedChange={(checked) =>
                        form.setData(
                            'requires_acknowledgement',
                            checked === true,
                        )
                    }
                />
                Everybody must acknowledge they have read it
            </label>
        </FormDialog>
    );
}
