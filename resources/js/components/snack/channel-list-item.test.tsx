import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { ChannelListItem } from '@/components/snack/channel-list-item';
import type { Channel } from '@/types';

// Inertia's Link needs a running app to resolve routing; for a presentational
// row an anchor is a faithful stand-in and keeps the test to what it asserts.
vi.mock('@inertiajs/react', () => ({
    Link: ({
        children,
        href,
        ...props
    }: {
        children: React.ReactNode;
        href: string | { url: string };
    }) => (
        <a href={typeof href === 'string' ? href : href.url} {...props}>
            {children}
        </a>
    ),
}));

function makeChannel(overrides: Partial<Channel> = {}): Channel {
    return {
        id: 1,
        name: 'general',
        slug: 'general',
        description: null,
        topic: null,
        type: 'public',
        is_private: false,
        is_archived: false,
        is_default: true,
        last_message_at: null,
        is_member: true,
        is_muted: false,
        channel_group_id: null,
        notification_level: 'mentions',
        last_read_message_id: null,
        created_at: null,
        ...overrides,
    };
}

describe('ChannelListItem', () => {
    it('renders the channel name', () => {
        render(
            <ChannelListItem
                channel={makeChannel()}
                workspaceSlug="snack-hq"
                isActive={false}
            />,
        );

        expect(screen.getByText('general')).toBeInTheDocument();
    });

    it('announces the channel type for screen readers', () => {
        render(
            <ChannelListItem
                channel={makeChannel({ type: 'private', is_private: true })}
                workspaceSlug="snack-hq"
                isActive={false}
            />,
        );

        // Knowing a room is private before pasting into it matters.
        expect(screen.getByText('Private channel')).toBeInTheDocument();
    });

    it('marks the active channel as the current page', () => {
        render(
            <ChannelListItem
                channel={makeChannel()}
                workspaceSlug="snack-hq"
                isActive
            />,
        );

        expect(screen.getByRole('link')).toHaveAttribute(
            'aria-current',
            'page',
        );
    });

    it('signals unread messages with weight as well as a dot', () => {
        render(
            <ChannelListItem
                channel={makeChannel()}
                workspaceSlug="snack-hq"
                isActive={false}
                unreadMessages={4}
            />,
        );

        expect(screen.getByRole('link')).toHaveClass('font-semibold');
        expect(screen.getByLabelText('4 unread messages')).toBeInTheDocument();
    });

    it('shows a mention count instead of the unread dot', () => {
        render(
            <ChannelListItem
                channel={makeChannel()}
                workspaceSlug="snack-hq"
                isActive={false}
                unreadMessages={9}
                unreadMentions={2}
            />,
        );

        expect(screen.getByText('2')).toBeInTheDocument();
        expect(screen.getByText('unread mentions')).toBeInTheDocument();
        expect(
            screen.queryByLabelText('9 unread messages'),
        ).not.toBeInTheDocument();
    });

    it('caps a large mention count', () => {
        render(
            <ChannelListItem
                channel={makeChannel()}
                workspaceSlug="snack-hq"
                isActive={false}
                unreadMentions={150}
            />,
        );

        expect(screen.getByText('99+')).toBeInTheDocument();
    });

    it('does not show unread emphasis for a muted channel', () => {
        render(
            <ChannelListItem
                channel={makeChannel({ is_muted: true })}
                workspaceSlug="snack-hq"
                isActive={false}
                unreadMessages={12}
            />,
        );

        expect(screen.getByRole('link')).not.toHaveClass('font-semibold');
        expect(screen.getByLabelText('Muted')).toBeInTheDocument();
    });

    it('still surfaces mentions in a muted channel', () => {
        render(
            <ChannelListItem
                channel={makeChannel({ is_muted: true })}
                workspaceSlug="snack-hq"
                isActive={false}
                unreadMessages={12}
                unreadMentions={1}
            />,
        );

        // Muting silences ordinary traffic, never a direct mention.
        expect(screen.getByText('unread mentions')).toBeInTheDocument();
    });

    it('links to the channel within its workspace', () => {
        render(
            <ChannelListItem
                channel={makeChannel({ slug: 'engineering' })}
                workspaceSlug="snack-hq"
                isActive={false}
            />,
        );

        expect(screen.getByRole('link')).toHaveAttribute(
            'href',
            '/w/snack-hq/channels/engineering',
        );
    });

    it('is only draggable when there is somewhere to drop it', () => {
        const { rerender } = render(
            <ChannelListItem
                channel={makeChannel()}
                workspaceSlug="snack-hq"
                isActive={false}
            />,
        );

        // A row that lifts off the page with no sections to file it into
        // promises a rearrangement that cannot happen.
        expect(screen.getByRole('link')).toHaveAttribute('draggable', 'false');

        rerender(
            <ChannelListItem
                channel={makeChannel()}
                workspaceSlug="snack-hq"
                isActive={false}
                draggable
            />,
        );

        expect(screen.getByRole('link')).toHaveAttribute('draggable', 'true');
    });

    it('carries the channel id in the drag payload', () => {
        const setData = vi.fn();
        const onDragStart = vi.fn();

        render(
            <ChannelListItem
                channel={makeChannel({ id: 42 })}
                workspaceSlug="snack-hq"
                isActive={false}
                draggable
                onDragStart={onDragStart}
            />,
        );

        fireEvent.dragStart(screen.getByRole('link'), {
            dataTransfer: { setData, effectAllowed: 'none' },
        });

        // The id travels in the payload as well as in React state — a link is
        // natively dragged as its URL, so the payload has to be overwritten.
        expect(setData).toHaveBeenCalledWith('text/plain', '42');
        expect(onDragStart).toHaveBeenCalledWith(
            expect.objectContaining({ id: 42 }),
        );
    });
});
