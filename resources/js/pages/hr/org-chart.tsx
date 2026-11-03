import { Link } from '@inertiajs/react';
import {
    ChevronDown,
    ChevronRight,
    ChevronsDownUp,
    ChevronsUpDown,
    Crosshair,
    List,
    Minus,
    Network,
    Plus,
    Search,
    X,
} from 'lucide-react';
import type { PointerEvent as ReactPointerEvent, ReactNode } from 'react';
import { useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import {
    EmptyNote,
    HrPage,
    PersonAvatar,
    SelectField,
    StatusPill,
} from '@/components/hr/hr-ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useWorkspace } from '@/hooks/use-workspace';
import { cn } from '@/lib/utils';
import { show as showEmployee } from '@/routes/hr/employees';

type Person = {
    id: number;
    manager_id: number | null;
    name: string;
    initials: string;
    avatar_url: string | null;
    job_title: string | null;
    department: string | null;
    status: string;
    status_label: string;
};

type Props = {
    people: Person[];
    own_employee_id: number | null;
};

type View = 'chart' | 'list';

type Tree = {
    byId: Map<number, Person>;
    reports: Map<number, Person[]>;
    roots: Person[];
    /** Everybody under a person, however far down. */
    teamSize: Map<number, number>;
    depth: Map<number, number>;
    levels: number;
    departments: string[];
};

/** Accent per department, picked by name so it stays put between visits. */
const DEPARTMENT_TONES = [
    { bar: 'bg-sky-500', chip: 'bg-sky-500/15 text-sky-700 dark:text-sky-300' },
    {
        bar: 'bg-violet-500',
        chip: 'bg-violet-500/15 text-violet-700 dark:text-violet-300',
    },
    {
        bar: 'bg-emerald-500',
        chip: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
    },
    {
        bar: 'bg-amber-500',
        chip: 'bg-amber-500/15 text-amber-700 dark:text-amber-300',
    },
    {
        bar: 'bg-teal-500',
        chip: 'bg-teal-500/15 text-teal-700 dark:text-teal-300',
    },
    {
        bar: 'bg-indigo-500',
        chip: 'bg-indigo-500/15 text-indigo-700 dark:text-indigo-300',
    },
    {
        bar: 'bg-fuchsia-500',
        chip: 'bg-fuchsia-500/15 text-fuchsia-700 dark:text-fuchsia-300',
    },
    {
        bar: 'bg-orange-500',
        chip: 'bg-orange-500/15 text-orange-700 dark:text-orange-300',
    },
];

function departmentTone(department: string | null) {
    if (!department) {
        return null;
    }

    let hash = 0;

    for (const character of department.toLowerCase()) {
        hash = (hash * 31 + character.charCodeAt(0)) >>> 0;
    }

    return DEPARTMENT_TONES[hash % DEPARTMENT_TONES.length];
}

const ZOOM_STEPS = [0.5, 0.65, 0.8, 1, 1.15, 1.3];

/** Teams this big start folded below the second level. */
const FOLD_ABOVE = 30;

/**
 * Builds the reporting tree from each person's manager.
 *
 * Anybody whose manager is not (or no longer) employed here starts a tree of
 * their own. A manager chain that loops back on itself is cut where it would
 * repeat, so a data-entry mistake cannot hang the page.
 */
function buildTree(people: Person[]): Tree {
    const byId = new Map(people.map((person) => [person.id, person]));
    const reports = new Map<number, Person[]>();

    for (const person of people) {
        if (person.manager_id !== null && byId.has(person.manager_id)) {
            reports.set(person.manager_id, [
                ...(reports.get(person.manager_id) ?? []),
                person,
            ]);
        }
    }

    // Managers before individual contributors, so teams read left to right.
    for (const [id, team] of reports) {
        reports.set(
            id,
            [...team].sort(
                (a, b) =>
                    Number(reports.has(b.id)) - Number(reports.has(a.id)) ||
                    a.name.localeCompare(b.name),
            ),
        );
    }

    const roots = people.filter(
        (person) => person.manager_id === null || !byId.has(person.manager_id),
    );

    const depth = new Map<number, number>();
    const teamSize = new Map<number, number>();

    const walk = (person: Person, level: number): number => {
        if (depth.has(person.id)) {
            return 0;
        }

        depth.set(person.id, level);

        let size = 0;

        for (const report of reports.get(person.id) ?? []) {
            size += 1 + walk(report, level + 1);
        }

        teamSize.set(person.id, size);

        return size;
    };

    roots.sort(
        (a, b) =>
            (reports.get(b.id)?.length ?? 0) -
                (reports.get(a.id)?.length ?? 0) ||
            a.name.localeCompare(b.name),
    );
    roots.forEach((root) => walk(root, 0));

    // People in a loop of managers are reachable from no root; start a tree
    // at the first of each loop so they still appear.
    for (const person of people) {
        if (!depth.has(person.id)) {
            roots.push(person);
            walk(person, 0);
        }
    }

    return {
        byId,
        reports,
        roots,
        teamSize,
        depth,
        levels: people.length === 0 ? 0 : Math.max(...depth.values()) + 1,
        departments: [
            ...new Set(
                people
                    .map((person) => person.department)
                    .filter((department): department is string =>
                        Boolean(department),
                    ),
            ),
        ].sort((a, b) => a.localeCompare(b)),
    };
}

/**
 * Who reports to whom: a top-down chart to pan and zoom around, or an
 * indented list for narrow screens. Teams fold away, and searching or picking
 * a department highlights the people it matches and opens the way to them.
 */
export default function OrgChartPage({ people, own_employee_id }: Props) {
    const tree = useMemo(() => buildTree(people), [people]);
    const { byId, reports, roots, teamSize, depth } = tree;

    const [view, setView] = useState<View>(() =>
        typeof window !== 'undefined' &&
        window.matchMedia('(max-width: 639px)').matches
            ? 'list'
            : 'chart',
    );
    const [focus, setFocus] = useState('');
    const [query, setQuery] = useState('');
    const [department, setDepartment] = useState('');
    const [collapsed, setCollapsed] = useState<Set<number>>(
        () =>
            new Set(
                people.length > FOLD_ABOVE
                    ? people
                          .filter(
                              (person) =>
                                  (depth.get(person.id) ?? 0) >= 1 &&
                                  reports.has(person.id),
                          )
                          .map((person) => person.id)
                    : [],
            ),
    );

    const managers = people.filter((person) => reports.has(person.id));
    const focused = focus === '' ? null : (byId.get(Number(focus)) ?? null);
    const shown = focused ? [focused] : roots;

    // The chain of managers above the focused person, top first.
    const chain = useMemo(() => {
        const above: Person[] = [];
        const seen = new Set<number>();
        let current = focused?.manager_id ?? null;

        while (current !== null && byId.has(current) && !seen.has(current)) {
            seen.add(current);
            above.unshift(byId.get(current)!);
            current = byId.get(current)!.manager_id;
        }

        return above;
    }, [focused, byId]);

    const term = query.trim().toLowerCase();
    const filtering = term !== '' || department !== '';

    const matches = useMemo(() => {
        if (!filtering) {
            return new Set<number>();
        }

        return new Set(
            people
                .filter(
                    (person) =>
                        (department === '' ||
                            person.department === department) &&
                        (term === '' ||
                            [person.name, person.job_title, person.department]
                                .filter(Boolean)
                                .some((value) =>
                                    value!.toLowerCase().includes(term),
                                )),
                )
                .map((person) => person.id),
        );
    }, [people, term, department, filtering]);

    // Every manager above a match is held open so the match can be seen.
    const forcedOpen = useMemo(() => {
        const open = new Set<number>();

        for (const id of matches) {
            const seen = new Set<number>();
            let current = byId.get(id)?.manager_id ?? null;

            while (
                current !== null &&
                byId.has(current) &&
                !seen.has(current)
            ) {
                seen.add(current);
                open.add(current);
                current = byId.get(current)!.manager_id;
            }
        }

        return open;
    }, [matches, byId]);

    const node: NodeProps = {
        reports,
        teamSize,
        ownId: own_employee_id,
        isOpen: (id) => !collapsed.has(id) || forcedOpen.has(id),
        toggle: (id) =>
            setCollapsed((current) => {
                const next = new Set(current);

                if (next.has(id)) {
                    next.delete(id);
                } else {
                    next.add(id);
                }

                return next;
            }),
        highlight: (id) => (filtering ? matches.has(id) : null),
    };

    return (
        <HrPage
            title="Org chart"
            description="Who reports to whom. HR sets each person's manager on their file."
            width="max-w-7xl"
        >
            {people.length === 0 ? (
                <EmptyNote>Nobody is employed here yet.</EmptyNote>
            ) : (
                <>
                    <dl className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                        <Stat label="People" value={people.length} />
                        <Stat label="Managers" value={managers.length} />
                        <Stat
                            label="Departments"
                            value={tree.departments.length}
                        />
                        <Stat label="Levels" value={tree.levels} />
                    </dl>

                    <section className="rounded-2xl border border-border/70 bg-card shadow-xs">
                        <div className="flex flex-wrap items-end gap-3 border-b border-border/70 p-4">
                            <div className="relative min-w-48 flex-1 sm:max-w-xs">
                                <label htmlFor="org-search" className="sr-only">
                                    Search people
                                </label>
                                <Search
                                    aria-hidden="true"
                                    className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                                />
                                <Input
                                    id="org-search"
                                    type="search"
                                    value={query}
                                    onChange={(event) =>
                                        setQuery(event.target.value)
                                    }
                                    placeholder="Search name, title, or team"
                                    className="pl-9"
                                />
                            </div>
                            {tree.departments.length > 0 && (
                                <SelectField
                                    id="org-department"
                                    label="Department"
                                    value={department}
                                    onChange={setDepartment}
                                    options={tree.departments.map((name) => ({
                                        value: name,
                                        label: name,
                                    }))}
                                    allowEmpty
                                    emptyLabel="All departments"
                                    className="w-48"
                                />
                            )}
                            {managers.length > 0 && (
                                <SelectField
                                    id="org-focus"
                                    label="Show the team under"
                                    value={focus}
                                    onChange={setFocus}
                                    options={managers.map((manager) => ({
                                        value: String(manager.id),
                                        label: manager.name,
                                    }))}
                                    allowEmpty
                                    emptyLabel="Everyone"
                                    className="w-48"
                                />
                            )}

                            <div className="ml-auto flex items-center gap-2">
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    aria-label="Expand every team"
                                    title="Expand every team"
                                    onClick={() => setCollapsed(new Set())}
                                >
                                    <ChevronsUpDown aria-hidden="true" />
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    aria-label="Collapse every team"
                                    title="Collapse every team"
                                    onClick={() =>
                                        setCollapsed(
                                            new Set(
                                                managers.map(
                                                    (manager) => manager.id,
                                                ),
                                            ),
                                        )
                                    }
                                >
                                    <ChevronsDownUp aria-hidden="true" />
                                </Button>
                                <div
                                    className="flex rounded-lg border border-border p-0.5"
                                    role="group"
                                    aria-label="Layout"
                                >
                                    <Button
                                        size="sm"
                                        variant={
                                            view === 'chart'
                                                ? 'secondary'
                                                : 'ghost'
                                        }
                                        aria-pressed={view === 'chart'}
                                        onClick={() => setView('chart')}
                                    >
                                        <Network aria-hidden="true" />
                                        Chart
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant={
                                            view === 'list'
                                                ? 'secondary'
                                                : 'ghost'
                                        }
                                        aria-pressed={view === 'list'}
                                        onClick={() => setView('list')}
                                    >
                                        <List aria-hidden="true" />
                                        List
                                    </Button>
                                </div>
                            </div>
                        </div>

                        {(focused || filtering) && (
                            <div className="flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-border/70 px-4 py-2.5 text-sm">
                                {focused && (
                                    <nav
                                        aria-label="Reporting line"
                                        className="flex min-w-0 flex-wrap items-center gap-1 text-muted-foreground"
                                    >
                                        {chain.map((manager) => (
                                            <span
                                                key={manager.id}
                                                className="flex items-center gap-1"
                                            >
                                                <button
                                                    type="button"
                                                    className="rounded hover:text-foreground hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                                    onClick={() =>
                                                        setFocus(
                                                            reports.has(
                                                                manager.id,
                                                            )
                                                                ? String(
                                                                      manager.id,
                                                                  )
                                                                : '',
                                                        )
                                                    }
                                                >
                                                    {manager.name}
                                                </button>
                                                <ChevronRight
                                                    aria-hidden="true"
                                                    className="size-3.5"
                                                />
                                            </span>
                                        ))}
                                        <span className="font-medium text-foreground">
                                            {focused.name}'s team
                                        </span>
                                    </nav>
                                )}
                                {filtering && (
                                    <span
                                        className="text-muted-foreground"
                                        aria-live="polite"
                                    >
                                        {matches.size === 0
                                            ? 'Nobody matches.'
                                            : `${matches.size} ${matches.size === 1 ? 'match' : 'matches'} highlighted`}
                                    </span>
                                )}
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    className="ml-auto"
                                    onClick={() => {
                                        setFocus('');
                                        setQuery('');
                                        setDepartment('');
                                    }}
                                >
                                    <X aria-hidden="true" />
                                    Clear
                                </Button>
                            </div>
                        )}

                        {view === 'chart' ? (
                            <ChartCanvas resetKey={`${focus}|${roots.length}`}>
                                <ul className="flex items-start justify-center gap-10">
                                    {shown.map((person) => (
                                        <ChartNode
                                            key={person.id}
                                            person={person}
                                            seen={new Set()}
                                            {...node}
                                        />
                                    ))}
                                </ul>
                            </ChartCanvas>
                        ) : (
                            <ul className="space-y-2 overflow-x-auto p-4">
                                {shown.map((person) => (
                                    <ListNode
                                        key={person.id}
                                        person={person}
                                        seen={new Set()}
                                        {...node}
                                    />
                                ))}
                            </ul>
                        )}
                    </section>
                </>
            )}
        </HrPage>
    );
}

function Stat({ label, value }: { label: string; value: number }) {
    return (
        <div className="rounded-xl border border-border/70 bg-card px-4 py-3 shadow-xs">
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="mt-1 text-xl font-semibold tabular-nums">{value}</dd>
        </div>
    );
}

/**
 * The scrollable stage the chart sits on: drag the background to pan, and
 * zoom with the buttons or ctrl/⌘ and the scroll wheel.
 */
function ChartCanvas({
    resetKey,
    children,
}: {
    resetKey: string;
    children: ReactNode;
}) {
    const stage = useRef<HTMLDivElement>(null);
    const drag = useRef<{ x: number; y: number; left: number; top: number }>(
        null,
    );
    const [zoom, setZoom] = useState(1);
    const [dragging, setDragging] = useState(false);

    function step(direction: 1 | -1) {
        setZoom((current) => {
            const index = ZOOM_STEPS.findIndex((value) => value >= current);
            const next = Math.min(
                Math.max((index === -1 ? 3 : index) + direction, 0),
                ZOOM_STEPS.length - 1,
            );

            return ZOOM_STEPS[next];
        });
    }

    function center() {
        const element = stage.current;

        if (element) {
            element.scrollTo({
                left: (element.scrollWidth - element.clientWidth) / 2,
                top: 0,
            });
        }
    }

    // Start centred on the top of the tree, and again whenever it changes.
    useLayoutEffect(center, [resetKey, zoom]);

    // React's wheel handler is passive, so ctrl-zoom needs its own listener.
    useEffect(() => {
        const element = stage.current;

        if (!element) {
            return;
        }

        const onWheel = (event: WheelEvent) => {
            if (!event.ctrlKey && !event.metaKey) {
                return;
            }

            event.preventDefault();
            step(event.deltaY > 0 ? -1 : 1);
        };

        element.addEventListener('wheel', onWheel, { passive: false });

        return () => element.removeEventListener('wheel', onWheel);
    }, []);

    function start(event: ReactPointerEvent<HTMLDivElement>) {
        const element = stage.current;

        if (
            !element ||
            event.button !== 0 ||
            event.pointerType === 'touch' ||
            (event.target as HTMLElement).closest('a, button')
        ) {
            return;
        }

        drag.current = {
            x: event.clientX,
            y: event.clientY,
            left: element.scrollLeft,
            top: element.scrollTop,
        };
        element.setPointerCapture(event.pointerId);
        setDragging(true);
    }

    function move(event: ReactPointerEvent<HTMLDivElement>) {
        const element = stage.current;

        if (!element || !drag.current) {
            return;
        }

        element.scrollLeft =
            drag.current.left - (event.clientX - drag.current.x);
        element.scrollTop = drag.current.top - (event.clientY - drag.current.y);
    }

    function end() {
        drag.current = null;
        setDragging(false);
    }

    return (
        <div className="relative">
            <div
                ref={stage}
                role="region"
                aria-label="Org chart. Drag to pan; hold Ctrl or ⌘ and scroll to zoom."
                tabIndex={0}
                onPointerDown={start}
                onPointerMove={move}
                onPointerUp={end}
                onPointerCancel={end}
                className={cn(
                    'snack-scroll h-[68vh] min-h-96 overflow-auto rounded-b-2xl bg-[radial-gradient(circle,var(--color-border)_1px,transparent_1px)] [background-size:20px_20px] focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none focus-visible:ring-inset',
                    dragging ? 'cursor-grabbing select-none' : 'cursor-grab',
                )}
            >
                <div className="inline-block min-w-full p-8" style={{ zoom }}>
                    {children}
                </div>
            </div>

            <div className="absolute right-3 bottom-3 flex items-center gap-1 rounded-lg border border-border bg-card/95 p-1 shadow-sm backdrop-blur">
                <Button
                    variant="ghost"
                    size="icon"
                    className="size-8"
                    aria-label="Zoom out"
                    disabled={zoom <= ZOOM_STEPS[0]}
                    onClick={() => step(-1)}
                >
                    <Minus aria-hidden="true" />
                </Button>
                <button
                    type="button"
                    className="w-12 rounded text-center text-xs font-medium tabular-nums hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    aria-label="Reset zoom"
                    title="Reset zoom"
                    onClick={() => setZoom(1)}
                >
                    {Math.round(zoom * 100)}%
                </button>
                <Button
                    variant="ghost"
                    size="icon"
                    className="size-8"
                    aria-label="Zoom in"
                    disabled={zoom >= ZOOM_STEPS[ZOOM_STEPS.length - 1]}
                    onClick={() => step(1)}
                >
                    <Plus aria-hidden="true" />
                </Button>
                <Button
                    variant="ghost"
                    size="icon"
                    className="size-8"
                    aria-label="Back to the top"
                    title="Back to the top"
                    onClick={center}
                >
                    <Crosshair aria-hidden="true" />
                </Button>
            </div>
        </div>
    );
}

type NodeProps = {
    reports: Map<number, Person[]>;
    teamSize: Map<number, number>;
    ownId: number | null;
    isOpen: (id: number) => boolean;
    toggle: (id: number) => void;
    /** True or false while searching; null when nothing is being searched. */
    highlight: (id: number) => boolean | null;
};

/**
 * One person in the chart with the lines down to their team. Each report
 * draws its own share of the bar above it, so the bar spans exactly from the
 * first report to the last.
 */
function ChartNode({
    person,
    seen,
    reports,
    ...rest
}: NodeProps & { person: Person; seen: Set<number> }) {
    const team = seen.has(person.id) ? [] : (reports.get(person.id) ?? []);
    const open = team.length > 0 && rest.isOpen(person.id);
    const nextSeen = new Set(seen).add(person.id);

    return (
        <li className="flex flex-col items-center">
            <PersonCard
                person={person}
                team={team.length}
                open={open}
                reports={reports}
                {...rest}
            />

            {open && (
                <>
                    <span aria-hidden="true" className="h-6 w-px bg-border" />
                    <ul className="flex items-start">
                        {team.map((report, index) => (
                            <li
                                key={report.id}
                                className="relative flex flex-col items-center px-3"
                            >
                                {team.length > 1 && (
                                    <span
                                        aria-hidden="true"
                                        className={cn(
                                            'absolute top-0 h-px bg-border',
                                            index === 0
                                                ? 'right-0 left-1/2'
                                                : index === team.length - 1
                                                  ? 'right-1/2 left-0'
                                                  : 'inset-x-0',
                                        )}
                                    />
                                )}
                                <span
                                    aria-hidden="true"
                                    className="h-6 w-px bg-border"
                                />
                                <ul>
                                    <ChartNode
                                        person={report}
                                        seen={nextSeen}
                                        reports={reports}
                                        {...rest}
                                    />
                                </ul>
                            </li>
                        ))}
                    </ul>
                </>
            )}
        </li>
    );
}

function PersonCard({
    person,
    team,
    open,
    teamSize,
    ownId,
    toggle,
    highlight,
}: NodeProps & { person: Person; team: number; open: boolean }) {
    const workspace = useWorkspace();
    const tone = departmentTone(person.department);
    const isYou = person.id === ownId;
    const match = highlight(person.id);
    const total = teamSize.get(person.id) ?? 0;

    return (
        <div
            className={cn(
                'relative w-60 overflow-hidden rounded-xl border bg-card text-left shadow-xs transition-[opacity,box-shadow] motion-reduce:transition-none',
                isYou ? 'border-primary' : 'border-border',
                match === true &&
                    'ring-2 ring-primary ring-offset-2 ring-offset-background',
                match === false && 'opacity-40',
            )}
        >
            {tone && (
                <span
                    aria-hidden="true"
                    className={cn('absolute inset-x-0 top-0 h-1', tone.bar)}
                />
            )}
            <Link
                href={
                    showEmployee({
                        workspace: workspace.slug,
                        employee: person.id,
                    }).url
                }
                className="flex items-start gap-3 px-3 pt-4 pb-3 hover:bg-accent/60 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none focus-visible:ring-inset"
            >
                <PersonAvatar person={person} className="size-10" />
                <span className="min-w-0 flex-1">
                    <span className="block truncate text-sm font-semibold">
                        {person.name}
                        {isYou && (
                            <span className="font-normal text-muted-foreground">
                                {' '}
                                (you)
                            </span>
                        )}
                    </span>
                    <span className="block truncate text-xs text-muted-foreground">
                        {person.job_title || 'No title'}
                    </span>
                    <span className="mt-1.5 flex flex-wrap gap-1">
                        {person.department && tone && (
                            <span
                                className={cn(
                                    'inline-flex max-w-full truncate rounded-md px-1.5 py-0.5 text-[11px] font-medium',
                                    tone.chip,
                                )}
                            >
                                {person.department}
                            </span>
                        )}
                        {person.status !== 'active' && (
                            <StatusPill
                                status={person.status}
                                label={person.status_label}
                            />
                        )}
                    </span>
                </span>
            </Link>

            {team > 0 && (
                <button
                    type="button"
                    onClick={() => toggle(person.id)}
                    aria-expanded={open}
                    aria-label={`${open ? 'Hide' : 'Show'} ${person.name}'s team`}
                    className="flex w-full items-center justify-between gap-2 border-t border-border/70 px-3 py-1.5 text-xs text-muted-foreground hover:bg-accent/60 hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none focus-visible:ring-inset"
                >
                    <span className="tabular-nums">
                        {team} direct
                        {total > team && ` · ${total} in team`}
                    </span>
                    <ChevronDown
                        aria-hidden="true"
                        className={cn(
                            'size-3.5 transition-transform motion-reduce:transition-none',
                            !open && '-rotate-90',
                        )}
                    />
                </button>
            )}
        </div>
    );
}

/**
 * The same tree as an indented list, for narrow screens and screen readers.
 */
function ListNode({
    person,
    seen,
    reports,
    teamSize,
    ownId,
    isOpen,
    toggle,
    highlight,
}: NodeProps & { person: Person; seen: Set<number> }) {
    const workspace = useWorkspace();
    const team = seen.has(person.id) ? [] : (reports.get(person.id) ?? []);
    const open = team.length > 0 && isOpen(person.id);
    const nextSeen = new Set(seen).add(person.id);
    const tone = departmentTone(person.department);
    const match = highlight(person.id);
    const isYou = person.id === ownId;
    const total = teamSize.get(person.id) ?? 0;

    return (
        <li>
            <div
                className={cn(
                    'flex max-w-xl items-center gap-1 rounded-lg border bg-card transition-opacity motion-reduce:transition-none',
                    isYou ? 'border-primary' : 'border-border',
                    match === true && 'ring-2 ring-primary',
                    match === false && 'opacity-40',
                )}
            >
                {team.length > 0 ? (
                    <button
                        type="button"
                        onClick={() => toggle(person.id)}
                        aria-expanded={open}
                        aria-label={`${open ? 'Hide' : 'Show'} ${person.name}'s team`}
                        className="ml-1 grid size-8 shrink-0 place-items-center rounded-md text-muted-foreground hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    >
                        <ChevronDown
                            aria-hidden="true"
                            className={cn(
                                'size-4 transition-transform motion-reduce:transition-none',
                                !open && '-rotate-90',
                            )}
                        />
                    </button>
                ) : (
                    <span aria-hidden="true" className="ml-1 size-8 shrink-0" />
                )}
                <Link
                    href={
                        showEmployee({
                            workspace: workspace.slug,
                            employee: person.id,
                        }).url
                    }
                    className="flex min-w-0 flex-1 items-center gap-2 rounded-r-lg py-2 pr-3 hover:bg-accent/60 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                >
                    <PersonAvatar person={person} className="size-8" />
                    <span className="min-w-0 flex-1">
                        <span className="block truncate text-sm font-medium">
                            {person.name}
                            {isYou && (
                                <span className="font-normal text-muted-foreground">
                                    {' '}
                                    (you)
                                </span>
                            )}
                        </span>
                        <span className="block truncate text-xs text-muted-foreground">
                            {[person.job_title, person.department]
                                .filter(Boolean)
                                .join(' · ') || 'No title'}
                            {team.length > 0 &&
                                ` · ${team.length} direct${total > team.length ? `, ${total} in team` : ''}`}
                        </span>
                    </span>
                    {person.status !== 'active' && (
                        <StatusPill
                            status={person.status}
                            label={person.status_label}
                        />
                    )}
                    {tone && (
                        <span
                            aria-hidden="true"
                            className={cn(
                                'size-2 shrink-0 rounded-full',
                                tone.bar,
                            )}
                        />
                    )}
                </Link>
            </div>

            {open && (
                <ul className="mt-2 ml-5 space-y-2 border-l border-border pl-4">
                    {team.map((report) => (
                        <ListNode
                            key={report.id}
                            person={report}
                            seen={nextSeen}
                            reports={reports}
                            teamSize={teamSize}
                            ownId={ownId}
                            isOpen={isOpen}
                            toggle={toggle}
                            highlight={highlight}
                        />
                    ))}
                </ul>
            )}
        </li>
    );
}
