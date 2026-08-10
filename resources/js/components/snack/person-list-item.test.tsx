import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { PersonListItem } from '@/components/snack/person-list-item';
import type { Conversation, SnackUser, WorkspacePerson } from '@/types';

/*
 * Inertia's Link needs a running app to resolve routing. The stand-in flattens
 * the href — Wayfinder hands it a { url, method } pair for the POST shape — and
 * the payload onto data attributes, which is exactly what these tests are here
 * to assert: where the row goes, and how.
 */
vi.mock('@inertiajs/react', () => ({
    Link: ({
        children,
        href,
        method,
        data,
        className,
        'aria-current': ariaCurrent,
    }: {
        children: React.ReactNode;
        href: string | { url: string; method?: string };
        method?: string;
        data?: Record<string, unknown>;
        className?: string;
        'aria-current'?: 'page';
    }) => (
        <a
            href={typeof href === 'string' ? href : href.url}
            className={className}
            aria-current={ariaCurrent}
            data-method={
                typeof href === 'string'
                    ? (method ?? 'get')
                    : (href.method ?? 'get')
            }
            data-payload={data === undefined ? undefined : JSON.stringify(data)}
        >
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

const bo = makeUser(2, 'Bo');

function makeConversation(overrides: Partial<Conversation> = {}): Conversation {
    return {
        id: 'conv_01HZX',
        type: 'direct',
        name: null,
        display_name: 'Bo',
        participants: [makeUser(1, 'Ada'), bo],
        last_message_at: null,
        is_muted: false,
        notification_level: 'all',
        last_read_message_id: null,
        has_left: false,
        created_at: null,
        ...overrides,
    };
}

function makePerson(conversation: Conversation | null): WorkspacePerson {
    return { user: bo, is_bot: false, conversation };
}

describe('PersonListItem', () => {
    it('opens a person you have never messaged by posting to find-or-create', () => {
        render(
            <PersonListItem
                person={makePerson(null)}
                workspaceSlug="snack-hq"
                isActive={false}
            />,
        );

        const row = screen.getByRole('link', { name: /Bo/ });

        /*
         * The point of the roster: the row exists before the thread does, and
         * the click is what creates it. A GET here would mean a conversation
         * row per member the moment the sidebar rendered — or, with prefetch,
         * per member the pointer passed over.
         */
        expect(row).toHaveAttribute('data-method', 'post');
        expect(row).toHaveAttribute('href', '/w/snack-hq/dms');
        expect(row).toHaveAttribute(
            'data-payload',
            JSON.stringify({ participant_ids: [2] }),
        );
    });

    it('links straight to the conversation once there is one', () => {
        render(
            <PersonListItem
                person={makePerson(makeConversation())}
                workspaceSlug="snack-hq"
                isActive={false}
            />,
        );

        const row = screen.getByRole('link', { name: /Bo/ });

        expect(row).toHaveAttribute('data-method', 'get');
        expect(row).toHaveAttribute('href', '/w/snack-hq/dms/conv_01HZX');
    });

    it('badges the unread messages of the conversation behind the person', () => {
        render(
            <PersonListItem
                person={makePerson(makeConversation())}
                workspaceSlug="snack-hq"
                isActive={false}
                unreadMessages={4}
            />,
        );

        expect(screen.getByText('4')).toBeInTheDocument();
    });

    it('drops the badge when the conversation is muted', () => {
        render(
            <PersonListItem
                person={makePerson(makeConversation({ is_muted: true }))}
                workspaceSlug="snack-hq"
                isActive={false}
                unreadMessages={4}
            />,
        );

        // Muting is a request not to be told; the bell says why the count is
        // gone rather than leaving the row looking like nothing arrived.
        expect(screen.queryByText('4')).not.toBeInTheDocument();
        expect(screen.getByLabelText('Muted')).toBeInTheDocument();
    });

    it('marks the open conversation as the current page', () => {
        render(
            <PersonListItem
                person={makePerson(makeConversation())}
                workspaceSlug="snack-hq"
                isActive
            />,
        );

        expect(screen.getByRole('link', { name: /Bo/ })).toHaveAttribute(
            'aria-current',
            'page',
        );
    });
});
