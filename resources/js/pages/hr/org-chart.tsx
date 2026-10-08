import { Link } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import {
    EmptyNote,
    HrPage,
    PersonAvatar,
    SelectField,
} from '@/components/hr/hr-ui';
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
};

type Props = {
    people: Person[];
    own_employee_id: number | null;
};

/**
 * Who reports to whom, drawn from each person's manager.
 *
 * Anybody whose manager is not (or no longer) employed here starts a tree of
 * their own. A manager chain that loops back on itself is cut where it would
 * repeat, so a data-entry mistake cannot hang the page.
 */
export default function OrgChartPage({ people, own_employee_id }: Props) {
    const workspace = useWorkspace();
    const [focus, setFocus] = useState('');

    const { byId, reports, roots } = useMemo(() => {
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

        const roots = people.filter(
            (person) =>
                person.manager_id === null || !byId.has(person.manager_id),
        );

        // People in a loop of managers are reachable from no root; start a
        // tree at the first of each loop so they still appear.
        const reached = new Set<number>();
        const reach = (person: Person) => {
            if (reached.has(person.id)) {
                return;
            }

            reached.add(person.id);
            (reports.get(person.id) ?? []).forEach(reach);
        };

        roots.forEach(reach);

        for (const person of people) {
            if (!reached.has(person.id)) {
                roots.push(person);
                reach(person);
            }
        }

        return { byId, reports, roots };
    }, [people]);

    const managers = people.filter((person) => reports.has(person.id));
    const shown = focus === '' ? roots : [byId.get(Number(focus))!];

    function renderNode(person: Person, seen: Set<number>) {
        const children = seen.has(person.id)
            ? []
            : (reports.get(person.id) ?? []);
        const nextSeen = new Set(seen).add(person.id);

        return (
            <li key={person.id} className="relative">
                <Link
                    href={
                        showEmployee({
                            workspace: workspace.slug,
                            employee: person.id,
                        }).url
                    }
                    className={cn(
                        'inline-flex max-w-full items-center gap-2 rounded-lg border bg-card px-3 py-2 transition-colors hover:bg-accent',
                        person.id === own_employee_id
                            ? 'border-primary'
                            : 'border-border',
                    )}
                >
                    <PersonAvatar person={person} className="size-8" />
                    <span className="min-w-0">
                        <span className="block truncate text-sm font-medium">
                            {person.name}
                            {person.id === own_employee_id && (
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
                            {children.length > 0 &&
                                ` · ${children.length} direct ${children.length === 1 ? 'report' : 'reports'}`}
                        </span>
                    </span>
                </Link>

                {children.length > 0 && (
                    <ul className="mt-2 ml-4 space-y-2 border-l border-border pl-4">
                        {children.map((child) => renderNode(child, nextSeen))}
                    </ul>
                )}
            </li>
        );
    }

    return (
        <HrPage
            title="Org chart"
            description="Who reports to whom. HR sets each person's manager on their file."
        >
            {people.length === 0 ? (
                <EmptyNote>Nobody is employed here yet.</EmptyNote>
            ) : (
                <>
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
                            className="max-w-xs"
                        />
                    )}
                    <div className="overflow-x-auto">
                        <ul className="space-y-2">
                            {shown.map((person) =>
                                renderNode(person, new Set()),
                            )}
                        </ul>
                    </div>
                </>
            )}
        </HrPage>
    );
}
