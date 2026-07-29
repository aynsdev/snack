import { Head, Link, router } from '@inertiajs/react';
import { UserPlus } from 'lucide-react';
import { UserAvatar } from '@/components/snack/user-avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useCurrentUser, useWorkspace } from '@/hooks/use-workspace';
import { update as updateMember } from '@/routes/members';
import { index as workspaceInvitations } from '@/routes/workspace-invitations';
import type { WorkspaceMember, WorkspaceRole } from '@/types';

type Paginated<T> = {
    data: T[];
    meta: { current_page: number; last_page: number; total: number };
    links: { next: string | null; prev: string | null };
};

type Props = {
    members: Paginated<WorkspaceMember>;
    canManage: boolean;
};

const ASSIGNABLE_ROLES: WorkspaceRole[] = ['admin', 'member', 'guest'];

/**
 * The member directory, doubling as the admin roster.
 *
 * Role and status controls only render for admins, and only on rows they
 * outrank — but the server re-checks both through WorkspacePolicy::manageMember
 * on every request, so hiding a control is a convenience, never the guard.
 */
export default function WorkspaceMembers({ members, canManage }: Props) {
    const workspace = useWorkspace();
    const currentUser = useCurrentUser();

    return (
        <>
            <Head title={`Members · ${workspace.name}`} />

            <div className="snack-scroll min-w-0 flex-1 overflow-y-auto">
                <div className="mx-auto max-w-4xl px-6 py-8">
                    <header className="mb-6 flex flex-wrap items-end justify-between gap-4">
                        <div>
                            <h1 className="text-2xl font-bold tracking-tight">
                                Members
                            </h1>
                            <p className="mt-1 text-sm text-muted-foreground">
                                {members.meta.total} of {workspace.member_limit}{' '}
                                seats used
                            </p>
                        </div>

                        {canManage && (
                            <Button asChild>
                                <Link
                                    href={workspaceInvitations(workspace.slug)}
                                >
                                    <UserPlus aria-hidden="true" />
                                    Invite people
                                </Link>
                            </Button>
                        )}
                    </header>

                    <ul className="divide-y divide-border overflow-hidden rounded-xl border border-border">
                        {members.data.map((member) => (
                            <MemberRow
                                key={member.id}
                                member={member}
                                workspaceSlug={workspace.slug}
                                // The owner is immutable, and nobody edits
                                // themselves from the admin roster.
                                editable={
                                    canManage &&
                                    member.role !== 'owner' &&
                                    member.user.id !== currentUser?.id
                                }
                            />
                        ))}
                    </ul>

                    <Pagination links={members.links} meta={members.meta} />
                </div>
            </div>
        </>
    );
}

function MemberRow({
    member,
    workspaceSlug,
    editable,
}: {
    member: WorkspaceMember;
    workspaceSlug: string;
    editable: boolean;
}) {
    const isSuspended = member.status === 'suspended';

    const patch = (payload: Record<string, string>) => {
        router.patch(
            updateMember({ workspace: workspaceSlug, member: member.id }),
            payload,
            { preserveScroll: true },
        );
    };

    return (
        <li className="flex flex-wrap items-center gap-4 px-4 py-3">
            <UserAvatar user={member.user} size="lg" showPresence />

            <div className="min-w-0 flex-1">
                <div className="flex items-center gap-2">
                    <span className="truncate font-medium">
                        {member.user.name}
                    </span>

                    {isSuspended && (
                        <Badge variant="destructive">Suspended</Badge>
                    )}

                    {member.role === 'owner' && <Badge>Owner</Badge>}
                </div>

                <p className="truncate text-sm text-muted-foreground">
                    @{member.user.username}
                    {member.user.title && ` · ${member.user.title}`}
                    {member.email && ` · ${member.email}`}
                </p>
            </div>

            {editable ? (
                <div className="flex items-center gap-2">
                    <Select
                        value={member.role}
                        onValueChange={(role) => patch({ role })}
                    >
                        <SelectTrigger className="w-32" aria-label="Role">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {ASSIGNABLE_ROLES.map((role) => (
                                <SelectItem key={role} value={role}>
                                    {role.charAt(0).toUpperCase() +
                                        role.slice(1)}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <Button
                        variant={isSuspended ? 'outline' : 'ghost'}
                        size="sm"
                        onClick={() =>
                            patch({
                                status: isSuspended ? 'active' : 'suspended',
                            })
                        }
                    >
                        {isSuspended ? 'Reinstate' : 'Suspend'}
                    </Button>
                </div>
            ) : (
                <span className="text-sm text-muted-foreground">
                    {member.role_label}
                </span>
            )}
        </li>
    );
}

function Pagination({
    links,
    meta,
}: {
    links: Paginated<unknown>['links'];
    meta: Paginated<unknown>['meta'];
}) {
    if (meta.last_page <= 1) {
        return null;
    }

    return (
        <nav
            aria-label="Members pagination"
            className="mt-6 flex items-center justify-between"
        >
            <p className="text-sm text-muted-foreground">
                Page {meta.current_page} of {meta.last_page}
            </p>

            <div className="flex gap-2">
                <Button
                    asChild={links.prev !== null}
                    variant="outline"
                    size="sm"
                    disabled={links.prev === null}
                >
                    {links.prev ? (
                        <Link href={links.prev} preserveScroll>
                            Previous
                        </Link>
                    ) : (
                        <span>Previous</span>
                    )}
                </Button>

                <Button
                    asChild={links.next !== null}
                    variant="outline"
                    size="sm"
                    disabled={links.next === null}
                >
                    {links.next ? (
                        <Link href={links.next} preserveScroll>
                            Next
                        </Link>
                    ) : (
                        <span>Next</span>
                    )}
                </Button>
            </div>
        </nav>
    );
}
