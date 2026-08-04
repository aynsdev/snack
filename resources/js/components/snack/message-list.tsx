import { useVirtualizer } from '@tanstack/react-virtual';
import { Loader2 } from 'lucide-react';
import {
    useCallback,
    useEffect,
    useLayoutEffect,
    useMemo,
    useRef,
    useState,
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
import type { Message, PendingMessage, ReactionGroup } from '@/types';

/**
 * Roughly the height of a grouped message, used only until a row has actually
 * been measured. Estimating low is the safer error: a row that turns out taller
 * pushes content down, which the anchoring below absorbs, whereas estimating
 * high leaves a gap that only closes once the row is measured.
 */
const ESTIMATED_ROW_HEIGHT = 64;

/** Rows rendered beyond the viewport, so scrolling does not reveal blank space. */
const OVERSCAN = 8;

/**
 * Distance from the bottom still counted as "at the bottom", in pixels.
 *
 * Sub-pixel layout and a partially visible last message should both still
 * count. The library's own default is 1px, which in practice means a reader
 * sitting at what looks like the bottom does not get followed.
 */
const BOTTOM_TOLERANCE = 80;

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
 *  - the reader scrolls → nothing, beyond noting whether they are at the bottom.
 *
 * The first two are the virtualizer's `followOnAppend` and `anchorTo: 'end'`.
 * This component used to do both by hand, comparing `scrollHeight` before and
 * after each render. That arithmetic is only correct when every row has been
 * measured, which stops being true the moment rows are windowed — a freshly
 * prepended page is all estimates. Anchoring on the *identity* of the row under
 * the reader survives being wrong about its neighbours' heights.
 *
 * # Rendering cost
 *
 * The sent history is windowed: only the rows near the viewport are in the
 * document, however many pages have been scrolled through. Everything else is
 * two padding values.
 *
 * The window is expressed as padding above and below the visible rows rather
 * than by absolutely positioning each one, which is the more common way to write
 * this. Absolute positioning takes every row out of flow and breaks the sticky
 * day dividers — `position: sticky` needs a normal-flow box to stick within.
 * Padding keeps the rows in flow, so the dividers behave exactly as they did
 * before any of this was windowed.
 *
 * Pending messages stay outside the window. There are only ever a handful, they
 * live at the very bottom where the reader already is, and keeping them in flow
 * means the timeline does not have to model two kinds of row.
 *
 * # The React Compiler warning here is expected
 *
 * `useVirtualizer` returns functions the compiler cannot prove safe to memoize,
 * so it declines to compile this component and says so as a lint warning. That
 * is the trade being made: scrolling now re-renders this component and the
 * dozen or so rows inside the window, where before it re-rendered nothing at
 * all — but "nothing at all" was only true because every message in the channel
 * was already sitting in the document. The expensive work inside each row is
 * still compiled and memoized on its own, so what repeats is reconciliation
 * over a bounded window rather than parsing or formatting.
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
    onOpenThread,
    onReact,
    onTogglePin,
    onToggleSave,
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
    onOpenThread?: (message: Message) => void;
    onReact?: (
        messageId: number,
        emoji: string,
        current: ReactionGroup[],
    ) => void;
    onTogglePin?: (message: Message) => void;
    onToggleSave?: (message: Message) => void;
    /** The channel masthead, which scrolls away with the history. */
    header?: React.ReactNode;
}) {
    const container = useRef<HTMLDivElement>(null);
    const sentinel = useRef<HTMLDivElement>(null);
    const masthead = useRef<HTMLDivElement>(null);

    /** Where the reader was before the last render, for the prepend correction. */
    const anchor = useRef({ top: 0, height: 0, atBottom: true });

    const recordPosition = useCallback(() => {
        const element = container.current;

        if (element === null) {
            return;
        }

        anchor.current = {
            top: element.scrollTop,
            height: element.scrollHeight,
            atBottom:
                element.scrollHeight -
                    element.scrollTop -
                    element.clientHeight <
                BOTTOM_TOLERANCE,
        };
    }, []);

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

            /*
             * The oldest row loaded is only known to start a day once there is
             * nothing older to load. Claiming otherwise is both wrong and
             * expensive: wrong because "Wednesday, July 29" above the oldest
             * message asserts the day begins there when the rest of it is one
             * scroll away, and expensive because the claim is withdrawn the
             * moment that page arrives. The divider disappears, the row loses
             * ~50px, and since all of that happens above the reader the history
             * lurches under them on every page they scroll back through.
             */
            const startsDay =
                previous === null
                    ? !hasMore
                    : previous.created_at === null ||
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
    }, [messages, pending, hasMore]);

    /*
     * How far the windowed rows start below the top of the scrolled content.
     * The load-older sentinel, the spinner, and the masthead all sit above them,
     * each comes and goes, and the masthead's own height depends on how long the
     * channel description is. Observed rather than assumed: get this wrong and
     * the virtualizer maps scroll position to the wrong rows and renders a
     * window that does not cover the viewport.
     */
    const [listOffset, setListOffset] = useState(0);

    useLayoutEffect(() => {
        const element = masthead.current;

        if (element === null) {
            return;
        }

        const measure = () => setListOffset(element.offsetHeight);

        measure();

        const observer = new ResizeObserver(measure);
        observer.observe(element);

        return () => observer.disconnect();
    }, []);

    const virtualizer = useVirtualizer({
        count: rows.sent.length,
        getScrollElement: () => container.current,
        estimateSize: () => ESTIMATED_ROW_HEIGHT,
        overscan: OVERSCAN,
        scrollMargin: listOffset,
        /*
         * Keyed by message id, not by index. Prepending a page shifts every
         * index down, and an index-keyed measurement cache would hand each row
         * the height belonging to a different message — the whole history would
         * appear to change size at once.
         */
        getItemKey: (index) => rows.sent[index].message.id,
        // Hold the reader on the row they were looking at whenever the ends of
        // the list change, which is what makes prepending a page invisible.
        anchorTo: 'end',
        // Following an appended message is handled below instead — see there for
        // why the window's end is not the timeline's end.
        followOnAppend: false,
        scrollEndThreshold: BOTTOM_TOLERANCE,
    });

    const virtualRows = virtualizer.getVirtualItems();
    const totalSize = virtualizer.getTotalSize();
    const { scrollMargin } = virtualizer.options;

    /*
     * The window, expressed as the space it displaces. Item offsets are measured
     * from the top of the scroll container, so the content above the list is
     * subtracted back out — it is already there as real DOM.
     */
    const paddingTop =
        virtualRows.length > 0 ? virtualRows[0].start - scrollMargin : 0;

    const paddingBottom =
        virtualRows.length > 0
            ? totalSize -
              (virtualRows[virtualRows.length - 1].end - scrollMargin)
            : 0;

    /*
     * Open at the newest message. The anchoring above only engages once the list
     * has changed at least once, so the very first paint is this component's to
     * place — and a timeline that opens anywhere other than the bottom is a bug
     * every single time.
     */
    const opened = useRef(false);

    useLayoutEffect(() => {
        if (opened.current || rows.sent.length === 0) {
            return;
        }

        opened.current = true;
        virtualizer.scrollToEnd();

        /*
         * Then the container's own bottom, for the same reason the follow-on-
         * append below does it by hand: `scrollToEnd` lands on the end of the
         * virtual *window*, which sits above the list's bottom padding — so a
         * timeline opened this way stops a little short of its newest message.
         *
         * Deferred by a frame because the rows the virtualizer just revealed are
         * measured after this effect, and an attachment or an image resolves
         * taller than its estimate; reading `scrollHeight` now would pin the
         * view to a height the list is about to exceed.
         */
        const element = container.current;

        if (element !== null) {
            requestAnimationFrame(() => {
                element.scrollTop = element.scrollHeight;
            });
        }
    }, [rows.sent.length, virtualizer]);

    /*
     * Settle the last few pixels of a prepend.
     *
     * `anchorTo` restores the reader by remembering which row they were on,
     * which is the right instinct and is not quite exact: the rows either side
     * of the join change shape when the join disappears — a group that could not
     * see its own beginning now can — and those few tens of pixels land above the
     * reader, so the correction comes up short by the same amount on every page.
     *
     * Total height knows about all of it. Rows outside the window keep their
     * estimate rather than being measured, so the height read here and the height
     * read on the last scroll are the same kind of number, and the difference
     * between them is exactly how much was inserted above.
     */
    const oldestId = rows.sent[0]?.message.id;
    const newestId = rows.sent.at(-1)?.message.id;
    const previousOldestId = useRef(oldestId);

    useLayoutEffect(() => {
        const element = container.current;
        const wasPrepended =
            element !== null &&
            oldestId !== undefined &&
            previousOldestId.current !== undefined &&
            oldestId < previousOldestId.current;

        previousOldestId.current = oldestId;

        if (!wasPrepended) {
            return;
        }

        element.scrollTop =
            anchor.current.top + (element.scrollHeight - anchor.current.height);
    }, [oldestId]);

    /*
     * Follow a new message down, if the reader was already at the bottom.
     *
     * The virtualizer offers `followOnAppend` for this and it stops short here,
     * because it scrolls to the end of the *window* and the window is not the end
     * of the timeline: unsent messages sit below it in ordinary flow, as does the
     * bottom padding. Sending a message therefore left the thing just sent about
     * a row and a half below the fold — the one case where following is least
     * optional.
     *
     * The container's own scroll height has no such blind spot.
     */
    useLayoutEffect(() => {
        const element = container.current;

        if (element === null || !anchor.current.atBottom) {
            return;
        }

        element.scrollTop = element.scrollHeight;
    }, [newestId, pending.length]);

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
    useEffect(() => {
        if (anchor.current.atBottom) {
            onReachBottom();
        }
    }, [newestId, onReachBottom]);

    return (
        <div className="relative flex min-h-0 flex-1 flex-col">
            {/*
             * Overlaid rather than placed in the scrolled content, and that is
             * load-bearing. In flow it sits above the windowed rows, so every
             * appearance and disappearance changes how far down the list starts
             * — by exactly its own height, at exactly the moment a prepended
             * page is landing. The reader sees the history shift by a spinner
             * on every page they scroll back through.
             */}
            {loadingOlder && (
                <div className="pointer-events-none absolute inset-x-0 top-0 z-20 flex justify-center py-3">
                    <Loader2
                        aria-label="Loading earlier messages"
                        className="size-4 animate-spin text-muted-foreground"
                    />
                </div>
            )}

            <div
                ref={container}
                onScroll={recordPosition}
                className="snack-scroll min-h-0 flex-1 overflow-y-auto"
            >
                <div ref={masthead}>
                    {hasMore && (
                        <div
                            ref={sentinel}
                            aria-hidden="true"
                            className="h-px"
                        />
                    )}

                    {!hasMore && header}
                </div>

                <div style={{ height: paddingTop }} />

                {virtualRows.map((row) => {
                    const { message, grouped, startsDay } =
                        rows.sent[row.index];

                    return (
                        <div
                            key={row.key}
                            data-index={row.index}
                            ref={virtualizer.measureElement}
                        >
                            {startsDay && message.created_at !== null && (
                                <DayDivider iso={message.created_at} />
                            )}

                            <MessageItem
                                message={message}
                                grouped={grouped}
                                isOwn={message.sender?.id === currentUserId}
                                onEdit={onEdit}
                                onDelete={onDelete}
                                onOpenThread={onOpenThread}
                                onReact={onReact}
                                onTogglePin={onTogglePin}
                                onToggleSave={onToggleSave}
                                currentUserId={currentUserId}
                            />
                        </div>
                    );
                })}

                <div style={{ height: paddingBottom }} />

                <div className="pb-2">
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
