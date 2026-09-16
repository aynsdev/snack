import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { RemoveChannelMemberButton } from '@/components/snack/remove-channel-member-button';
import type { Channel, ChannelMembership } from '@/types';

const inertia = vi.hoisted(() => ({ delete: vi.fn() }));

vi.mock('@inertiajs/react', () => ({ router: inertia }));

const channel = {
    id: 4,
    name: 'Design',
    slug: 'design',
} as Channel;

const membership = {
    id: 12,
    role: 'member',
    joined_at: null,
    user: {
        id: 9,
        name: 'Ada Lovelace',
        username: 'ada',
        initials: 'AL',
        avatar_url: null,
        title: null,
        department: null,
        presence_status: 'offline',
        status_emoji: null,
        status_message: null,
    },
} satisfies ChannelMembership;

beforeEach(() => inertia.delete.mockReset());

describe('RemoveChannelMemberButton', () => {
    it('confirms before removing the selected membership', async () => {
        const user = userEvent.setup();

        render(
            <RemoveChannelMemberButton
                channel={channel}
                membership={membership}
                workspaceSlug="acme"
            />,
        );

        await user.click(
            screen.getByRole('button', {
                name: 'Remove Ada Lovelace from channel',
            }),
        );

        expect(screen.getByRole('alertdialog')).toBeInTheDocument();
        expect(inertia.delete).not.toHaveBeenCalled();

        await user.click(screen.getByRole('button', { name: 'Remove member' }));

        expect(inertia.delete).toHaveBeenCalledWith(
            '/w/acme/channels/design/members/12',
            expect.objectContaining({ preserveScroll: true }),
        );
    });
});
