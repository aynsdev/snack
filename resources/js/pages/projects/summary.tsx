import { Head, Link } from '@inertiajs/react';
import {
    CalendarClock,
    CircleCheck,
    FilePlus2,
    PencilLine,
} from 'lucide-react';
import { ProjectHeader } from '@/components/snack/projects/project-header';
import {
    BarRows,
    Panel,
    StackedBar,
    StatTile,
} from '@/components/snack/projects/summary-charts';
import { TaskTypeIcon } from '@/components/snack/projects/task-glyphs';
import { useWorkspace } from '@/hooks/use-workspace';
import { cn } from '@/lib/utils';
import { board as boardRoute } from '@/routes/projects';
import type {
    Project,
    ProjectMembership,
    SummaryActivity,
    SummaryCount,
    SummaryStatus,
    TaskType,
} from '@/types';

type Props = {
    project: Project;
    members: ProjectMembership[];
    recent: {
        completed: number;
        updated: number;
        created: number;
        due_soon: number;
    };
    statuses: SummaryStatus[];
    priorities: SummaryCount[];
    types: SummaryCount[];
    activity: SummaryActivity[];
    canManage: boolean;
};

/**
 * The project at a glance, and the first thing its tab bar offers.
 *
 * Answers the questions somebody asks before opening the board: is this moving,
 * where is the work piled up, what kind of work is it, and what changed while I
 * was away. Everything here is read-only and every figure is a count the server
 * did in one query — see Projects\ProjectSummaryController.
 */
export default function ProjectSummaryPage({
    project,
    members,
    recent,
    statuses,
    priorities,
    types,
    activity,
}: Props) {
    const workspace = useWorkspace();

    const total = statuses.reduce((sum, status) => sum + status.count, 0);

    return (
        <div className="flex min-h-0 w-full flex-col">
            <Head title={`${project.name} summary`} />

            <ProjectHeader
                project={project}
                workspaceSlug={workspace.slug}
                members={members}
                current="summary"
            />

            <div className="snack-scroll min-h-0 flex-1 overflow-y-auto">
                <div className="mx-auto max-w-5xl space-y-4 px-4 py-6">
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <StatTile
                            icon={CircleCheck}
                            label="completed"
                            value={recent.completed}
                            hint="in the last 7 days"
                        />
                        <StatTile
                            icon={PencilLine}
                            label="updated"
                            value={recent.updated}
                            hint="in the last 7 days"
                        />
                        <StatTile
                            icon={FilePlus2}
                            label="created"
                            value={recent.created}
                            hint="in the last 7 days"
                        />
                        <StatTile
                            icon={CalendarClock}
                            label="due soon"
                            value={recent.due_soon}
                            hint="unfinished, in the next 7 days"
                        />
                    </div>

                    <div className="grid gap-4 lg:grid-cols-2">
                        <Panel
                            title="Status overview"
                            description="Where this project's work is sitting, in board order."
                        >
                            {total === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    Nothing has been filed in this project yet.
                                </p>
                            ) : (
                                <>
                                    <p className="mb-3 text-3xl font-bold tabular-nums">
                                        {total}
                                        <span className="ml-2 text-sm font-medium text-muted-foreground">
                                            {total === 1 ? 'card' : 'cards'} in
                                            total
                                        </span>
                                    </p>

                                    <StackedBar
                                        total={total}
                                        segments={statuses.map((status) => ({
                                            key: status.id,
                                            label: status.name,
                                            count: status.count,
                                        }))}
                                    />

                                    <Link
                                        href={
                                            boardRoute([
                                                workspace.slug,
                                                project.slug,
                                            ]).url
                                        }
                                        className="mt-4 inline-block text-sm text-brand hover:underline"
                                    >
                                        Open the board
                                    </Link>
                                </>
                            )}
                        </Panel>

                        <Panel
                            title="Recent activity"
                            description="The cards touched most recently. Snack keeps no field-by-field history."
                        >
                            {activity.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    Nothing has moved yet.
                                </p>
                            ) : (
                                <ul className="divide-y divide-border">
                                    {activity.map((task) => (
                                        <ActivityRow
                                            key={task.id}
                                            task={task}
                                        />
                                    ))}
                                </ul>
                            )}
                        </Panel>

                        <Panel
                            title="Priority breakdown"
                            description="How the work has been prioritised, highest first."
                        >
                            <BarRows
                                total={total}
                                emptyLabel="Nothing to prioritise yet."
                                rows={priorities.map((priority) => ({
                                    key: priority.value,
                                    label: priority.label,
                                    count: priority.count,
                                }))}
                            />
                        </Panel>

                        <Panel
                            title="Types of work"
                            description="What this project is made of."
                        >
                            <BarRows
                                total={total}
                                emptyLabel="No work filed yet."
                                rows={types.map((type) => ({
                                    key: type.value,
                                    label: type.label,
                                    count: type.count,
                                }))}
                            />
                        </Panel>
                    </div>
                </div>
            </div>
        </div>
    );
}

/**
 * One line of the activity list: which card, where it is now, and when it last
 * changed. Deliberately not "who changed what" — that is not recorded, and
 * inferring it from a timestamp would be a story rather than a record.
 */
function ActivityRow({ task }: { task: SummaryActivity }) {
    return (
        <li className="flex items-center gap-2 py-2">
            <TaskTypeIcon type={task.type as TaskType} />

            <span className="w-20 shrink-0 font-mono text-xs text-muted-foreground">
                {task.reference}
            </span>

            <span
                className={cn(
                    'min-w-0 flex-1 truncate text-sm',
                    task.is_complete && 'text-muted-foreground line-through',
                )}
            >
                {task.title}
            </span>

            <span className="shrink-0 rounded bg-muted px-1.5 py-0.5 text-[0.625rem] font-medium text-muted-foreground">
                {task.status}
            </span>

            <span className="w-20 shrink-0 text-right text-xs text-muted-foreground">
                {sinceLabel(task.updated_at)}
            </span>
        </li>
    );
}

/**
 * "3h ago", to the coarsest unit that is still true.
 *
 * A relative time rather than a date: the question this list answers is "has
 * anything happened lately", and "14:32" only answers it if you already know
 * what day it is talking about.
 */
function sinceLabel(iso: string | null): string {
    if (iso === null) {
        return '';
    }

    const minutes = Math.max(
        0,
        Math.round((Date.now() - new Date(iso).getTime()) / 60000),
    );

    if (minutes < 1) {
        return 'just now';
    }

    if (minutes < 60) {
        return `${minutes}m ago`;
    }

    const hours = Math.round(minutes / 60);

    if (hours < 24) {
        return `${hours}h ago`;
    }

    return `${Math.round(hours / 24)}d ago`;
}
