import { Form, Head, router } from '@inertiajs/react';
import { MailPlus } from 'lucide-react';
import InputError from '@/components/input-error';
import { EmptyState } from '@/components/snack/empty-state';
import { InviteLinkCard } from '@/components/snack/invite-link-card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useWorkspace } from '@/hooks/use-workspace';
import { destroy, store } from '@/routes/workspace-invitations';
import type { InvitationStatus, WorkspaceInvitation } from '@/types';

type Props = {
    invitations: {
        data: WorkspaceInvitation[];
        meta: { total: number };
    };
    /** The workspace's shareable link, or null if it has never had one. */
    inviteLink: WorkspaceInvitation | null;
};

const STATUS_VARIANT: Record<
    InvitationStatus,
    'default' | 'secondary' | 'destructive' | 'outline'
> = {
    pending: 'default',
    accepted: 'secondary',
    expired: 'outline',
    revoked: 'destructive',
    // Links only; an emailed invitation is spent through `accepted_at`.
    used_up: 'outline',
};

/**
 * Invitation management.
 *
 * Expired invitations stay listed rather than being hidden, so an admin can see
 * that someone was invited and never came — silently dropping the row would
 * make it look like the invitation was never sent.
 */
export default function WorkspaceInvitations({
    invitations,
    inviteLink,
}: Props) {
    const workspace = useWorkspace();

    return (
        <>
            <Head title={`Invitations · ${workspace.name}`} />

            <div className="snack-scroll min-w-0 flex-1 overflow-y-auto">
                <div className="mx-auto max-w-3xl px-6 py-8">
                    <header className="mb-6">
                        <h1 className="text-2xl font-bold tracking-tight">
                            Invite people
                        </h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Share a link, or invite people by email. Both expire
                            automatically and can be revoked at any time.
                        </p>
                    </header>

                    <InviteLinkCard
                        link={inviteLink}
                        workspaceSlug={workspace.slug}
                    />

                    <h2 className="mb-3 font-semibold">Invite by email</h2>

                    <Form
                        action={store(workspace.slug)}
                        resetOnSuccess
                        disableWhileProcessing
                        className="mb-8 rounded-xl border border-border p-4"
                    >
                        {({ errors, processing }) => (
                            <div className="flex flex-wrap items-end gap-3">
                                <div className="min-w-56 flex-1 space-y-2">
                                    <Label htmlFor="email">Email address</Label>
                                    <Input
                                        id="email"
                                        name="email"
                                        type="email"
                                        required
                                        placeholder="colleague@company.com"
                                    />
                                    <InputError message={errors.email} />
                                </div>

                                <div className="w-40 space-y-2">
                                    <Label htmlFor="role">Role</Label>
                                    <Select name="role" defaultValue="member">
                                        <SelectTrigger id="role">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="admin">
                                                Admin
                                            </SelectItem>
                                            <SelectItem value="member">
                                                Member
                                            </SelectItem>
                                            <SelectItem value="guest">
                                                Guest
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                    <InputError message={errors.role} />
                                </div>

                                <Button type="submit" disabled={processing}>
                                    <MailPlus aria-hidden="true" />
                                    Send invitation
                                </Button>
                            </div>
                        )}
                    </Form>

                    {invitations.data.length === 0 ? (
                        <EmptyState
                            icon={MailPlus}
                            title="No outstanding invitations"
                            description="Everyone you have invited has either joined or had their invitation withdrawn."
                        />
                    ) : (
                        <ul className="divide-y divide-border overflow-hidden rounded-xl border border-border">
                            {invitations.data.map((invitation) => (
                                <InvitationRow
                                    key={invitation.id}
                                    invitation={invitation}
                                    workspaceSlug={workspace.slug}
                                />
                            ))}
                        </ul>
                    )}
                </div>
            </div>
        </>
    );
}

function InvitationRow({
    invitation,
    workspaceSlug,
}: {
    invitation: WorkspaceInvitation;
    workspaceSlug: string;
}) {
    const canRevoke = invitation.status === 'pending';

    return (
        <li className="flex flex-wrap items-center gap-4 px-4 py-3">
            <div className="min-w-0 flex-1">
                <div className="flex items-center gap-2">
                    <span className="truncate font-medium">
                        {invitation.email}
                    </span>
                    <Badge variant={STATUS_VARIANT[invitation.status]}>
                        {invitation.status}
                    </Badge>
                </div>

                <p className="truncate text-sm text-muted-foreground">
                    {invitation.role_label}
                    {invitation.invited_by &&
                        ` · invited by ${invitation.invited_by.name}`}
                    {` · expires ${new Date(invitation.expires_at).toLocaleDateString()}`}
                </p>
            </div>

            {canRevoke && (
                <Button
                    variant="ghost"
                    size="sm"
                    onClick={() =>
                        router.delete(
                            destroy({
                                workspace: workspaceSlug,
                                invitation: invitation.id,
                            }),
                            { preserveScroll: true },
                        )
                    }
                >
                    Revoke
                </Button>
            )}
        </li>
    );
}
