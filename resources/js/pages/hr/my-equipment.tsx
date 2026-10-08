import { router, useForm } from '@inertiajs/react';
import { PackagePlus, Undo2 } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { FormDialog } from '@/components/hr/hr-dialogs';
import {
    EmptyNote,
    Field,
    formatDate,
    HrPage,
    Section,
    SelectField,
    StatusPill,
} from '@/components/hr/hr-ui';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { useWorkspace } from '@/hooks/use-workspace';
import { requestReturn } from '@/routes/hr/equipment-assignments';
import { cancel, store } from '@/routes/hr/equipment-requests';
import type { EquipmentAssignment, HrRequest, Option } from '@/types/hr';

type Props = {
    current: EquipmentAssignment[];
    past: EquipmentAssignment[];
    requests: HrRequest[];
    categories: Option[];
    can_request: boolean;
};

/**
 * Company equipment you have, have had, and have asked for.
 */
export default function MyEquipmentPage({
    current,
    past,
    requests,
    categories,
    can_request,
}: Props) {
    const workspace = useWorkspace();
    const [asking, setAsking] = useState(false);

    return (
        <HrPage
            title="My equipment"
            description="Company equipment handed to you."
            actions={
                can_request &&
                categories.length > 0 && (
                    <Button onClick={() => setAsking(true)}>
                        <PackagePlus aria-hidden="true" />
                        Request equipment
                    </Button>
                )
            }
        >
            <Section title="With you now">
                {current.length === 0 ? (
                    <EmptyNote>
                        You do not have any company equipment.
                    </EmptyNote>
                ) : (
                    <ul className="divide-y divide-border">
                        {current.map((assignment) => (
                            <li
                                key={assignment.id}
                                className="flex flex-wrap items-center gap-3 py-2.5 first:pt-0 last:pb-0"
                            >
                                <div className="min-w-0 flex-1">
                                    <p className="text-sm font-medium">
                                        {assignment.equipment?.name}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {[
                                            assignment.equipment?.category,
                                            assignment.equipment?.tracking_code,
                                            assignment.equipment
                                                ?.serial_number &&
                                                `S/N ${assignment.equipment.serial_number}`,
                                            `since ${formatDate(assignment.assigned_on)}`,
                                        ]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </p>
                                </div>
                                {assignment.return_requested_at ? (
                                    <span className="text-xs text-muted-foreground">
                                        Return requested
                                    </span>
                                ) : (
                                    assignment.can.request_return && (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() =>
                                                router.post(
                                                    requestReturn({
                                                        workspace:
                                                            workspace.slug,
                                                        equipmentAssignment:
                                                            assignment.id,
                                                    }).url,
                                                    {},
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            <Undo2 aria-hidden="true" />
                                            Return it
                                        </Button>
                                    )
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </Section>

            {requests.length > 0 && (
                <Section title="My requests">
                    <ul className="divide-y divide-border">
                        {requests.map((request) => (
                            <li
                                key={request.id}
                                className="flex flex-wrap items-center gap-3 py-2.5 first:pt-0 last:pb-0"
                            >
                                <div className="min-w-0 flex-1">
                                    <p className="text-sm font-medium">
                                        {request.summary}
                                    </p>
                                    {request.review_note && (
                                        <p className="text-xs text-muted-foreground">
                                            {request.reviewer}: “
                                            {request.review_note}”
                                        </p>
                                    )}
                                </div>
                                <StatusPill
                                    status={request.status}
                                    label={request.status_label}
                                />
                                {request.can.cancel && (
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        onClick={() =>
                                            router.post(
                                                cancel({
                                                    workspace: workspace.slug,
                                                    equipmentRequest:
                                                        request.id,
                                                }).url,
                                                {},
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        Withdraw
                                    </Button>
                                )}
                            </li>
                        ))}
                    </ul>
                </Section>
            )}

            {past.length > 0 && (
                <Section title="Returned">
                    <ul className="divide-y divide-border">
                        {past.map((assignment) => (
                            <li
                                key={assignment.id}
                                className="py-2 text-sm first:pt-0 last:pb-0"
                            >
                                {assignment.equipment?.name}
                                <span className="text-xs text-muted-foreground">
                                    {' · '}
                                    {formatDate(assignment.assigned_on)} –{' '}
                                    {formatDate(assignment.returned_on)}
                                </span>
                            </li>
                        ))}
                    </ul>
                </Section>
            )}

            {asking && (
                <AskDialog
                    categories={categories}
                    onClose={() => setAsking(false)}
                />
            )}
        </HrPage>
    );
}

function AskDialog({
    categories,
    onClose,
}: {
    categories: Option[];
    onClose: () => void;
}) {
    const workspace = useWorkspace();
    const form = useForm({ equipment_category_id: '', reason: '' });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            equipment_category_id: Number(data.equipment_category_id),
        }));
        form.post(store(workspace.slug).url, {
            preserveScroll: true,
            onSuccess: onClose,
        });
    }

    return (
        <FormDialog
            title="Request equipment"
            description="HR will be told, and hand something over if they approve."
            onClose={onClose}
            onSubmit={submit}
            processing={form.processing}
            submitLabel="Send request"
        >
            <SelectField
                id="ask-category"
                label="What"
                value={form.data.equipment_category_id}
                onChange={(value) =>
                    form.setData('equipment_category_id', value)
                }
                options={categories}
                error={form.errors.equipment_category_id}
            />
            <Field label="Why" htmlFor="ask-reason" error={form.errors.reason}>
                <Textarea
                    id="ask-reason"
                    rows={3}
                    value={form.data.reason}
                    onChange={(event) =>
                        form.setData('reason', event.target.value)
                    }
                    placeholder="Optional"
                />
            </Field>
        </FormDialog>
    );
}
