import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowRight,
    AtSign,
    Bell,
    Files,
    Hash,
    Lock,
    MessagesSquare,
    Search,
    Send,
    Smile,
} from 'lucide-react';
import { SnackLogo, SnackLogoIcon } from '@/components/snack/snack-logo';
import { Button } from '@/components/ui/button';
import { dashboard, login } from '@/routes';

const FEATURES = [
    {
        icon: Hash,
        title: 'Channels that stay readable',
        body: 'Public, private, and announcement-only channels. Unread markers remember where you stopped, so coming back after a day off is not an archaeology exercise.',
    },
    {
        icon: MessagesSquare,
        title: 'Threads that stay put',
        body: 'Replies live beside the message that started them instead of shoving it up the channel. Follow the ones you care about and ignore the rest.',
    },
    {
        icon: Send,
        title: 'Direct and group messages',
        body: 'One-to-one or a huddle of six, on the same message pipeline as channels — same editing, same search, same files, no second-class inbox.',
    },
    {
        icon: Search,
        title: 'Search that finds it',
        body: 'Full-text search across every message and file, narrowed by from:, in:, has:file, and date. Results come back with the line highlighted.',
    },
    {
        icon: Files,
        title: 'Files where you left them',
        body: 'Drag anything in. Images get thumbnails, everything gets indexed, and the file browser lists the lot without you scrolling back through chat.',
    },
    {
        icon: Bell,
        title: 'Notifications with a volume knob',
        body: 'Per-channel levels, mute that still lets a direct mention through, quiet hours, and email only when you are genuinely away.',
    },
] as const;

const SAMPLE_MESSAGES = [
    {
        author: 'Ada',
        initials: 'A',
        time: '9:41',
        body: 'Ship notes for the release are in #announcements — worth a skim before standup.',
        tint: 'bg-brand text-white',
    },
    {
        author: 'Grace',
        initials: 'G',
        time: '9:43',
        body: 'Reading now. The migration section answered the question I was about to ask.',
        tint: 'bg-emerald-500 text-white',
    },
    {
        author: 'Alan',
        initials: 'A',
        time: '9:44',
        body: 'Same. Threading the follow-ups so we keep the channel clean.',
        tint: 'bg-violet-500 text-white',
    },
] as const;

/**
 * The public landing page.
 *
 * Only ever seen by signed-out visitors on an installation that has already
 * been set up — {@link EnsureInstanceIsSetUp} redirects to the setup wizard
 * while no account exists, so this page never has to render a "nothing here
 * yet" state.
 *
 * There is no sign-up call to action anywhere on it, and that is deliberate:
 * Snack is invitation-first, so a "get started" button would lead to a route
 * that deliberately 404s. The honest CTA is to sign in, or to go and find your
 * invitation email.
 */
export default function Welcome() {
    const { auth } = usePage().props;

    return (
        <>
            <Head title="Snack — small chats, big work" />

            <div className="min-h-dvh bg-background text-foreground">
                <header className="mx-auto flex w-full max-w-6xl items-center justify-between px-6 py-6">
                    <SnackLogo />

                    <nav className="flex items-center gap-2">
                        {auth.user ? (
                            <Button asChild>
                                <Link href={dashboard()}>
                                    Open Snack
                                    <ArrowRight aria-hidden="true" />
                                </Link>
                            </Button>
                        ) : (
                            <Button asChild variant="ghost">
                                <Link href={login()}>Sign in</Link>
                            </Button>
                        )}
                    </nav>
                </header>

                <main>
                    {/* Hero */}
                    <section className="relative overflow-hidden">
                        <div
                            aria-hidden="true"
                            className="pointer-events-none absolute inset-x-0 -top-40 h-96 bg-gradient-to-b from-brand-subtle to-transparent opacity-70 blur-3xl"
                        />

                        <div className="relative mx-auto grid w-full max-w-6xl gap-12 px-6 py-16 lg:grid-cols-2 lg:items-center lg:py-24">
                            <div>
                                <p className="inline-flex items-center gap-2 rounded-full border border-border bg-card px-3 py-1 text-sm text-muted-foreground">
                                    <Lock
                                        aria-hidden="true"
                                        className="size-3.5"
                                    />
                                    Self-hosted, invite-only
                                </p>

                                <h1 className="mt-6 text-4xl font-bold tracking-tight text-balance sm:text-5xl lg:text-6xl">
                                    Small chats.{' '}
                                    <span className="text-brand">
                                        Big work.
                                    </span>
                                </h1>

                                <p className="mt-6 max-w-xl text-lg text-pretty text-muted-foreground">
                                    Snack is a team chat app you run yourself.
                                    Channels, direct messages, threads, search,
                                    and files — on your own hardware, with your
                                    own data, and nobody metering your message
                                    history.
                                </p>

                                <div className="mt-8 flex flex-wrap items-center gap-3">
                                    {auth.user ? (
                                        <Button asChild size="lg">
                                            <Link href={dashboard()}>
                                                Open your workspace
                                                <ArrowRight aria-hidden="true" />
                                            </Link>
                                        </Button>
                                    ) : (
                                        <>
                                            <Button asChild size="lg">
                                                <Link href={login()}>
                                                    Sign in
                                                    <ArrowRight aria-hidden="true" />
                                                </Link>
                                            </Button>

                                            <p className="text-sm text-muted-foreground">
                                                Need an account? Ask a workspace
                                                admin for an invitation.
                                            </p>
                                        </>
                                    )}
                                </div>
                            </div>

                            {/* A still of the product rather than a stock illustration. */}
                            <div className="relative">
                                <div className="overflow-hidden rounded-2xl border border-border bg-card shadow-xl">
                                    <div className="flex items-center gap-2 border-b border-border px-4 py-3">
                                        <Hash
                                            aria-hidden="true"
                                            className="size-4 text-muted-foreground"
                                        />
                                        <span className="text-sm font-semibold">
                                            general
                                        </span>
                                        <span className="ml-auto text-xs text-muted-foreground">
                                            3 online
                                        </span>
                                    </div>

                                    <ul className="divide-y divide-border/50">
                                        {SAMPLE_MESSAGES.map((message) => (
                                            <li
                                                key={message.author}
                                                className="flex gap-3 px-4 py-3.5"
                                            >
                                                <span
                                                    className={`flex size-8 shrink-0 items-center justify-center rounded-lg text-sm font-semibold ${message.tint}`}
                                                >
                                                    {message.initials}
                                                </span>

                                                <div className="min-w-0">
                                                    <p className="flex items-baseline gap-2">
                                                        <span className="text-sm font-semibold">
                                                            {message.author}
                                                        </span>
                                                        <span className="text-xs text-muted-foreground">
                                                            {message.time}
                                                        </span>
                                                    </p>
                                                    <p className="mt-0.5 text-sm text-pretty text-muted-foreground">
                                                        {message.body}
                                                    </p>
                                                </div>
                                            </li>
                                        ))}
                                    </ul>

                                    <div className="flex items-center gap-2 border-t border-border px-4 py-3 text-sm text-muted-foreground">
                                        <AtSign
                                            aria-hidden="true"
                                            className="size-4"
                                        />
                                        <Smile
                                            aria-hidden="true"
                                            className="size-4"
                                        />
                                        <span>Message #general</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>

                    {/* Features */}
                    <section className="mx-auto w-full max-w-6xl px-6 pt-4 pb-16 lg:pt-8 lg:pb-24">
                        <h2 className="max-w-2xl text-3xl font-bold tracking-tight text-balance">
                            Everything a working team needs, and not much else.
                        </h2>

                        <div className="mt-12 grid gap-8 sm:grid-cols-2 lg:grid-cols-3">
                            {FEATURES.map((feature) => (
                                <div key={feature.title}>
                                    <span className="flex size-10 items-center justify-center rounded-xl bg-brand-subtle text-brand">
                                        <feature.icon
                                            aria-hidden="true"
                                            className="size-5"
                                        />
                                    </span>

                                    <h3 className="mt-4 font-semibold">
                                        {feature.title}
                                    </h3>

                                    <p className="mt-2 text-sm text-pretty text-muted-foreground">
                                        {feature.body}
                                    </p>
                                </div>
                            ))}
                        </div>
                    </section>

                    {/* Closing */}
                    <section className="mx-auto w-full max-w-6xl px-6 pb-16 lg:pb-24">
                        <div className="rounded-3xl border border-border bg-card px-6 py-12 text-center sm:px-12">
                            <SnackLogoIcon className="mx-auto size-10 text-brand" />

                            <h2 className="mx-auto mt-6 max-w-xl text-3xl font-bold tracking-tight text-balance">
                                Your team, your server, your history.
                            </h2>

                            <p className="mx-auto mt-4 max-w-xl text-pretty text-muted-foreground">
                                Snack keeps every message, file, and thread on
                                infrastructure you control. No seat pricing, no
                                message limits, no third party reading the room.
                            </p>

                            <div className="mt-8">
                                <Button asChild size="lg">
                                    <Link
                                        href={auth.user ? dashboard() : login()}
                                    >
                                        {auth.user
                                            ? 'Open your workspace'
                                            : 'Sign in'}
                                        <ArrowRight aria-hidden="true" />
                                    </Link>
                                </Button>
                            </div>
                        </div>
                    </section>
                </main>

                <footer className="border-t border-border">
                    <div className="mx-auto flex w-full max-w-6xl flex-col items-center justify-between gap-4 px-6 py-8 sm:flex-row">
                        <SnackLogo />

                        <p className="text-sm text-muted-foreground">
                            Small chats. Big work.
                        </p>
                    </div>
                </footer>
            </div>
        </>
    );
}
