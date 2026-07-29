import { Head, Link, router, setLayoutProps } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { login, register } from '@/routes';
import { accept } from '@/routes/invitations';

type Props = {
    token: string;
    workspace: {
        name: string;
        initials: string;
        logo_url: string | null;
        description: string | null;
    };
    invitedBy: string;
    email: string;
    role: string;
    expiresAt: string;
    isAuthenticated: boolean;
    /** False when signed in as someone other than the invited address. */
    emailMatches: boolean;
};

/**
 * The invitation landing page.
 *
 * Reachable while signed out, because someone holding an invitation needs to
 * see what they were invited to before deciding to create an account. Only the
 * workspace name, the inviter, and the invited address are revealed — all
 * things the recipient already knows from the email that brought them here.
 *
 * Renders inside the shared auth layout, which supplies the Snack mark and the
 * centred column. The heading goes through `setLayoutProps` because it names
 * the workspace and so cannot be a static layout value.
 */
export default function Invitation({
    token,
    workspace,
    invitedBy,
    email,
    role,
    expiresAt,
    isAuthenticated,
    emailMatches,
}: Props) {
    setLayoutProps({
        title: `${invitedBy} invited you to ${workspace.name}`,
        description: workspace.description ?? 'Small chats. Big work.',
    });

    return (
        <>
            <Head title={`Join ${workspace.name}`} />

            <div className="space-y-6">
                <div className="flex justify-center">
                    <span className="flex size-16 items-center justify-center overflow-hidden rounded-2xl bg-brand-subtle text-lg font-bold text-foreground">
                        {workspace.logo_url ? (
                            <img
                                src={workspace.logo_url}
                                alt=""
                                className="size-full object-cover"
                            />
                        ) : (
                            workspace.initials
                        )}
                    </span>
                </div>

                <dl className="space-y-1.5 rounded-xl border border-border p-4 text-sm text-muted-foreground">
                    <div className="flex justify-between gap-4">
                        <dt>Invited address</dt>
                        <dd className="truncate font-medium text-foreground">
                            {email}
                        </dd>
                    </div>
                    <div className="flex justify-between gap-4">
                        <dt>Role</dt>
                        <dd className="font-medium text-foreground">{role}</dd>
                    </div>
                    <div className="flex justify-between gap-4">
                        <dt>Expires</dt>
                        <dd className="font-medium text-foreground">
                            {new Date(expiresAt).toLocaleDateString()}
                        </dd>
                    </div>
                </dl>

                {!isAuthenticated ? (
                    <SignedOutActions />
                ) : emailMatches ? (
                    <Button
                        className="w-full"
                        onClick={() => router.post(accept(token))}
                    >
                        Join {workspace.name}
                    </Button>
                ) : (
                    <WrongAccountNotice email={email} />
                )}
            </div>
        </>
    );
}

function SignedOutActions() {
    return (
        <div className="space-y-3 text-center">
            <Button asChild className="w-full">
                <Link href={register()}>Create an account</Link>
            </Button>

            <p className="text-sm text-muted-foreground">
                Already have one?{' '}
                <Link
                    href={login()}
                    className="font-medium text-foreground underline underline-offset-4"
                >
                    Sign in
                </Link>
            </p>
        </div>
    );
}

/**
 * Invitations are bound to the address they were sent to, so a forwarded link
 * cannot onboard the wrong person. Say so plainly instead of failing at submit.
 */
function WrongAccountNotice({ email }: { email: string }) {
    return (
        <div className="space-y-3 text-center">
            <p className="text-sm text-pretty text-muted-foreground">
                This invitation was sent to <strong>{email}</strong>. Sign in
                with that address to accept it.
            </p>

            <Button asChild variant="outline" className="w-full">
                <Link href={login()}>Switch account</Link>
            </Button>
        </div>
    );
}
