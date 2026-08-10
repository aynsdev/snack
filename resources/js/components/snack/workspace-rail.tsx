import { Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useWorkspaceRail } from '@/hooks/use-workspace';
import { cn } from '@/lib/utils';
import {
    create as createWorkspace,
    show as showWorkspace,
} from '@/routes/workspaces';
import type { WorkspaceSummary } from '@/types';

/**
 * The leftmost column: workspace switcher and add-workspace control.
 *
 * Charcoal in both light and dark themes. When needed, it is a fixed anchor —
 * the one part of the UI that never changes as you move around — so it reads
 * better as furniture than as a themed surface. A single workspace needs no
 * switcher, so the entire column disappears in that common case.
 */
export function WorkspaceRail({
    currentWorkspaceSlug,
}: {
    currentWorkspaceSlug?: string;
}) {
    const workspaces = useWorkspaceRail();

    if (workspaces.length <= 1) {
        return null;
    }

    return (
        <nav
            aria-label="Workspaces"
            className="flex w-16 shrink-0 flex-col items-center gap-1 bg-rail py-3 text-rail-foreground"
        >
            <ul className="flex flex-col items-center gap-1.5">
                {workspaces.map((workspace) => (
                    <li key={workspace.id}>
                        <WorkspaceRailButton
                            workspace={workspace}
                            isActive={workspace.slug === currentWorkspaceSlug}
                        />
                    </li>
                ))}

                <li className="mt-1">
                    <Tooltip>
                        <TooltipTrigger asChild>
                            <Link
                                href={createWorkspace()}
                                className={cn(
                                    'flex size-11 items-center justify-center rounded-xl border border-dashed border-rail-muted/40 text-rail-muted',
                                    'transition-colors hover:border-rail-active hover:text-rail-active',
                                )}
                            >
                                <Plus aria-hidden="true" className="size-5" />
                                <span className="sr-only">Add a workspace</span>
                            </Link>
                        </TooltipTrigger>
                        <TooltipContent side="right">
                            Add a workspace
                        </TooltipContent>
                    </Tooltip>
                </li>
            </ul>
        </nav>
    );
}

function WorkspaceRailButton({
    workspace,
    isActive,
}: {
    workspace: WorkspaceSummary;
    isActive: boolean;
}) {
    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <Link
                    href={showWorkspace(workspace.slug)}
                    aria-label={workspace.name}
                    aria-current={isActive ? 'page' : undefined}
                    className="group relative flex items-center justify-center"
                >
                    {/* The active pill grows out of the edge, so the current
                        workspace is identifiable by position as well as ring. */}
                    <span
                        aria-hidden="true"
                        className={cn(
                            'absolute -left-3 w-1 rounded-r-full bg-rail-active transition-all',
                            isActive
                                ? 'h-7 opacity-100'
                                : 'h-2 opacity-0 group-hover:opacity-60',
                        )}
                    />

                    <span
                        className={cn(
                            'flex size-11 items-center justify-center overflow-hidden rounded-xl text-sm font-semibold transition-all',
                            isActive
                                ? 'bg-brand-subtle text-foreground ring-2 ring-rail-active'
                                : 'bg-rail-foreground/10 text-rail-foreground hover:rounded-lg hover:bg-rail-foreground/20',
                        )}
                    >
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

                    <span className="sr-only">{workspace.name}</span>
                </Link>
            </TooltipTrigger>
            <TooltipContent side="right">{workspace.name}</TooltipContent>
        </Tooltip>
    );
}
