import { Link, usePage } from '@inertiajs/react';
import {
    CalendarDays,
    ChartColumn,
    BookOpen,
    ClipboardList,
    Clock,
    FileStack,
    LayoutDashboard,
    Megaphone,
    Network,
    Plane,
    Settings,
    UserRound,
    Users,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { useWorkspace } from '@/hooks/use-workspace';
import { cn } from '@/lib/utils';
import {
    dashboard as hrDashboard,
    index as hrIndex,
    me as myProfile,
    orgChart,
} from '@/routes/hr';
import { index as announcementsIndex } from '@/routes/hr/announcements';
import { index as documentsIndex } from '@/routes/hr/documents';
import { index as employeesIndex } from '@/routes/hr/employees';
import { index as holidaysIndex } from '@/routes/hr/holidays';
import { index as leaveIndex } from '@/routes/hr/leave';
import { index as policiesIndex } from '@/routes/hr/policies';
import { index as requestsIndex } from '@/routes/hr/requests';
import { edit as hrSettings } from '@/routes/hr/settings';
import { index as timeIndex } from '@/routes/hr/time';

type Item = {
    label: string;
    icon: LucideIcon;
    href: { url: string };
    /** Path after `/w/{workspace}/hr` that marks this item active. */
    match: RegExp;
};

/**
 * The HR section's sidebar, grouped by whose information it is: your own
 * ("Me"), everyone's ("Company"), then the admin screens ("Manage").
 */
export function HrSidebar({ onNavigate }: { onNavigate?: () => void }) {
    const workspace = useWorkspace();
    const { currentUrl } = useCurrentUrl();
    const path = currentUrl.replace(/^\/w\/[^/]+\/hr/, '').split(/[?#]/)[0];

    // Your own file lives at /employees/{id}; it belongs under "Me", not under
    // the admin directory that happens to share its URL.
    const ownEmployeeId = usePage().props.clock?.employee_id ?? null;
    const ownFile =
        ownEmployeeId !== null ? `/employees/${ownEmployeeId}` : null;
    const isOwnFile = ownFile !== null && path === ownFile;

    const me: Item[] = [
        {
            label: 'Overview',
            icon: LayoutDashboard,
            href: hrIndex(workspace.slug),
            match: /^\/?$/,
        },
        {
            label: 'My profile',
            icon: UserRound,
            href: myProfile(workspace.slug),
            match: isOwnFile ? /.*/ : /^\/me$/,
        },
        {
            label: 'Time clock',
            icon: Clock,
            href: timeIndex(workspace.slug),
            match: /^\/time/,
        },
        {
            label: 'Leave',
            icon: Plane,
            href: leaveIndex(workspace.slug),
            match: /^\/leave/,
        },
        {
            label: 'Requests',
            icon: ClipboardList,
            href: requestsIndex(workspace.slug),
            match: /^\/requests/,
        },
        {
            label: 'Documents',
            icon: FileStack,
            href: documentsIndex(workspace.slug),
            match: /^\/documents/,
        },
    ];

    const company: Item[] = [
        {
            label: 'Holidays',
            icon: CalendarDays,
            href: holidaysIndex(workspace.slug),
            match: /^\/holidays/,
        },
        {
            label: 'Announcements',
            icon: Megaphone,
            href: announcementsIndex(workspace.slug),
            match: /^\/announcements/,
        },
        {
            label: 'Policies',
            icon: BookOpen,
            href: policiesIndex(workspace.slug),
            match: /^\/policies/,
        },
        {
            label: 'Org chart',
            icon: Network,
            href: orgChart(workspace.slug),
            match: /^\/org-chart/,
        },
    ];

    const admin: Item[] = [
        {
            label: 'Dashboard',
            icon: ChartColumn,
            href: hrDashboard(workspace.slug),
            match: /^\/dashboard/,
        },
        {
            label: 'Employees',
            icon: Users,
            href: employeesIndex(workspace.slug),
            match: isOwnFile ? /^$/ : /^\/employees/,
        },
        {
            label: 'HR settings',
            icon: Settings,
            href: hrSettings(workspace.slug),
            match: /^\/settings/,
        },
    ];

    return (
        <div className="space-y-4">
            <ItemList
                heading="Me"
                items={me}
                path={path}
                onNavigate={onNavigate}
            />

            <ItemList
                heading="Company"
                items={company}
                path={path}
                onNavigate={onNavigate}
            />

            {workspace.abilities.manage_people && (
                <ItemList
                    heading="Manage"
                    items={admin}
                    path={path}
                    onNavigate={onNavigate}
                />
            )}
        </div>
    );
}

function ItemList({
    heading,
    items,
    path,
    onNavigate,
}: {
    heading: string;
    items: Item[];
    path: string;
    onNavigate?: () => void;
}) {
    return (
        <div>
            <p className="px-2 py-1 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                {heading}
            </p>
            <ul className="space-y-0.5">
                {items.map((item) => {
                    const active = item.match.test(path);

                    return (
                        <li key={item.label}>
                            <Link
                                href={item.href.url}
                                onClick={onNavigate}
                                aria-current={active ? 'page' : undefined}
                                className={cn(
                                    'flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-sm transition-colors',
                                    active
                                        ? 'bg-sidebar-accent font-semibold text-foreground'
                                        : 'text-muted-foreground hover:bg-sidebar-accent hover:text-foreground',
                                )}
                            >
                                <item.icon
                                    aria-hidden="true"
                                    className="size-4"
                                />
                                {item.label}
                            </Link>
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}
