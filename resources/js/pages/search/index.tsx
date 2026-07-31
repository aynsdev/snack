import { Head, Link, router } from '@inertiajs/react';
import { AtSign, CalendarDays, Hash, Paperclip, Search } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import {
    ContainerLabel,
    useContainerHref,
} from '@/components/snack/container-label';
import { EmptyState } from '@/components/snack/empty-state';
import { UserAvatar } from '@/components/snack/user-avatar';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useWorkspace } from '@/hooks/use-workspace';
import { dayLabel } from '@/lib/message-grouping';
import { cn } from '@/lib/utils';
import { index as searchIndex } from '@/routes/search';
import type { ParsedSearchQuery, SearchResult, SnippetSegment } from '@/types';

type Props = {
    query: ParsedSearchQuery;
    results: SearchResult[];
    total: number;
    page: number;
    has_more: boolean;
};

/**
 * Workspace search.
 *
 * The URL is the state: a search is something people link to a colleague and
 * come back to an hour later, and the back button has to undo a query the way it
 * undoes any other navigation. So this submits rather than fetching as you type.
 *
 * Results are ranked by relevance, not recency — which is the point of searching
 * rather than scrolling — and every one of them was already visible to this
 * viewer before they searched: the server narrows to their own channels and
 * conversations before ranking anything.
 */
export default function SearchIndex({
    query,
    results,
    total,
    page,
    has_more: hasMore,
}: Props) {
    const workspace = useWorkspace();
    const [term, setTerm] = useState(query.raw);

    const submit = (raw: string, nextPage = 1) => {
        router.get(
            searchIndex.url(workspace.slug),
            {
                ...(raw === '' ? {} : { q: raw }),
                ...(nextPage === 1 ? {} : { page: nextPage }),
            },
            { preserveState: true, preserveScroll: nextPage > 1 },
        );
    };

    return (
        <>
            <Head
                title={query.raw === '' ? 'Search' : `${query.raw} — Search`}
            />

            <div className="flex min-w-0 flex-1 flex-col">
                <header className="border-b border-border px-4 py-3">
                    <form
                        onSubmit={(event) => {
                            event.preventDefault();
                            submit(term);
                        }}
                    >
                        <div className="relative">
                            <Search
                                aria-hidden="true"
                                className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                            />

                            <Input
                                value={term}
                                autoFocus
                                onChange={(event) =>
                                    setTerm(event.target.value)
                                }
                                placeholder="Search messages and files"
                                aria-label="Search this workspace"
                                className="h-10 pl-9"
                            />
                        </div>
                    </form>

                    <QueryChips query={query} />

                    {!query.is_empty && (
                        <p className="mt-2 text-xs text-muted-foreground">
                            {total === 1 ? '1 result' : `${total} results`}
                        </p>
                    )}
                </header>

                <div className="snack-scroll min-h-0 flex-1 overflow-y-auto">
                    {query.is_empty ? (
                        <SearchTips tooShort={query.too_short} />
                    ) : results.length === 0 ? (
                        <EmptyState
                            icon={Search}
                            title="Nothing matched"
                            description="Try fewer words, or drop a filter. Search only covers channels and conversations you are part of."
                        />
                    ) : (
                        <>
                            <ul className="divide-y divide-border">
                                {results.map((result) => (
                                    <ResultRow
                                        key={result.message.id}
                                        result={result}
                                    />
                                ))}
                            </ul>

                            {hasMore && (
                                <div className="flex justify-center p-4">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            submit(query.raw, page + 1)
                                        }
                                    >
                                        Show more results
                                    </Button>
                                </div>
                            )}
                        </>
                    )}
                </div>
            </div>
        </>
    );
}

/**
 * One result.
 *
 * Links to the *container* rather than to the message: a message is read where
 * it was said, and landing on one with no conversation around it is exactly the
 * disorientation the snippet is there to prevent.
 */
function ResultRow({ result }: { result: SearchResult }) {
    const href = useContainerHref(result.container);
    const message = result.message;

    return (
        <li>
            <Link
                href={href ?? '#'}
                className={cn(
                    'flex gap-3 px-4 py-3 transition-colors hover:bg-accent/50',
                    href === null && 'pointer-events-none opacity-60',
                )}
            >
                <div className="pt-0.5">
                    {message.sender && (
                        <UserAvatar user={message.sender} size="md" />
                    )}
                </div>

                <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-2">
                        <ContainerLabel container={result.container} />

                        {message.created_at !== null && (
                            <span className="ml-auto shrink-0 text-xs text-muted-foreground">
                                {dayLabel(message.created_at)}
                            </span>
                        )}
                    </div>

                    <p className="mt-0.5 text-xs text-muted-foreground">
                        {message.sender?.name ?? 'Snack'}
                    </p>

                    <Snippet
                        segments={result.snippet}
                        fallback={message.body}
                    />

                    {/*
                     * A count rather than the files themselves. The whole row
                     * is already a link, and an attachment list is full of its
                     * own buttons and image previews — nesting those inside an
                     * anchor is invalid markup and, in practice, a click that
                     * does two things at once.
                     */}
                    {message.attachments.length > 0 && (
                        <span className="mt-1.5 flex items-center gap-1.5 text-xs text-muted-foreground">
                            <Paperclip aria-hidden="true" className="size-3" />
                            {message.attachments.length === 1
                                ? message.attachments[0].name
                                : `${message.attachments.length} files`}
                        </span>
                    )}
                </div>
            </Link>
        </li>
    );
}

/**
 * The matched text, with the hits marked.
 *
 * The segments arrive pre-split from the server precisely so this can render
 * them as elements. Nothing here is parsed as markup — the same guarantee the
 * rest of the message pipeline makes, extended to snippets.
 *
 * Falls back to the message body when there is nothing to highlight, which is
 * what a filters-only search produces.
 */
function Snippet({
    segments,
    fallback,
}: {
    segments: SnippetSegment[];
    fallback: string;
}) {
    if (segments.length === 0) {
        return (
            <p className="mt-1 line-clamp-3 text-sm break-words">{fallback}</p>
        );
    }

    return (
        <p className="mt-1 line-clamp-3 text-sm break-words">
            {segments.map((segment, index) => (
                <span
                    key={index}
                    className={
                        segment.highlight
                            ? 'rounded-sm bg-brand-subtle px-0.5 font-semibold text-foreground'
                            : undefined
                    }
                >
                    {segment.text}
                </span>
            ))}
        </p>
    );
}

/**
 * What the server actually made of the query.
 *
 * Echoing the parsed filters back matters more than it looks: `from:@ada` and
 * `from: @ada` are not the same string, and without this the second one silently
 * searches for the words instead of filtering by the person.
 */
function QueryChips({ query }: { query: ParsedSearchQuery }) {
    const chips: ReactNode[] = [];

    for (const username of query.from) {
        chips.push(
            <Chip key={`from-${username}`} icon={AtSign}>
                {username}
            </Chip>,
        );
    }

    for (const slug of query.in) {
        chips.push(
            <Chip key={`in-${slug}`} icon={Hash}>
                {slug}
            </Chip>,
        );
    }

    if (query.has_file) {
        chips.push(
            <Chip key="has-file" icon={Paperclip}>
                has files
            </Chip>,
        );
    }

    if (query.after !== null) {
        chips.push(
            <Chip key="after" icon={CalendarDays}>
                after {query.after}
            </Chip>,
        );
    }

    if (query.before !== null) {
        chips.push(
            <Chip key="before" icon={CalendarDays}>
                before {query.before}
            </Chip>,
        );
    }

    if (chips.length === 0) {
        return null;
    }

    return <div className="mt-2 flex flex-wrap gap-1.5">{chips}</div>;
}

function Chip({
    icon: Icon,
    children,
}: {
    icon: typeof AtSign;
    children: ReactNode;
}) {
    return (
        <span className="flex items-center gap-1 rounded-full border border-border bg-muted/40 px-2 py-0.5 text-xs text-muted-foreground">
            <Icon aria-hidden="true" className="size-3" />
            {children}
        </span>
    );
}

/**
 * The empty state, which doubles as the documentation for the filters.
 *
 * Nobody discovers `from:` by guessing, and a search box that quietly supports
 * syntax nobody is told about is a search box that only its author can use.
 */
function SearchTips({ tooShort }: { tooShort: boolean }) {
    return (
        <div className="mx-auto max-w-lg px-6 py-10">
            <h2 className="text-base font-semibold">
                {tooShort
                    ? 'That is a little short to search for'
                    : 'Search this workspace'}
            </h2>

            <p className="mt-1.5 text-sm text-muted-foreground">
                {tooShort
                    ? 'Try at least a couple of characters, or narrow it down with a filter.'
                    : 'Messages and file names across every channel and conversation you are part of.'}
            </p>

            <dl className="mt-6 space-y-3 text-sm">
                <Tip term="from:@ada">only what one person said</Tip>
                <Tip term="in:#design">only one channel</Tip>
                <Tip term="has:file">only messages with attachments</Tip>
                <Tip term="after:2026-01-01">since a date</Tip>
                <Tip term="before:2026-06-30">up to a date</Tip>
                <Tip term={'"exact phrase"'}>those words, in that order</Tip>
            </dl>
        </div>
    );
}

function Tip({ term, children }: { term: string; children: ReactNode }) {
    return (
        <div className="flex gap-3">
            <dt className="w-40 shrink-0 font-mono text-xs text-brand">
                {term}
            </dt>
            <dd className="text-muted-foreground">{children}</dd>
        </div>
    );
}
