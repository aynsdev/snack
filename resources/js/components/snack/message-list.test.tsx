import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { MessageList } from '@/components/snack/message-list';
import type { Message, PendingMessage, SnackUser } from '@/types';

/** The height jsdom will claim every box has, viewport and rows alike. */
const BOX_HEIGHT = 600;

/*
 * jsdom performs no layout, so every element reports a zero-height box. A
 * virtualizer handed a zero-height viewport correctly concludes that no row is
 * visible and renders nothing at all — which would make every assertion below
 * pass or fail for reasons that have nothing to do with what it is testing.
 *
 * The virtualizer sizes its viewport from the scroll element's `offsetHeight`
 * and each row from `getBoundingClientRect`, so both are faked. The viewport
 * stub is deliberately scoped to the scroll container: the component measures
 * the masthead above the list with `offsetHeight` too, and a non-zero value
 * there would push the whole list past the fake viewport and put us straight
 * back to rendering nothing.
 */
beforeEach(() => {
    vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockReturnValue({
        width: 800,
        height: BOX_HEIGHT,
        top: 0,
        left: 0,
        bottom: BOX_HEIGHT,
        right: 800,
        x: 0,
        y: 0,
        toJSON: () => ({}),
    });

    vi.spyOn(HTMLElement.prototype, 'offsetHeight', 'get').mockImplementation(
        function (this: HTMLElement) {
            return this.classList.contains('snack-scroll') ? BOX_HEIGHT : 0;
        },
    );
});

const ADA: SnackUser = {
    id: 1,
    name: 'Ada Lovelace',
    username: 'ada',
    initials: 'AL',
    avatar_url: null,
    title: null,
    department: null,
    presence_status: 'online',
    status_emoji: null,
    status_message: null,
};

function makeMessage(overrides: Partial<Message> = {}): Message {
    return {
        id: 1,
        workspace_id: 1,
        channel_id: 1,
        conversation_id: null,
        parent_message_id: null,
        body: 'Hello.',
        type: 'text',
        sender: ADA,
        reply_count: 0,
        last_reply_at: null,
        last_reply_id: null,
        reactions: [],
        is_pinned: false,
        has_attachments: false,
        attachments: [],
        is_edited: false,
        edited_at: null,
        created_at: '2026-07-31T09:00:00+00:00',
        ...overrides,
    };
}

function makePending(overrides: Partial<PendingMessage> = {}): PendingMessage {
    return {
        nonce: 'nonce-1',
        body: 'Going out now.',
        created_at: '2026-07-31T09:05:00+00:00',
        sender: ADA,
        failed: false,
        attachmentCount: 0,
        ...overrides,
    } as PendingMessage;
}

function renderList(props: Partial<Parameters<typeof MessageList>[0]> = {}) {
    return render(
        <MessageList
            messages={[makeMessage()]}
            pending={[]}
            hasMore={false}
            loadingOlder={false}
            currentUserId={1}
            onLoadOlder={vi.fn()}
            onEdit={vi.fn()}
            onDelete={vi.fn()}
            onRetry={vi.fn()}
            onDiscard={vi.fn()}
            onReachBottom={vi.fn()}
            {...props}
        />,
    );
}

/**
 * The windowed timeline.
 *
 * jsdom has no layout — every box measures zero — so this cannot assert which
 * rows the window chooses. What it can assert is everything that has to survive
 * windowing regardless of measurement: that a small history renders in full
 * rather than collapsing to nothing, that day dividers and grouping still land
 * where the grouping pass put them, and that unsent messages stay outside the
 * window and therefore always render.
 *
 * That last one is the regression worth guarding. With a zero-height viewport a
 * virtualizer renders only its overscan, so anything moved *into* the window by
 * a later change would silently stop appearing here.
 */
describe('MessageList', () => {
    it('aligns the viewer’s messages and pending sends right and other authors left', () => {
        renderList({
            messages: [
                makeMessage({ id: 1, body: 'My message' }),
                makeMessage({
                    id: 2,
                    body: 'Their message',
                    sender: { ...ADA, id: 2, name: 'Grace' },
                }),
            ],
            pending: [makePending({ body: 'Sending mine' })],
        });

        expect(screen.getByText('My message').closest('article')).toHaveClass(
            'flex-row-reverse',
        );
        expect(
            screen.getByText('Their message').closest('article'),
        ).not.toHaveClass('flex-row-reverse');
        expect(screen.getByText('Sending mine').closest('article')).toHaveClass(
            'flex-row-reverse',
        );
    });

    it('renders a short history in full', () => {
        renderList({
            messages: [
                makeMessage({ id: 1, body: 'First.' }),
                makeMessage({ id: 2, body: 'Second.' }),
                makeMessage({ id: 3, body: 'Third.' }),
            ],
        });

        expect(screen.getByText('First.')).toBeInTheDocument();
        expect(screen.getByText('Second.')).toBeInTheDocument();
        expect(screen.getByText('Third.')).toBeInTheDocument();
    });

    it('divides the history by day', () => {
        renderList({
            messages: [
                makeMessage({
                    id: 1,
                    body: 'Yesterday.',
                    created_at: '2026-07-30T09:00:00+00:00',
                }),
                makeMessage({
                    id: 2,
                    body: 'Today.',
                    created_at: '2026-07-31T09:00:00+00:00',
                }),
            ],
        });

        // Two messages a day apart cannot share a group, so both dividers render
        // and each message keeps its own author header.
        expect(screen.getAllByText('Ada Lovelace')).toHaveLength(2);
        expect(screen.getByText('Thursday, July 30').tagName).toBe('TIME');
        expect(screen.getByText('Friday, July 31').tagName).toBe('TIME');
    });

    it('keeps unsent messages outside the window', () => {
        renderList({
            messages: [makeMessage({ id: 1, body: 'Sent.' })],
            pending: [makePending({ body: 'Not yet sent.' })],
        });

        expect(screen.getByText('Not yet sent.')).toBeInTheDocument();
    });

    it('renders an empty timeline without a history', () => {
        const { container } = renderList({ messages: [] });

        expect(container.querySelector('.snack-scroll')).not.toBeNull();
        expect(screen.queryByText('Hello.')).not.toBeInTheDocument();
    });

    /*
     * The timeline corrects its own scroll position in three places — following
     * an arrival, settling a prepend, and the virtualizer's end anchoring — and
     * every one of them reads `scrollTop` and `scrollHeight` assuming nothing
     * else has moved them. Browser scroll anchoring moves them, on every row
     * that enters or leaves the window. jsdom performs no layout and so cannot
     * show the resulting drift; what it can hold is the declaration that turns
     * the browser's correction off.
     */
    it('leaves scroll corrections to the timeline, not the browser', () => {
        const { container } = renderList();

        expect(container.querySelector('.snack-scroll')).toHaveClass(
            '[overflow-anchor:none]',
        );
    });

    /*
     * Opening a container is the commonest way a badge gets cleared, so the
     * list has to report the read on mount and not only when the next message
     * arrives.
     */
    it('reports the read pointer as soon as it opens', () => {
        const onReachBottom = vi.fn();

        renderList({ onReachBottom });

        expect(onReachBottom).toHaveBeenCalled();
    });

    /*
     * And again when the reader scrolls back down. Someone who walked up
     * through unread history has read it; before this, the pointer only moved
     * when a *new* message arrived while they happened to be at the bottom, so
     * the badge stayed lit until somebody else said something.
     */
    it('reports the read pointer when the reader scrolls back to the bottom', () => {
        const onReachBottom = vi.fn();

        const { container } = renderList({ onReachBottom });

        const scroller = container.querySelector('.snack-scroll');

        if (scroller === null) {
            throw new Error('The timeline rendered no scroll container.');
        }

        onReachBottom.mockClear();

        // jsdom performs no layout, so the bottom is where a zero scroll height
        // and the faked viewport put it: `scrollTop` of zero already qualifies.
        fireEvent.scroll(scroller);

        expect(onReachBottom).toHaveBeenCalled();
    });
});
