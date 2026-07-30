import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { ConversationListItem } from '@/components/snack/conversation-list-item';
import type { Conversation, SnackUser } from '@/types';

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

function makeUser(id: number, name: string): SnackUser {
    return {
        id,
        name,
        username: name.toLowerCase(),
        initials: name.slice(0, 1),
        avatar_url: null,
        title: null,
        department: null,
        presence_status: 'online',
        status_emoji: null,
        status_message: null,
    };
}

const viewer = makeUser(1, 'Ada');
const bo = makeUser(2, 'Bo');
const cy = makeUser(3, 'Cy');

function makeConversation(overrides: Partial<Conversation> = {}): Conversation {
    return {
        id: 'conv_01HZX',
        type: 'direct',
        name: null,
        display_name: 'Bo',
        participants: [viewer, bo],
        last_message_at: null,
        is_muted: false,
        notification_level: 'all',
        last_read_message_id: null,
        has_left: false,
        created_at: null,
        ...overrides,
    };
}

describe('ConversationListItem', () => {
    it('renders the display name the server computed for this viewer', () => {
        render(
            <ConversationListItem
                conversation={makeConversation()}
                workspaceSlug="snack-hq"
                viewerId={viewer.id}
                isActive={false}
            />,
        );

        expect(screen.getByText('Bo')).toBeInTheDocument();
    });

    it('links to the conversation within its workspace', () => {
        render(
            <ConversationListItem
                conversation={makeConversation({ id: 'conv_42' })}
                workspaceSlug="snack-hq"
                viewerId={viewer.id}
                isActive={false}
            />,
        );

        expect(screen.getByRole('link')).toHaveAttribute(
            'href',
            '/w/snack-hq/dms/conv_42',
        );
    });

    it('marks the active conversation as the current page', () => {
        render(
            <ConversationListItem
                conversation={makeConversation()}
                workspaceSlug="snack-hq"
                viewerId={viewer.id}
                isActive
            />,
        );

        expect(screen.getByRole('link')).toHaveAttribute(
            'aria-current',
            'page',
        );
    });

    it('signals unread messages with weight as well as a count', () => {
        render(
            <ConversationListItem
                conversation={makeConversation()}
                workspaceSlug="snack-hq"
                viewerId={viewer.id}
                isActive={false}
                unreadMessages={3}
            />,
        );

        // Two signals, because weight alone is not something everyone can see.
        expect(screen.getByRole('link')).toHaveClass('font-semibold');
        expect(screen.getByText('3')).toBeInTheDocument();
    });

    it('caps a large unread count', () => {
        render(
            <ConversationListItem
                conversation={makeConversation()}
                workspaceSlug="snack-hq"
                viewerId={viewer.id}
                isActive={false}
                unreadMessages={150}
            />,
        );

        expect(screen.getByText('99+')).toBeInTheDocument();
    });

    it('drops unread emphasis for a muted conversation', () => {
        render(
            <ConversationListItem
                conversation={makeConversation({ is_muted: true })}
                workspaceSlug="snack-hq"
                viewerId={viewer.id}
                isActive={false}
                unreadMessages={9}
            />,
        );

        expect(screen.getByRole('link')).not.toHaveClass('font-semibold');
        expect(screen.getByLabelText('Muted')).toBeInTheDocument();
    });

    it('shows the other person’s presence on a one-to-one row', () => {
        render(
            <ConversationListItem
                conversation={makeConversation()}
                workspaceSlug="snack-hq"
                viewerId={viewer.id}
                isActive={false}
            />,
        );

        expect(screen.getByText('Active')).toBeInTheDocument();
    });

    it('does not claim a group is online', () => {
        render(
            <ConversationListItem
                conversation={makeConversation({
                    type: 'group',
                    display_name: 'Bo, Cy',
                    participants: [viewer, bo, cy],
                })}
                workspaceSlug="snack-hq"
                viewerId={viewer.id}
                isActive={false}
            />,
        );

        // "Is this group away?" is not a question, so no presence is rendered.
        expect(screen.queryByText('Active')).not.toBeInTheDocument();
    });

    it('still renders when everyone else has left the group', () => {
        render(
            <ConversationListItem
                conversation={makeConversation({
                    type: 'group',
                    display_name: 'Just you',
                    participants: [viewer],
                })}
                workspaceSlug="snack-hq"
                viewerId={viewer.id}
                isActive={false}
            />,
        );

        // Rare, but a row that throws here would take the whole sidebar with it.
        expect(screen.getByText('Just you')).toBeInTheDocument();
    });
});
