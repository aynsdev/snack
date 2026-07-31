import { Head, Link, router } from '@inertiajs/react';
import { ScrollText } from 'lucide-react';
import { EmptyState } from '@/components/snack/empty-state';
import { UserAvatar } from '@/components/snack/user-avatar';
import { Button } from '@/components/ui/button';
import { useWorkspace } from '@/hooks/use-workspace';
import { dayLabel } from '@/lib/message-grouping';
import { cn } from '@/lib/utils';
import { index as auditIndex } from '@/routes/audit';
import type { AuditEntry } from '@/types';

type Paginated<T> = {
    data: T[];
    meta: { current_page: number; last_page: number; total: number };
    links: { next: string | null; prev: string | null };
};

type Props = {
    entries: Paginated<AuditEntry>;
    /** The active subject filter, or null for everything. */
    subject: string | null;
    subjects: string[];
};

/**
 * What has been done to this workspace, and by whom.
 *
 * Read-only by construction — there is no write route behind this screen at
 * all. Entries are written by the actions that cause them and the table is
 * append-only, so nothing here offers to edit or delete a row.
 *
 * Paginated rather than capped like the message inboxes: reaching back is the
 * entire point of this view.
 */
export default function WorkspaceAudit({ entries, subject, subjects }: Props) {
    const workspace = useWorkspace();

    const filter = (next: string | null) => {
        router.get(
            auditIndex.url(workspace.slug),
            next === null ? {} : { subject: next },
            { preserveScroll: true, preserveState: true },
        );
    };

    return (
        <>
            <Head title={`Audit log · ${workspace.name}`} />

            <div className="snack-scroll min-w-0 flex-1 overflow-y-auto">
                <div className="mx-auto max-w-4xl px-6 py-8">
                    <header className="mb-6">
                        <h1 className="text-2xl font-bold tracking-tight">
                            Audit log
                        </h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {entries.meta.total} recorded{' '}
                            {entries.meta.total === 1 ? 'change' : 'changes'} in{' '}
                            {workspace.name}.
                        </p>
                    </header>

                    {/*
                     * Filtering by subject rather than by individual action:
                     * "what happened to members" is the question an admin
                     * arrives with, and the handful of rows it returns answers
                     * the narrower one on sight.
                     */}
                    <nav
                        aria-label="Filter by subject"
                        className="mb-6 flex flex-wrap gap-2"
                    >
                        <FilterChip
                            label="Everything"
                            active={subject === null}
                            onClick={() => filter(null)}
                        />

                        {subjects.map((option) => (
                            <FilterChip
                                key={option}
                                label={option}
                                active={subject === option}
                                onClick={() => filter(option)}
                            />
                        ))}
                    </nav>

                    {entries.data.length === 0 ? (
                        <EmptyState
                            icon={ScrollText}
                            title="Nothing recorded yet"
                            description="Invitations, role changes, channel edits, and workspace settings all leave an entry here — so an administrator can reconstruct what changed and who changed it."
                        />
                    ) : (
                        <ul className="divide-y divide-border overflow-hidden rounded-xl border border-border">
                            {entries.data.map((entry) => (
                                <AuditRow key={entry.id} entry={entry} />
                            ))}
                        </ul>
                    )}

                    <Pagination links={entries.links} meta={entries.meta} />
                </div>
            </div>
        </>
    );
}

function AuditRow({ entry }: { entry: AuditEntry }) {
    const values = { ...(entry.new_values ?? {}) };

    return (
        <li className="flex flex-wrap items-start gap-4 px-4 py-3">
            <div className="pt-0.5">
                {entry.actor === null ? (
                    <span
                        aria-hidden="true"
                        className="flex size-8 items-center justify-center rounded-full bg-muted text-xs text-muted-foreground"
                    >
                        ?
                    </span>
                ) : (
                    <UserAvatar user={entry.actor} size="md" />
                )}
            </div>

            <div className="min-w-0 flex-1">
                <p className="text-sm font-medium">{entry.action_label}</p>

                <p className="mt-0.5 text-xs text-muted-foreground">
                    {/*
                     * The entry outlives the person who caused it — that is the
                     * point of the nullable actor — so a deleted account is
                     * named as one rather than leaving a blank.
                     */}
                    {entry.actor?.name ?? 'A deleted account'}
                    {entry.ip_address !== null && ` · ${entry.ip_address}`}
                </p>

                {Object.keys(values).length > 0 && (
                    <dl className="mt-2 flex flex-wrap gap-x-4 gap-y-1">
                        {Object.entries(values).map(([key, value]) => (
                            <div key={key} className="flex gap-1.5 text-xs">
                                <dt className="text-muted-foreground">
                                    {key.replaceAll('_', ' ')}
                                </dt>
                                <dd className="font-medium">
                                    {formatValue(value)}
                                </dd>
                            </div>
                        ))}
                    </dl>
                )}
            </div>

            {entry.created_at !== null && (
                <span className="shrink-0 text-xs text-muted-foreground">
                    {dayLabel(entry.created_at)}
                </span>
            )}
        </li>
    );
}

/**
 * The value bags have no fixed schema — a rename carries a name, a settings
 * change carries a nested document — so anything that is not a scalar is shown
 * as JSON rather than as "[object Object]".
 */
function formatValue(value: unknown): string {
    if (value === null || value === undefined) {
        return '—';
    }

    if (typeof value === 'boolean') {
        return value ? 'yes' : 'no';
    }

    return typeof value === 'object' ? JSON.stringify(value) : String(value);
}

function FilterChip({
    label,
    active,
    onClick,
}: {
    label: string;
    active: boolean;
    onClick: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-pressed={active}
            className={cn(
                'rounded-full border px-3 py-1 text-xs font-medium capitalize transition-colors',
                active
                    ? 'border-transparent bg-primary text-primary-foreground'
                    : 'border-border text-muted-foreground hover:bg-accent',
            )}
        >
            {label}
        </button>
    );
}

function Pagination({
    links,
    meta,
}: {
    links: Paginated<unknown>['links'];
    meta: Paginated<unknown>['meta'];
}) {
    if (meta.last_page <= 1) {
        return null;
    }

    return (
        <nav
            aria-label="Audit log pagination"
            className="mt-6 flex items-center justify-between"
        >
            <p className="text-sm text-muted-foreground">
                Page {meta.current_page} of {meta.last_page}
            </p>

            <div className="flex gap-2">
                <Button
                    asChild={links.prev !== null}
                    variant="outline"
                    size="sm"
                    disabled={links.prev === null}
                >
                    {links.prev ? (
                        <Link href={links.prev} preserveScroll>
                            Previous
                        </Link>
                    ) : (
                        <span>Previous</span>
                    )}
                </Button>

                <Button
                    asChild={links.next !== null}
                    variant="outline"
                    size="sm"
                    disabled={links.next === null}
                >
                    {links.next ? (
                        <Link href={links.next} preserveScroll>
                            Next
                        </Link>
                    ) : (
                        <span>Next</span>
                    )}
                </Button>
            </div>
        </nav>
    );
}
