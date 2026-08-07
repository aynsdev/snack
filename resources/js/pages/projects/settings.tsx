import { Head, router, useForm } from '@inertiajs/react';
import { Archive, Plus, Trash2, UserPlus } from 'lucide-react';
import { useState } from 'react';
import {
    PROJECT_COLOR_NAMES,
    PROJECT_COLORS,
} from '@/components/snack/projects/project-avatar';
import { ProjectHeader } from '@/components/snack/projects/project-header';
import {
    LABEL_COLOR_NAMES,
    LabelChip,
} from '@/components/snack/projects/task-glyphs';
import { UserAvatar } from '@/components/snack/user-avatar';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { useWorkspace } from '@/hooks/use-workspace';
import { cn } from '@/lib/utils';
import { update as updateProject } from '@/routes/projects';
import { store as archiveProject } from '@/routes/projects/archive';
import { update as assignGroup } from '@/routes/projects/group';
import {
    destroy as destroyLabel,
    store as storeLabel,
} from '@/routes/projects/labels';
import {
    destroy as removeMember,
    store as addMembers,
    update as updateMember,
} from '@/routes/projects/members';
import type {
    CardFields,
    Project,
    ProjectGroup,
    ProjectMembership,
    SnackUser,
    TaskLabel,
} from '@/types';

/**
 * The value the group picker uses for "no group".
 *
 * A named token rather than an empty string, which the select primitive treats
 * as no selection at all and refuses as an item value.
 */
const UNGROUPED = 'none';

type Props = {
    project: Project;
    /** Every group in the workspace, so the picker can name this one's. */
    groups: ProjectGroup[];
    members: ProjectMembership[];
    labels: TaskLabel[];
    canManage: boolean;
    /** Deferred: everyone in the workspace not already on the project. */
    addable?: SnackUser[];
};

/**
 * Everything about a project that is not the work itself.
 *
 * Three sections in the order a team actually reaches for them: what the project
 * is, what its cards show, its vocabulary, and who is on it. The board's own
 * shape is not here — a column is renamed, retyped and removed from the board
 * itself, where you can see what you are changing.
 *
 * Read-only for anyone who is not a lead. Rendering the page and refusing every
 * control is deliberate — "you cannot change this" is a more useful answer than
 * a 403 for somebody who came here to look up the project key.
 */
export default function ProjectSettingsPage({
    project,
    groups,
    members,
    labels,
    canManage,
    addable,
}: Props) {
    const workspace = useWorkspace();

    return (
        <div className="flex min-h-0 w-full flex-col">
            <Head title={`${project.name} settings`} />

            <ProjectHeader
                project={project}
                workspaceSlug={workspace.slug}
                members={members}
                current="settings"
            />

            <div className="snack-scroll min-h-0 flex-1 overflow-y-auto">
                <div className="mx-auto max-w-3xl space-y-8 px-4 py-6">
                    <DetailsSection
                        project={project}
                        groups={groups}
                        workspaceSlug={workspace.slug}
                        canManage={canManage}
                    />

                    <CardFieldsSection
                        project={project}
                        workspaceSlug={workspace.slug}
                        canManage={canManage}
                    />

                    <LabelsSection
                        project={project}
                        labels={labels}
                        workspaceSlug={workspace.slug}
                        canManage={canManage}
                    />

                    <MembersSection
                        project={project}
                        members={members}
                        addable={addable}
                        workspaceSlug={workspace.slug}
                        canManage={canManage}
                    />
                </div>
            </div>
        </div>
    );
}

function DetailsSection({
    project,
    groups,
    workspaceSlug,
    canManage,
}: {
    project: Project;
    groups: ProjectGroup[];
    workspaceSlug: string;
    canManage: boolean;
}) {
    const form = useForm({
        name: project.name,
        key: project.key,
        description: project.description ?? '',
        color: project.color,
        uses_sprints: project.uses_sprints,
        next_task_number: String(project.next_task_number),
    });

    return (
        <Section
            title="Project"
            description="Renaming regenerates the URL. The key stays put — it is inside every reference anyone has quoted."
        >
            <form
                className="space-y-4"
                onSubmit={(event) => {
                    event.preventDefault();

                    // The counter is typed, so it travels as text; the server
                    // wants the number. Blank becomes 0 rather than nothing, so
                    // it is refused by the same rule that refuses a zero.
                    form.transform((data) => ({
                        ...data,
                        next_task_number: Number(data.next_task_number),
                    }));

                    form.patch(
                        updateProject.url({
                            workspace: workspaceSlug,
                            project: project.slug,
                        }),
                    );
                }}
            >
                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="space-y-1.5">
                        <Label htmlFor="name">Name</Label>
                        <Input
                            id="name"
                            value={form.data.name}
                            disabled={!canManage}
                            onChange={(event) =>
                                form.setData('name', event.target.value)
                            }
                        />
                        {form.errors.name !== undefined && (
                            <p className="text-xs text-destructive">
                                {form.errors.name}
                            </p>
                        )}
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor="key">Key</Label>
                        <Input
                            id="key"
                            value={form.data.key}
                            disabled={!canManage}
                            maxLength={10}
                            className="uppercase"
                            onChange={(event) =>
                                form.setData(
                                    'key',
                                    event.target.value.toUpperCase(),
                                )
                            }
                        />
                        {form.errors.key !== undefined && (
                            <p className="text-xs text-destructive">
                                {form.errors.key}
                            </p>
                        )}
                    </div>
                </div>

                <div className="space-y-1.5">
                    <Label htmlFor="description">Description</Label>
                    <Input
                        id="description"
                        value={form.data.description}
                        disabled={!canManage}
                        onChange={(event) =>
                            form.setData('description', event.target.value)
                        }
                    />
                </div>

                {/*
                 * Where numbering carries on from.
                 *
                 * Editable because a team arriving from another tracker has
                 * references people already quoted — starting again at 1 would
                 * make PWA-1499 mean two different things. It only ever moves
                 * forward: the server refuses a number already issued, because
                 * a counter behind the highest reference fails much later, on
                 * somebody's ordinary click.
                 */}
                <div className="max-w-xs space-y-1.5">
                    <Label htmlFor="next-task-number">Next ticket number</Label>

                    <div className="flex items-center gap-2">
                        <span className="text-sm text-muted-foreground">
                            {project.key}-
                        </span>

                        <Input
                            id="next-task-number"
                            type="number"
                            min={1}
                            inputMode="numeric"
                            value={form.data.next_task_number}
                            disabled={!canManage}
                            onChange={(event) =>
                                form.setData(
                                    'next_task_number',
                                    event.target.value,
                                )
                            }
                        />
                    </div>

                    <p className="text-xs text-muted-foreground">
                        The reference the next task filed here will carry. It
                        can only move forward — numbers already issued stay
                        meaning what they meant.
                    </p>

                    {form.errors.next_task_number !== undefined && (
                        <p className="text-xs text-destructive">
                            {form.errors.next_task_number}
                        </p>
                    )}
                </div>

                <div className="space-y-1.5">
                    <Label>Colour</Label>
                    <div className="flex flex-wrap gap-2">
                        {PROJECT_COLOR_NAMES.map((color) => (
                            <button
                                key={color}
                                type="button"
                                aria-label={color}
                                aria-pressed={form.data.color === color}
                                disabled={!canManage}
                                onClick={() => form.setData('color', color)}
                                className={cn(
                                    'size-6 rounded-md transition-transform',
                                    PROJECT_COLORS[color],
                                    form.data.color === color &&
                                        'ring-2 ring-foreground ring-offset-2 ring-offset-background',
                                )}
                            />
                        ))}
                    </div>
                </div>

                <label className="flex items-start gap-2.5">
                    <Checkbox
                        checked={form.data.uses_sprints}
                        disabled={!canManage}
                        onCheckedChange={(checked) =>
                            form.setData('uses_sprints', checked === true)
                        }
                    />
                    <span>
                        <span className="text-sm font-medium">
                            Plan in sprints
                        </span>
                        <span className="block text-xs text-muted-foreground">
                            Turning this off hides the backlog and puts every
                            task on the board. Nothing is lost either way.
                        </span>
                    </span>
                </label>

                {canManage && (
                    <div className="flex items-center gap-2">
                        <Button type="submit" disabled={form.processing}>
                            Save
                        </Button>

                        {!project.is_archived && (
                            <Button
                                type="button"
                                variant="ghost"
                                className="ml-auto text-muted-foreground"
                                onClick={() =>
                                    router.post(
                                        archiveProject.url({
                                            workspace: workspaceSlug,
                                            project: project.slug,
                                        }),
                                    )
                                }
                            >
                                <Archive className="size-4" />
                                Archive project
                            </Button>
                        )}
                    </div>
                )}
            </form>

            {groups.length > 0 && (
                /*
                 * Outside the form above, and saved the moment it changes.
                 *
                 * Which heading a project sits under is not part of what the
                 * project *is* — it is where it is filed, it has its own
                 * endpoint, and it changes nothing on this page. Putting it
                 * behind the same Save button would mean a rename and a move
                 * travelling as one request to two different places.
                 */
                <div className="mt-6 max-w-xs space-y-1.5 border-t border-border pt-6">
                    <Label htmlFor="project-group">Group</Label>

                    <Select
                        value={
                            project.project_group_id === null
                                ? UNGROUPED
                                : String(project.project_group_id)
                        }
                        disabled={!canManage}
                        onValueChange={(value) =>
                            router.patch(
                                assignGroup.url({
                                    workspace: workspaceSlug,
                                    project: project.slug,
                                }),
                                {
                                    project_group_id:
                                        value === UNGROUPED
                                            ? null
                                            : Number(value),
                                },
                                { preserveScroll: true },
                            )
                        }
                    >
                        <SelectTrigger id="project-group">
                            <SelectValue />
                        </SelectTrigger>

                        <SelectContent>
                            {groups.map((group) => (
                                <SelectItem
                                    key={group.id}
                                    value={String(group.id)}
                                >
                                    {group.name}
                                </SelectItem>
                            ))}

                            <SelectItem value={UNGROUPED}>Ungrouped</SelectItem>
                        </SelectContent>
                    </Select>

                    <p className="text-xs text-muted-foreground">
                        Where this project sits on the project list. Saved as
                        soon as you pick.
                    </p>
                </div>
            )}
        </Section>
    );
}

/**
 * Which fields the cards on this board draw.
 *
 * Saved on toggle rather than behind a Save button: each one is a single,
 * independent decision whose result is visible on the next screen, and there is
 * nothing here that only makes sense committed together.
 */
function CardFieldsSection({
    project,
    workspaceSlug,
    canManage,
}: {
    project: Project;
    workspaceSlug: string;
    canManage: boolean;
}) {
    const [fields, setFields] = useState<CardFields>(project.card_fields);

    const toggle = (field: keyof CardFields, value: boolean) => {
        const next = { ...fields, [field]: value };

        setFields(next);

        router.patch(
            updateProject.url({
                workspace: workspaceSlug,
                project: project.slug,
            }),
            { card_fields: next },
            { preserveScroll: true },
        );
    };

    return (
        <Section
            title="Cards"
            description="What a card shows on the board. The title is always there."
        >
            <div className="grid gap-2 sm:grid-cols-2">
                {CARD_FIELD_LABELS.map(([field, label, hint]) => (
                    <label
                        key={field}
                        className="flex items-start gap-2.5 rounded-lg border border-border p-2.5"
                    >
                        <Checkbox
                            checked={fields[field]}
                            disabled={!canManage}
                            onCheckedChange={(checked) =>
                                toggle(field, checked === true)
                            }
                        />
                        <span className="min-w-0">
                            <span className="text-sm font-medium">{label}</span>
                            <span className="block text-xs text-muted-foreground">
                                {hint}
                            </span>
                        </span>
                    </label>
                ))}
            </div>
        </Section>
    );
}

const CARD_FIELD_LABELS: Array<[keyof CardFields, string, string]> = [
    ['type', 'Type', 'Story, task, bug, or epic.'],
    ['key', 'Reference', 'PWA-1499.'],
    ['priority', 'Priority', 'The arrow.'],
    ['assignee', 'Assignee', 'Their avatar, or an empty ring.'],
    ['labels', 'Labels', 'The words your team invented.'],
    ['story_points', 'Story points', 'Only useful if you estimate.'],
    ['due_on', 'Due date', 'Turns red when it passes.'],
    ['epic', 'Epic', 'Which larger piece this belongs to.'],
];

function LabelsSection({
    project,
    labels,
    workspaceSlug,
    canManage,
}: {
    project: Project;
    labels: TaskLabel[];
    workspaceSlug: string;
    canManage: boolean;
}) {
    const [name, setName] = useState('');
    const [color, setColor] = useState('slate');

    const projectArgs = { workspace: workspaceSlug, project: project.slug };

    return (
        <Section
            title="Labels"
            description="Your own vocabulary — “needs design”, “customer-reported”. Removing one takes it off every card that wore it."
        >
            {labels.length === 0 ? (
                <p className="text-sm text-muted-foreground">No labels yet.</p>
            ) : (
                <ul className="flex flex-wrap gap-2">
                    {labels.map((label) => (
                        <li
                            key={label.id}
                            className="flex items-center gap-1 rounded-lg border border-border px-2 py-1"
                        >
                            <LabelChip name={label.name} color={label.color} />

                            {canManage && (
                                <button
                                    type="button"
                                    onClick={() =>
                                        router.delete(
                                            destroyLabel.url({
                                                ...projectArgs,
                                                label: label.id,
                                            }),
                                            { preserveScroll: true },
                                        )
                                    }
                                    className="text-muted-foreground transition-colors hover:text-destructive"
                                >
                                    <Trash2
                                        aria-hidden="true"
                                        className="size-3.5"
                                    />
                                    <span className="sr-only">
                                        Remove {label.name}
                                    </span>
                                </button>
                            )}
                        </li>
                    ))}
                </ul>
            )}

            {canManage && (
                <form
                    className="mt-3 flex flex-wrap items-center gap-2"
                    onSubmit={(event) => {
                        event.preventDefault();

                        if (name.trim() === '') {
                            return;
                        }

                        router.post(
                            storeLabel.url(projectArgs),
                            { name: name.trim(), color },
                            {
                                preserveScroll: true,
                                onSuccess: () => setName(''),
                            },
                        );
                    }}
                >
                    <Input
                        value={name}
                        onChange={(event) => setName(event.target.value)}
                        placeholder="needs design"
                        className="w-48"
                    />

                    <div className="flex flex-wrap gap-1.5">
                        {LABEL_COLOR_NAMES.map((candidate) => (
                            <button
                                key={candidate}
                                type="button"
                                aria-label={candidate}
                                aria-pressed={color === candidate}
                                onClick={() => setColor(candidate)}
                                className={cn(
                                    'rounded',
                                    color === candidate &&
                                        'ring-2 ring-foreground ring-offset-1 ring-offset-background',
                                )}
                            >
                                <LabelChip name="Aa" color={candidate} />
                            </button>
                        ))}
                    </div>

                    <Button type="submit" variant="outline">
                        <Plus className="size-4" />
                        Add label
                    </Button>
                </form>
            )}
        </Section>
    );
}

/**
 * Who is on the project — and therefore who can be assigned its work.
 *
 * The `addable` list arrives deferred, so it is only fetched for the person who
 * actually opened the picker.
 */
function MembersSection({
    project,
    members,
    addable,
    workspaceSlug,
    canManage,
}: {
    project: Project;
    members: ProjectMembership[];
    addable?: SnackUser[];
    workspaceSlug: string;
    canManage: boolean;
}) {
    const [adding, setAdding] = useState(false);
    const projectArgs = { workspace: workspaceSlug, project: project.slug };

    return (
        <Section
            title="People"
            description="Only these people can be assigned work on this board. Removing somebody hands their unfinished tasks back."
        >
            <ul className="space-y-2">
                {members.map((membership) => (
                    <li
                        key={membership.id}
                        className="flex flex-wrap items-center gap-2.5 rounded-lg border border-border p-2.5"
                    >
                        <UserAvatar user={membership.user} size="sm" />

                        <div className="min-w-0">
                            <p className="truncate text-sm font-medium">
                                {membership.user.name}
                            </p>
                            <p className="truncate text-xs text-muted-foreground">
                                @{membership.user.username}
                            </p>
                        </div>

                        <div className="ml-auto flex items-center gap-2">
                            <Select
                                value={membership.role}
                                disabled={!canManage}
                                onValueChange={(value) =>
                                    router.patch(
                                        updateMember.url({
                                            ...projectArgs,
                                            member: membership.id,
                                        }),
                                        { role: value },
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                <SelectTrigger className="w-36">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="lead">
                                        Project lead
                                    </SelectItem>
                                    <SelectItem value="member">
                                        Member
                                    </SelectItem>
                                </SelectContent>
                            </Select>

                            {canManage && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    className="text-muted-foreground"
                                    onClick={() =>
                                        router.delete(
                                            removeMember.url({
                                                ...projectArgs,
                                                member: membership.id,
                                            }),
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    <Trash2 className="size-4" />
                                    <span className="sr-only">
                                        Remove {membership.user.name}
                                    </span>
                                </Button>
                            )}
                        </div>
                    </li>
                ))}
            </ul>

            {canManage &&
                (adding ? (
                    addable === undefined ? (
                        <div className="mt-3 space-y-2">
                            <Skeleton className="h-10 w-full animate-pulse" />
                            <Skeleton className="h-10 w-full animate-pulse" />
                        </div>
                    ) : addable.length === 0 ? (
                        <p className="mt-3 text-sm text-muted-foreground">
                            Everyone in this workspace is already on the
                            project.
                        </p>
                    ) : (
                        <ul className="mt-3 space-y-1">
                            {addable.map((user) => (
                                <li key={user.id}>
                                    <button
                                        type="button"
                                        onClick={() =>
                                            router.post(
                                                addMembers.url(projectArgs),
                                                { user_ids: [user.id] },
                                                { preserveScroll: true },
                                            )
                                        }
                                        className="flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2 text-left transition-colors hover:bg-accent"
                                    >
                                        <UserAvatar user={user} size="sm" />
                                        <span className="text-sm">
                                            {user.name}
                                        </span>
                                        <Plus className="ml-auto size-4 text-muted-foreground" />
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )
                ) : (
                    <Button
                        type="button"
                        variant="outline"
                        className="mt-3"
                        onClick={() => setAdding(true)}
                    >
                        <UserPlus className="size-4" />
                        Add people
                    </Button>
                ))}
        </Section>
    );
}

function Section({
    title,
    description,
    children,
}: {
    title: string;
    description: string;
    children: React.ReactNode;
}) {
    return (
        <section>
            <h2 className="text-base font-semibold">{title}</h2>
            <p className="mt-0.5 mb-3 text-sm text-muted-foreground">
                {description}
            </p>
            {children}
        </section>
    );
}
