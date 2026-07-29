import { Head, Link, setLayoutProps } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { home } from '@/routes';

type Props = {
    reason: 'invalid' | 'expired' | 'revoked' | 'accepted' | 'pending';
};

const MESSAGES: Record<
    Props['reason'],
    { title: string; description: string }
> = {
    invalid: {
        title: 'This invitation link is not valid',
        description:
            'The link may have been mistyped or truncated. Ask whoever invited you to send a fresh one.',
    },
    expired: {
        title: 'This invitation has expired',
        description:
            'Invitations are only valid for a short window. Ask an admin to send you a new one.',
    },
    revoked: {
        title: 'This invitation was withdrawn',
        description:
            'An admin revoked this invitation. Get in touch with them if you think that was a mistake.',
    },
    accepted: {
        title: 'This invitation has already been used',
        description:
            'Sign in with the account you created, or ask for a new invitation.',
    },
    pending: {
        title: 'This invitation is not ready yet',
        description: 'Try the link from your email again in a moment.',
    },
};

/**
 * The dead end for a link that cannot be redeemed.
 *
 * The reason is stated plainly — expired, revoked, already used — because
 * "invalid link" leaves people retrying something that will never work.
 */
export default function InvitationInvalid({ reason }: Props) {
    const { title, description } = MESSAGES[reason] ?? MESSAGES.invalid;

    setLayoutProps({ title, description });

    return (
        <>
            <Head title="Invitation unavailable" />

            <Button asChild variant="outline" className="w-full">
                <Link href={home()}>Back to Snack</Link>
            </Button>
        </>
    );
}
