import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { FormDialog } from '@/components/hr/hr-dialogs';
import { Field, formatDateRange, formatDays } from '@/components/hr/hr-ui';
import { Textarea } from '@/components/ui/textarea';
import { useWorkspace } from '@/hooks/use-workspace';
import {
    approve as approveLeave,
    reject as rejectLeave,
} from '@/routes/hr/leave';
import type { LeaveRequest } from '@/types/hr';

/**
 * Approving or rejecting a leave request, with an optional note back to the
 * employee. Used by the leave queue and the HR dashboard.
 */
export function LeaveReviewDialog({
    request,
    decision,
    onClose,
}: {
    request: LeaveRequest;
    decision: 'approve' | 'reject';
    onClose: () => void;
}) {
    const workspace = useWorkspace();
    const form = useForm({ note: '' });
    const route = decision === 'approve' ? approveLeave : rejectLeave;

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(
            route({ workspace: workspace.slug, leaveRequest: request.id }).url,
            { preserveScroll: true, onSuccess: onClose },
        );
    }

    return (
        <FormDialog
            title={decision === 'approve' ? 'Approve leave' : 'Reject leave'}
            description={`${request.employee.name} · ${request.leave_type.name} · ${formatDateRange(request.starts_on, request.ends_on)} (${formatDays(request.days)})`}
            onClose={onClose}
            onSubmit={submit}
            processing={form.processing}
            submitLabel={decision === 'approve' ? 'Approve' : 'Reject'}
        >
            <Field
                label="Note to the employee"
                htmlFor="review-note"
                error={form.errors.note}
            >
                <Textarea
                    id="review-note"
                    value={form.data.note}
                    onChange={(event) =>
                        form.setData('note', event.target.value)
                    }
                    rows={3}
                    placeholder="Optional"
                />
            </Field>
        </FormDialog>
    );
}
