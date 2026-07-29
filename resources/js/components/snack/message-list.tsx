import { Loader2 } from 'lucide-react';
import {
    useCallback,
    useEffect,
    useLayoutEffect,
    useMemo,
    useRef,
} from 'react';
import {
    MessageItem,
    PendingMessageItem,
} from '@/components/snack/message-item';
import {
    continuesGroup,
    dayLabel,
    isSameDay,
    pendingContinuesGroup,
} from '@/lib/message-grouping';
import type { Message, PendingMessage } from '@/types';

/**
 * A channel's timeline.
 *
 * # Scroll behaviour
 *
 * Three things move the scroll position, and each needs the opposite treatment:
 *
 *  - a new message arrives → follow it, but only if the reader was already at
 *    the bottom. Yanking someone away from what they were reading is worse than
 *    making them scroll down.
 *  - older history is prepended → hold the reader exactly where they were.
 *    Content is inserted *above* them, so without correction the view jumps by
 *    the height of the whole page they just loaded.
 *  - the reader scrolls → record where they are, so the two rules above can
 *    tell which case they are in.
 *
 * # Rendering cost
 *
 * The number of DOM nodes is bounded by pagination rather than virtualization:
 * only the pages actually scrolled through are ever in the document, and each
 * group is marked `content-visibility: auto` so the browser skips layout and
 * paint for anything outside the viewport. True windowing would need a
 * measurement library and belongs with the rest of the Phase 7 optimization
 * work; this keeps the DOM honest without one.
 */
export function MessageList({
    messages,
    pending,
    hasMore,
    loadingOlder,
    currentUserId,
    onLoadOlder,
    onEdit,
    onDelete,
    onRetry,
    onDiscard,
    onReachBottom,
    header,
}: {
    messages: Message[];
    pending: PendingMessage[];
    hasMore: boolean;
    loadingOlder: boolean;
    currentUserId: number | null;
    onLoadOlder: () => void;
    onEdit: (id: number, body: string) => Promise<void>;
    onDelete: (id: number) => void;
    onRetry: (nonce: string) => void;
    onDiscard: (nonce: string) => void;
    onReachBottom: () => void;
    /** The channel masthead, which scrolls away with the history. */
    header?: React.ReactNode;
}) {
    const container = useRef<HTMLDivElement>(null);
    const sentinel = useRef<HTMLDivElement>(null);

    /** Where the reader was before the last render, for scroll correction. */
    const anchor = useRef({ top: 0, height: 0, atBottom: true });
    const oldestId = useRef<number | undefined>(messages[0]?.id);

    const recordPosition = useCallback(() => {
        const element = container.current;

        if (element === null) {
            return;
        }

        const distanceFromBottom =
            element.scrollHeight - element.scrollTop - element.clientHeight;

        anchor.current = {
            top: element.scrollTop,
            height: element.scrollHeight,
            // A small tolerance: sub-pixel layout and a partially visible last
            // message should still count as "at the bottom".
            atBottom: distanceFromBottom < 80,
        };
    }, []);

    /*
     * Runs before paint, so neither correction is ever visible as a jump.
     * Intentionally has no dependency array — it must compare against the
     * previous render on every render, whatever caused it.
     */
    useLayoutEffect(() => {
        const element = container.current;

        if (element === null) {
            return;
        }

        const firstId = messages[0]?.id;
        const prepended =
            firstId !== undefined &&
            oldestId.current !== undefined &&
            firstId < oldestId.current;

        if (prepended) {
            // Keep the reader on the same message by absorbing exactly the
            // height that was just inserted above them.
            element.scrollTop =
                anchor.current.top +
                (element.scrollHeight - anchor.current.height);
        } else if (anchor.current.atBottom) {
            element.scrollTop = element.scrollHeight;
        }

        oldestId.current = firstId;
        anchor.current.height = element.scrollHeight;
    });

    /** Load older history as the top of the list comes into view. */
    useEffect(() => {
        const target = sentinel.current;

        if (target === null || !hasMore) {
            return;
        }

        const observer = new IntersectionObserver(
            (entries) => {
                if (entries[0].isIntersecting) {
                    onLoadOlder();
                }
            },
            {
                root: container.current,
                // Start fetching before the reader actually reaches the top, so
                // the next page is usually already there when they get there.
                rootMargin: '300px 0px 0px 0px',
            },
        );

        observer.observe(target);

        return () => observer.disconnect();
    }, [hasMore, onLoadOlder]);

    /*
     * Sync the read pointer when the newest message changes while the reader is
     * at the bottom — which is the only moment they have demonstrably seen it.
     */
    const newestId = messages.at(-1)?.id;

    useEffect(() => {
        if (anchor.current.atBottom) {
            onReachBottom();
        }
    }, [newestId, onReachBottom]);

    /*
     * Grouping and day dividers are decided in one pass before rendering rather
     * than by carrying a mutable "previous" through the map. Each row's shape
     * depends on the row before it, and a variable threaded through JSX is
     * exactly the kind of render-order dependency that breaks under React's
     * ability to re-run or reorder a render.
     */
    const rows = useMemo(() => {
        const sent = messages.map((message, index) => {
            const previous = index > 0 ? messages[index - 1] : null;

            const startsDay =
                previous === null ||
                previous.created_at === null ||
                message.created_at === null ||
                !isSameDay(previous.created_at, message.created_at);

            return {
                message,
                startsDay,
                // A group must never straddle a divider, or its header ends up
                // orphaned above the date it belongs under.
                grouped: !startsDay && continuesGroup(previous, message),
            };
        });

        const lastSent = messages.at(-1) ?? null;

        const unsent = pending.map((message, index) => ({
            message,
            // Only the first unsent message can continue the sent history. The
            // rest always continue each other — same author, same moment.
            grouped:
                index === 0 ? pendingContinuesGroup(lastSent, message) : true,
        }));

        return { sent, unsent };
    }, [messages, pending]);

    return (
        <div
            ref={container}
            onScroll={recordPosition}
            className="snack-scroll min-h-0 flex-1 overflow-y-auto"
        >
            {hasMore && (
                <div ref={sentinel} aria-hidden="true" className="h-px" />
            )}

            {loadingOlder && (
                <div className="flex justify-center py-3">
                    <Loader2
                        aria-label="Loading earlier messages"
                        className="size-4 animate-spin text-muted-foreground"
                    />
                </div>
            )}

            {!hasMore && header}

            <div className="pb-2">
                {rows.sent.map(({ message, grouped, startsDay }) => (
                    <div key={message.id} style={{ contentVisibility: 'auto' }}>
                        {startsDay && message.created_at !== null && (
                            <DayDivider iso={message.created_at} />
                        )}

                        <MessageItem
                            message={message}
                            grouped={grouped}
                            isOwn={message.sender?.id === currentUserId}
                            onEdit={onEdit}
                            onDelete={onDelete}
                        />
                    </div>
                ))}

                {rows.unsent.map(({ message, grouped }) => (
                    <PendingMessageItem
                        key={message.nonce}
                        message={message}
                        grouped={grouped}
                        onRetry={() => onRetry(message.nonce)}
                        onDiscard={() => onDiscard(message.nonce)}
                    />
                ))}
            </div>
        </div>
    );
}

/**
 * A date separator.
 *
 * The label sits on the rule rather than above it, so a long history reads as
 * one continuous column broken by days rather than a stack of sections.
 */
function DayDivider({ iso }: { iso: string }) {
    return (
        <div className="sticky top-0 z-10 flex items-center gap-3 bg-background/85 px-4 py-2 backdrop-blur-sm">
            <span aria-hidden="true" className="h-px flex-1 bg-border" />

            <span className="rounded-full border border-border px-2.5 py-0.5 text-xs font-semibold text-muted-foreground">
                {dayLabel(iso)}
            </span>

            <span aria-hidden="true" className="h-px flex-1 bg-border" />
        </div>
    );
}
