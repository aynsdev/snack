import { Head, Link, usePage } from '@inertiajs/react';
import {
    Activity,
    ArrowRight,
    AtSign,
    Bell,
    Bookmark,
    ChartNoAxesColumn,
    Columns3,
    FileText,
    Files,
    Hash,
    HardDrive,
    Headphones,
    Images,
    Inbox,
    KeyRound,
    LayoutGrid,
    ListTodo,
    Lock,
    Mail,
    MessageCircle,
    MessagesSquare,
    Moon,
    Palette,
    Paperclip,
    Pin,
    Radio,
    Rocket,
    ScrollText,
    Search,
    Send,
    Server,
    ShieldCheck,
    Smile,
    Sunrise,
    Tag,
    Timer,
    Type,
    UserPlus,
    Webhook,
    Zap,
} from 'lucide-react';
import { SnackLogo, SnackLogoIcon } from '@/components/snack/snack-logo';
import { Button } from '@/components/ui/button';
import { dashboard, login } from '@/routes';
import { deleteAccount, privacy, terms } from '@/routes/legal';

type Feature = {
    icon: typeof Hash;
    title: string;
    body: string;
};

type Section = {
    id: string;
    eyebrow: string;
    title: string;
    features: readonly Feature[];
};

/**
 * Everything the application does, grouped the way somebody evaluating it would
 * ask about it.
 *
 * # Why the copy is this short
 *
 * A landing page is skimmed, not read. Every feature here gets a name and one
 * line, because a visitor deciding whether to keep scrolling is asking "does it
 * do X" — and a paragraph explaining *why* it does X is an answer to a question
 * they have not asked yet. The product itself is where the reasoning lives.
 *
 * The line is a claim, not a slogan: it says what the thing is, so the list can
 * be scanned top to bottom without any of it needing a second read.
 *
 * Every entry ships. A feature list is a promise the first five minutes of using
 * the product get checked against, so anything aspirational belongs in a
 * roadmap, not here.
 */
const SECTIONS: readonly Section[] = [
    {
        id: 'conversation',
        eyebrow: 'Conversation',
        title: 'Talking, without losing the plot',
        features: [
            {
                icon: Hash,
                title: 'Channels',
                body: 'Public, private, or announcement-only. Browse, join, archive.',
            },
            {
                icon: MessagesSquare,
                title: 'Threads',
                body: 'Replies stay beside the message. One inbox for the ones you follow.',
            },
            {
                icon: Send,
                title: 'DMs and group DMs',
                body: 'One-to-one or a group, renameable, people added as you go.',
            },
            {
                icon: Type,
                title: 'Formatting',
                body: 'Markdown or a toolbar. Code blocks, quotes, lists. Edit and delete.',
            },
            {
                icon: Smile,
                title: 'Emoji and reactions',
                body: 'React without adding noise. Type :rocket: and get 🚀.',
            },
            {
                icon: AtSign,
                title: 'Mentions',
                body: 'A person or the whole channel — and it reaches you through a mute.',
            },
            {
                icon: Pin,
                title: 'Pins and saves',
                body: 'Pin it for the channel, save it for yourself.',
            },
            {
                icon: Radio,
                title: 'Presence and status',
                body: 'Who is around, who is typing, and a status that expires.',
            },
            {
                icon: Headphones,
                title: 'Huddles',
                body: 'Live audio and video in any channel or DM. No invite, no link.',
            },
            {
                icon: Sunrise,
                title: 'Standups',
                body: 'A bot asks, on your days and your schedule, in your channel.',
            },
        ],
    },
    {
        id: 'projects',
        eyebrow: 'Projects',
        title: 'The work itself, not a link to it',
        features: [
            {
                icon: Columns3,
                title: 'Boards',
                body: 'Your columns, drag between them.',
            },
            {
                icon: ListTodo,
                title: 'Backlog',
                body: 'Type, priority, status, assignee — set from the row.',
            },
            {
                icon: Timer,
                title: 'Sprints',
                body: 'Start, complete, roll the rest onward.',
            },
            {
                icon: Rocket,
                title: 'Releases',
                body: 'What shipped together, planned through released.',
            },
            {
                icon: Tag,
                title: 'Labels and priorities',
                body: 'Your own labels and colours. Five priority levels.',
            },
            {
                icon: MessageCircle,
                title: 'Cards with detail',
                body: 'Comments, attachments, and per-project numbering.',
            },
            {
                icon: ChartNoAxesColumn,
                title: 'Summary',
                body: 'In flight, blocked, and shipped — without building a report.',
            },
            {
                icon: LayoutGrid,
                title: 'Grouped and starred',
                body: 'Your own headings. Archive what is done.',
            },
        ],
    },
    {
        id: 'knowledge',
        eyebrow: 'Knowledge',
        title: 'The things you need to find again',
        features: [
            {
                icon: FileText,
                title: 'Canvases',
                body: 'Shared docs with covers and templates, attached to a channel.',
            },
            {
                icon: Images,
                title: 'Asset library',
                body: 'Logos, decks, contracts. Folders, previews, grid or list.',
            },
            {
                icon: Files,
                title: 'File browser',
                body: 'Every file you can see, filtered by kind or channel.',
            },
            {
                icon: Search,
                title: 'Search',
                body: 'Full text, narrowed by from:, in:, and has:file.',
            },
            {
                icon: Paperclip,
                title: 'Attachments',
                body: 'Drag anything in. Thumbnails, previews, all of it searchable.',
            },
            {
                icon: Webhook,
                title: 'Webhooks',
                body: 'Post from CI or a script, signed if you want.',
            },
        ],
    },
    {
        id: 'attention',
        eyebrow: 'Attention',
        title: 'Told about the right things',
        features: [
            {
                icon: Bell,
                title: 'Per-channel levels',
                body: 'All, mentions only, or nothing.',
            },
            {
                icon: Inbox,
                title: 'One inbox',
                body: 'Mentions, replies, and assignments in a list you can clear.',
            },
            {
                icon: Moon,
                title: 'Quiet hours',
                body: 'Hold email overnight. The app keeps working.',
            },
            {
                icon: Mail,
                title: 'Digest email',
                body: 'One morning summary, off unless you ask.',
            },
            {
                icon: Activity,
                title: 'Activity feed',
                body: 'What happened while you were gone.',
            },
            {
                icon: Bookmark,
                title: 'Unread markers',
                body: 'Where you stopped, per channel, after a day off.',
            },
        ],
    },
    {
        id: 'running-it',
        eyebrow: 'Running it',
        title: 'Yours to administer',
        features: [
            {
                icon: LayoutGrid,
                title: 'Many workspaces',
                body: 'One login, separate channels, projects, and people.',
            },
            {
                icon: ShieldCheck,
                title: 'Roles and guests',
                body: 'Owner, admin, member — and guests who see one channel.',
            },
            {
                icon: UserPlus,
                title: 'Invitations',
                body: 'By email or a link you can revoke. No open sign-up.',
            },
            {
                icon: ScrollText,
                title: 'Audit log',
                body: 'Who removed it, who changed the role.',
            },
            {
                icon: Palette,
                title: 'Themes',
                body: 'Your logo, light or dark, six colours — set per person.',
            },
            {
                icon: Bookmark,
                title: 'Your own sidebar',
                body: 'Group channels under headings nobody else sees.',
            },
        ],
    },
    {
        id: 'foundations',
        eyebrow: 'Foundations',
        title: 'On your hardware, on your terms',
        features: [
            {
                icon: Server,
                title: 'Self-hosted',
                body: 'App, database, sockets, and media on your own boxes.',
            },
            {
                icon: KeyRound,
                title: 'Passkeys and 2FA',
                body: 'WebAuthn, authenticator apps, recovery codes.',
            },
            {
                icon: Lock,
                title: 'No public file URLs',
                body: 'Every download re-checks who you are.',
            },
            {
                icon: Zap,
                title: 'Realtime',
                body: 'Your own websocket server. No polling.',
            },
            {
                icon: HardDrive,
                title: 'Storage',
                body: 'Local disk or S3-compatible. One setting.',
            },
            {
                icon: Radio,
                title: 'One-screen setup',
                body: 'First account, first workspace, door closed.',
            },
        ],
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
                <header className="sticky top-0 z-20 border-b border-transparent bg-background/80 backdrop-blur-sm">
                    <div className="mx-auto flex w-full max-w-6xl items-center justify-between gap-4 px-6 py-4">
                        <SnackLogo />

                        {/*
                         * Jump links rather than a marketing menu. The page is
                         * long because the product is broad, and a reader who
                         * arrived asking "does it do project tracking" should
                         * not have to scroll past chat to find out.
                         */}
                        <nav
                            aria-label="Sections"
                            className="hidden items-center gap-1 lg:flex"
                        >
                            {SECTIONS.map((section) => (
                                <a
                                    key={section.id}
                                    href={`#${section.id}`}
                                    className="rounded-md px-2.5 py-1.5 text-sm text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                                >
                                    {section.eyebrow}
                                </a>
                            ))}
                        </nav>

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
                    </div>
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

                                <p className="mt-6 max-w-md text-lg text-pretty text-muted-foreground">
                                    Chat, boards, docs, and files in one app —
                                    on your own server.
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

                                <ul className="mt-10 flex flex-wrap gap-x-6 gap-y-2 text-sm text-muted-foreground">
                                    {SECTIONS.map((section) => (
                                        <li
                                            key={section.id}
                                            className="flex items-center gap-2"
                                        >
                                            <span
                                                aria-hidden="true"
                                                className="size-1.5 rounded-full bg-brand"
                                            />
                                            {section.eyebrow}
                                        </li>
                                    ))}
                                </ul>
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

                    {/* Features, by pillar */}
                    {SECTIONS.map((section, index) => (
                        <FeatureSection
                            key={section.id}
                            section={section}
                            /*
                             * Alternating surfaces rather than rules between
                             * sections: six identical bands of cards read as one
                             * very long list, and the reader loses track of
                             * which heading they are still under.
                             */
                            tinted={index % 2 === 1}
                        />
                    ))}

                    {/* Closing */}
                    <section className="mx-auto w-full max-w-6xl px-6 py-16 lg:py-24">
                        <div className="rounded-3xl border border-border bg-card px-6 py-12 text-center sm:px-12">
                            <SnackLogoIcon className="mx-auto size-10 text-brand" />

                            <h2 className="mx-auto mt-6 max-w-xl text-3xl font-bold tracking-tight text-balance">
                                Your team, your server, your history.
                            </h2>

                            <p className="mx-auto mt-4 max-w-md text-pretty text-muted-foreground">
                                No seat pricing, no message limits, nobody else
                                reading the room.
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

                        <nav
                            aria-label="Legal"
                            className="flex flex-wrap items-center justify-center gap-x-5 gap-y-2 text-sm text-muted-foreground"
                        >
                            <Link
                                href={terms()}
                                className="hover:text-foreground"
                            >
                                Terms
                            </Link>
                            <Link
                                href={privacy()}
                                className="hover:text-foreground"
                            >
                                Privacy
                            </Link>
                            <Link
                                href={deleteAccount()}
                                className="hover:text-foreground"
                            >
                                Delete account
                            </Link>
                        </nav>
                    </div>
                </footer>
            </div>
        </>
    );
}

/**
 * One pillar: a heading and its features.
 *
 * # Why the icon sits beside the name rather than above it
 *
 * A one-line feature stacked under a 40px icon is a card that is mostly empty
 * space, and a grid of them scrolls for pages saying very little. Putting the
 * icon on the left makes each entry two lines tall, which is what lets a whole
 * pillar be taken in without scrolling — the point of cutting the copy in the
 * first place.
 *
 * `scroll-mt` on the section stops the sticky header from covering the heading a
 * jump link just navigated to; without it the reader lands one heading-height
 * too far down and sees features with no title above them.
 */
function FeatureSection({
    section,
    tinted,
}: {
    section: Section;
    tinted: boolean;
}) {
    return (
        <section
            id={section.id}
            aria-labelledby={`${section.id}-heading`}
            className={
                'scroll-mt-20 border-t border-border' +
                (tinted ? ' bg-card/40' : '')
            }
        >
            <div className="mx-auto w-full max-w-6xl px-6 py-12 lg:py-16">
                <p className="text-xs font-semibold tracking-wide text-brand uppercase">
                    {section.eyebrow}
                </p>

                <h2
                    id={`${section.id}-heading`}
                    className="mt-1.5 max-w-2xl text-2xl font-bold tracking-tight text-balance sm:text-3xl"
                >
                    {section.title}
                </h2>

                <div className="mt-8 grid gap-x-8 gap-y-6 sm:grid-cols-2 lg:grid-cols-3">
                    {section.features.map((feature) => (
                        <div key={feature.title} className="flex gap-3">
                            <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-brand-subtle text-brand">
                                <feature.icon
                                    aria-hidden="true"
                                    className="size-4.5"
                                />
                            </span>

                            <div className="min-w-0">
                                <h3 className="text-sm font-semibold">
                                    {feature.title}
                                </h3>

                                <p className="mt-0.5 text-sm text-pretty text-muted-foreground">
                                    {feature.body}
                                </p>
                            </div>
                        </div>
                    ))}
                </div>
            </div>
        </section>
    );
}
