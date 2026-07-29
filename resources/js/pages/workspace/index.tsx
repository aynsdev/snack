import { Head, Link } from '@inertiajs/react';
import { ChevronRight, Plus } from 'lucide-react';
import { EmptyState } from '@/components/snack/empty-state';
import { SnackLogo } from '@/components/snack/snack-logo';
import { Button } from '@/components/ui/button';
import {
    create as createWorkspace,
    show as showWorkspace,
} from '@/routes/workspaces';
import type { WorkspaceSummary } from '@/types';

type Props = {
    workspaces: WorkspaceSummary[];
};

/**
 * The workspace picker.
 *
 * Only reached when someone belongs to zero or several workspaces — with
 * exactly one, the controller sends them straight in, because a chooser with a
 * single option is just a speed bump.
 */
export default function WorkspaceIndex({ workspaces }: Props) {
    return (
        <>
            <Head title="Your workspaces" />

            <div className="flex min-h-dvh flex-col items-center justify-center px-6 py-12">
                <SnackLogo className="mb-10" showTagline />

                <div className="w-full max-w-md">
                    {workspaces.length === 0 ? (
                        <EmptyState
                            icon={Plus}
                            title="You are not in a workspace yet"
                            description="Create one for your team, or ask a colleague to send you an invitation link."
                            action={
                                <Button asChild>
                                    <Link href={createWorkspace()}>
                                        Create a workspace
                                    </Link>
                                </Button>
                            }
                        />
                    ) : (
                        <>
                            <h1 className="mb-4 text-center text-xl font-bold tracking-tight">
                                Choose a workspace
                            </h1>

                            <ul className="divide-y divide-border overflow-hidden rounded-2xl border border-border">
                                {workspaces.map((workspace) => (
                                    <li key={workspace.id}>
                                        <Link
                                            href={showWorkspace(workspace.slug)}
                                            className="flex items-center gap-3 px-4 py-3.5 transition-colors hover:bg-accent"
                                        >
                                            <span className="flex size-10 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-brand-subtle text-sm font-semibold text-foreground">
                                                {workspace.logo_url ? (
                                                    <img
                                                        src={workspace.logo_url}
                                                        alt=""
                                                        className="size-full object-cover"
                                                    />
                                                ) : (
                                                    workspace.initials
                                                )}
                                            </span>

                                            <span className="min-w-0 flex-1">
                                                <span className="block truncate font-medium">
                                                    {workspace.name}
                                                </span>
                                                <span className="block truncate text-sm text-muted-foreground">
                                                    snack.test/w/
                                                    {workspace.slug}
                                                </span>
                                            </span>

                                            <ChevronRight
                                                aria-hidden="true"
                                                className="size-4 shrink-0 text-muted-foreground"
                                            />
                                        </Link>
                                    </li>
                                ))}
                            </ul>

                            <div className="mt-6 text-center">
                                <Button asChild variant="outline">
                                    <Link href={createWorkspace()}>
                                        <Plus aria-hidden="true" />
                                        Create another workspace
                                    </Link>
                                </Button>
                            </div>
                        </>
                    )}
                </div>
            </div>
        </>
    );
}
