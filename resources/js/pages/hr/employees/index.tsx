import { Link, router } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useState } from 'react';
import {
    EmptyNote,
    formatDate,
    HrPage,
    PersonAvatar,
    StatusPill,
} from '@/components/hr/hr-ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useWorkspace } from '@/hooks/use-workspace';
import {
    index as employeesIndex,
    show as showEmployee,
} from '@/routes/hr/employees';
import type { Employee, Option, Paginated } from '@/types/hr';

type Props = {
    employees: Paginated<Employee>;
    filters: {
        search: string;
        status: string | null;
        department: string | null;
    };
    departments: string[];
    statuses: Option[];
};

const ALL = '__all__';

/**
 * The employee directory. Every workspace member appears here — they became
 * employees when they joined — and terminated files are one filter away.
 */
export default function EmployeesIndex({
    employees,
    filters,
    departments,
    statuses,
}: Props) {
    const workspace = useWorkspace();
    const [search, setSearch] = useState(filters.search);

    function apply(next: Partial<Props['filters']>) {
        const query = { ...filters, search, ...next };

        router.get(
            employeesIndex(workspace.slug).url,
            Object.fromEntries(
                Object.entries(query).filter(
                    ([, value]) => value !== null && value !== '',
                ),
            ),
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    return (
        <HrPage
            title="Employees"
            description={`${employees.meta.total} ${employees.meta.total === 1 ? 'person' : 'people'}. Everyone who joins the workspace gets an employee file.`}
        >
            <div className="flex flex-wrap items-center gap-2">
                <form
                    className="relative min-w-48 flex-1"
                    onSubmit={(event) => {
                        event.preventDefault();
                        apply({});
                    }}
                >
                    <Search
                        aria-hidden="true"
                        className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                    />
                    <label htmlFor="employee-search" className="sr-only">
                        Search employees
                    </label>
                    <Input
                        id="employee-search"
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        placeholder="Search by name, email, title, or number"
                        className="pl-8"
                    />
                </form>

                <Select
                    value={filters.status ?? ALL}
                    onValueChange={(value) =>
                        apply({ status: value === ALL ? null : value })
                    }
                >
                    <SelectTrigger className="w-40" aria-label="Status">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value={ALL}>Current staff</SelectItem>
                        {statuses.map((status) => (
                            <SelectItem key={status.value} value={status.value}>
                                {status.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>

                {departments.length > 0 && (
                    <Select
                        value={filters.department ?? ALL}
                        onValueChange={(value) =>
                            apply({ department: value === ALL ? null : value })
                        }
                    >
                        <SelectTrigger className="w-44" aria-label="Department">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>All departments</SelectItem>
                            {departments.map((department) => (
                                <SelectItem key={department} value={department}>
                                    {department}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                )}
            </div>

            {employees.data.length === 0 ? (
                <EmptyNote>No employees match those filters.</EmptyNote>
            ) : (
                <div className="overflow-x-auto rounded-xl border border-border">
                    <table className="w-full min-w-[40rem] text-sm">
                        <thead className="bg-muted/50 text-left text-xs text-muted-foreground">
                            <tr>
                                <th
                                    scope="col"
                                    className="px-4 py-2.5 font-medium"
                                >
                                    Name
                                </th>
                                <th
                                    scope="col"
                                    className="px-4 py-2.5 font-medium"
                                >
                                    Role
                                </th>
                                <th
                                    scope="col"
                                    className="px-4 py-2.5 font-medium"
                                >
                                    Type
                                </th>
                                <th
                                    scope="col"
                                    className="px-4 py-2.5 font-medium"
                                >
                                    Hired
                                </th>
                                <th
                                    scope="col"
                                    className="px-4 py-2.5 font-medium"
                                >
                                    Status
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-border">
                            {employees.data.map((employee) => (
                                <tr
                                    key={employee.id}
                                    className="hover:bg-accent/50"
                                >
                                    <td className="px-4 py-2.5">
                                        <Link
                                            href={showEmployee({
                                                workspace: workspace.slug,
                                                employee: employee.id,
                                            })}
                                            className="flex items-center gap-3"
                                        >
                                            <span className="relative">
                                                <PersonAvatar
                                                    person={employee.user}
                                                />
                                                {employee.is_clocked_in && (
                                                    <span
                                                        title="Clocked in"
                                                        className="absolute -right-0.5 -bottom-0.5 size-2.5 rounded-full bg-emerald-500 ring-2 ring-background"
                                                    />
                                                )}
                                            </span>
                                            <span className="min-w-0">
                                                <span className="block truncate font-medium">
                                                    {employee.user.name}
                                                </span>
                                                <span className="block truncate text-xs text-muted-foreground">
                                                    {employee.employee_number
                                                        ? `${employee.employee_number} · `
                                                        : ''}
                                                    {employee.email}
                                                </span>
                                            </span>
                                        </Link>
                                    </td>
                                    <td className="px-4 py-2.5">
                                        <span className="block">
                                            {employee.job_title ?? '—'}
                                        </span>
                                        <span className="block text-xs text-muted-foreground">
                                            {employee.department}
                                        </span>
                                    </td>
                                    <td className="px-4 py-2.5 text-muted-foreground">
                                        {employee.employment_type_label}
                                    </td>
                                    <td className="px-4 py-2.5 text-muted-foreground tabular-nums">
                                        {formatDate(employee.hired_on)}
                                    </td>
                                    <td className="px-4 py-2.5">
                                        <StatusPill
                                            status={employee.status}
                                            label={employee.status_label}
                                        />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {employees.meta.last_page > 1 && (
                <nav
                    className="flex items-center justify-between text-sm"
                    aria-label="Pagination"
                >
                    <Button
                        variant="outline"
                        size="sm"
                        asChild
                        disabled={!employees.links.prev}
                    >
                        {employees.links.prev ? (
                            <Link href={employees.links.prev}>Previous</Link>
                        ) : (
                            <span>Previous</span>
                        )}
                    </Button>
                    <span className="text-muted-foreground">
                        Page {employees.meta.current_page} of{' '}
                        {employees.meta.last_page}
                    </span>
                    <Button
                        variant="outline"
                        size="sm"
                        asChild
                        disabled={!employees.links.next}
                    >
                        {employees.links.next ? (
                            <Link href={employees.links.next}>Next</Link>
                        ) : (
                            <span>Next</span>
                        )}
                    </Button>
                </nav>
            )}
        </HrPage>
    );
}
