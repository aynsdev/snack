import { Link } from '@inertiajs/react';
import { ProjectAvatar } from '@/components/snack/projects/project-avatar';
import { UserAvatar } from '@/components/snack/user-avatar';
import { cn } from '@/lib/utils';
import {
    backlog as backlogRoute,
    board as boardRoute,
} from '@/routes/projects';
import { edit as settingsRoute } from '@/routes/projects/settings';
import type { Project, ProjectMembership } from '@/types';

/**
 * The bar above every project view: which project, who is on it, and the three
 * ways to look at it.
 *
 * Backlog is hidden for a kanban project rather than disabled. There is nothing
 * to plan into without sprints — everything the project has is already on the
 * board — and its route redirects there, so a tab would be a link to the page
 * you are already on.
 */
export function ProjectHeader({
    project,
    workspaceSlug,
    members,
    current,
    children,
}: {
    project: Project;
    workspaceSlug: string;
    members: ProjectMembership[];
    current: 'board' | 'backlog' | 'settings';
    /** Controls belonging to the current view — the sprint header, filters. */
    children?: React.ReactNode;
}) {
    return (
        <header className="border-b border-border px-4 py-3">
            <div className="flex flex-wrap items-center gap-3">
                <ProjectAvatar project={project} size="lg" />

                <div className="min-w-0">
                    <h1 className="truncate text-lg font-bold tracking-tight">
                        {project.name}
                    </h1>
                    <p className="truncate text-xs text-muted-foreground">
                        {project.key}
                        {project.description !== null &&
                            ` · ${project.description}`}
                    </p>
                </div>

                {/*
                 * Who is on the project, which on a board is also the answer to
                 * "who can this be assigned to". Capped, because the point is a
                 * glance at the team rather than a roster — the settings screen
                 * is where the whole list lives.
                 */}
                {members.length > 0 && (
                    <ul className="ml-auto flex -space-x-1.5">
                        {members.slice(0, 6).map((membership) => (
                            <li key={membership.id}>
                                <UserAvatar
                                    user={membership.user}
                                    size="sm"
                                    className="ring-2 ring-background"
                                />
                            </li>
                        ))}

                        {members.length > 6 && (
                            <li className="flex size-6 items-center justify-center rounded-full bg-muted text-[0.625rem] font-semibold text-muted-foreground ring-2 ring-background">
                                +{members.length - 6}
                            </li>
                        )}
                    </ul>
                )}
            </div>

            <div className="mt-3 flex flex-wrap items-center gap-1">
                <Tab
                    href={boardRoute([workspaceSlug, project.slug]).url}
                    isActive={current === 'board'}
                >
                    Board
                </Tab>

                {project.uses_sprints && (
                    <Tab
                        href={backlogRoute([workspaceSlug, project.slug]).url}
                        isActive={current === 'backlog'}
                    >
                        Backlog
                    </Tab>
                )}

                <Tab
                    href={settingsRoute([workspaceSlug, project.slug]).url}
                    isActive={current === 'settings'}
                >
                    Settings
                </Tab>

                {children !== undefined && (
                    <div className="ml-auto flex flex-wrap items-center gap-2">
                        {children}
                    </div>
                )}
            </div>
        </header>
    );
}

function Tab({
    href,
    isActive,
    children,
}: {
    href: string;
    isActive: boolean;
    children: React.ReactNode;
}) {
    return (
        <Link
            href={href}
            aria-current={isActive ? 'page' : undefined}
            className={cn(
                'rounded-lg px-3 py-1.5 text-sm font-medium transition-colors',
                isActive
                    ? 'bg-accent text-foreground'
                    : 'text-muted-foreground hover:bg-accent/60 hover:text-foreground',
            )}
        >
            {children}
        </Link>
    );
}
