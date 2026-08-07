import { Link, router } from '@inertiajs/react';
import { Star } from 'lucide-react';
import { ProjectAvatar } from '@/components/snack/projects/project-avatar';
import { UserAvatar } from '@/components/snack/user-avatar';
import { cn } from '@/lib/utils';
import {
    backlog as backlogRoute,
    board as boardRoute,
} from '@/routes/projects';
import { summary as summaryRoute } from '@/routes/projects';
import { index as releasesRoute } from '@/routes/projects/releases';
import { edit as settingsRoute } from '@/routes/projects/settings';
import {
    destroy as unstarProject,
    store as starProject,
} from '@/routes/projects/star';
import type { Project, ProjectMembership } from '@/types';

/**
 * The bar above every project view: which project, who is on it, and the five
 * ways to look at it — the overview, then the order work moves through them.
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
    current: 'summary' | 'backlog' | 'board' | 'releases' | 'settings';
    /** Controls belonging to the current view — the sprint header, filters. */
    children?: React.ReactNode;
}) {
    return (
        <header className="border-b border-border px-4 py-3">
            <div className="flex flex-wrap items-center gap-3">
                <ProjectAvatar project={project} size="lg" />

                <div className="min-w-0">
                    <span className="flex items-center gap-1.5">
                        <h1 className="truncate text-lg font-bold tracking-tight">
                            {project.name}
                        </h1>

                        {project.is_member && (
                            <StarButton
                                project={project}
                                workspaceSlug={workspaceSlug}
                            />
                        )}
                    </span>
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
                {/*
                 * Summary, then backlog, board and releases: the overview
                 * first, and after it left to right is the order work moves —
                 * planned, worked, shipped. A kanban project has no backlog,
                 * which is the same rule with one step missing.
                 */}
                <Tab
                    href={summaryRoute([workspaceSlug, project.slug]).url}
                    isActive={current === 'summary'}
                >
                    Summary
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
                    href={boardRoute([workspaceSlug, project.slug]).url}
                    isActive={current === 'board'}
                >
                    Board
                </Tab>

                <Tab
                    href={releasesRoute([workspaceSlug, project.slug]).url}
                    isActive={current === 'releases'}
                >
                    Releases
                </Tab>

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

/**
 * Starring, from the one bar that is on every page of a project.
 *
 * Only for members: the star sits on your membership row, and somebody who is
 * not on the project has no row to write it to. It saves on click, like every
 * other one-decision control here — there is nothing to confirm about a
 * preference that changes only your own sidebar.
 */
function StarButton({
    project,
    workspaceSlug,
}: {
    project: Project;
    workspaceSlug: string;
}) {
    const args = { workspace: workspaceSlug, project: project.slug };

    return (
        <button
            type="button"
            aria-pressed={project.is_starred}
            title={
                project.is_starred ? 'Unstar this project' : 'Star this project'
            }
            onClick={() =>
                project.is_starred
                    ? router.delete(unstarProject.url(args), {
                          preserveScroll: true,
                      })
                    : router.post(
                          starProject.url(args),
                          {},
                          { preserveScroll: true },
                      )
            }
            className={cn(
                'rounded-md p-1 transition-colors',
                project.is_starred
                    ? 'text-amber-500 hover:text-amber-600'
                    : 'text-muted-foreground hover:text-foreground',
            )}
        >
            <Star
                aria-hidden="true"
                className={cn('size-4', project.is_starred && 'fill-current')}
            />
            <span className="sr-only">
                {project.is_starred ? 'Unstar' : 'Star'} {project.name}
            </span>
        </button>
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
