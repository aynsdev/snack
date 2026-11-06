import { useState } from 'react';
import type { ReactNode } from 'react';
import { WebhookAttachments } from '@/components/snack/webhook-attachments';
import { cn } from '@/lib/utils';
import type { WebhookAttachment } from '@/types';

export type WebhookLimits = {
    max_text_length: number;
    max_attachments: number;
    max_fields: number;
    rate_limit_per_minute: number;
};

type Topic = 'basics' | 'cards' | 'colors' | 'threads';

const TOPICS: { value: Topic; label: string }[] = [
    { value: 'basics', label: 'Basics' },
    { value: 'cards', label: 'Cards' },
    { value: 'colors', label: 'Colours' },
    { value: 'threads', label: 'Threads' },
];

/**
 * The developer guide on the webhooks screen: what to POST, and what it looks
 * like once it lands.
 *
 * On the page rather than in a docs site because the person wiring up a hook is
 * looking at this screen with a terminal open beside it. The examples are drawn
 * with the real card renderer, so "what does `color` do" is answered by looking
 * rather than by reading — and the numbers come from the server so they cannot
 * drift from what it actually enforces.
 */
export function WebhookDocs({ limits }: { limits: WebhookLimits }) {
    const [topic, setTopic] = useState<Topic>('basics');

    return (
        <section className="rounded-xl border border-border bg-card px-4 py-3">
            <h2 className="text-sm font-semibold">Sending to a webhook</h2>
            <p className="mt-1 text-sm text-pretty text-muted-foreground">
                A guide for the developer wiring up the integration.
                Slack-shaped payloads work as they are, so an existing
                integration usually just needs its URL changed.
            </p>

            <div
                role="group"
                aria-label="Webhook guide topic"
                className="mt-3 inline-flex max-w-full gap-1 overflow-x-auto rounded-lg border border-border bg-muted p-1"
            >
                {TOPICS.map(({ value, label }) => (
                    <button
                        key={value}
                        type="button"
                        aria-pressed={topic === value}
                        onClick={() => setTopic(value)}
                        className={cn(
                            'rounded-md px-3 py-1 text-sm whitespace-nowrap transition-colors',
                            topic === value
                                ? 'bg-card font-medium text-foreground shadow-xs'
                                : 'text-muted-foreground hover:bg-accent hover:text-accent-foreground',
                        )}
                    >
                        {label}
                    </button>
                ))}
            </div>

            <div className="mt-3">
                {topic === 'basics' && <Basics limits={limits} />}
                {topic === 'cards' && <Cards limits={limits} />}
                {topic === 'colors' && <Colors />}
                {topic === 'threads' && <Threads />}
            </div>
        </section>
    );
}

function Basics({ limits }: { limits: WebhookLimits }) {
    return (
        <div className="space-y-3">
            <Prose>
                POST JSON with a <Code>text</Code> field to the webhook URL. It
                becomes a message in the webhook&apos;s channel, posted by its
                bot.
            </Prose>

            <CodeBlock>
                {`curl -X POST -H 'Content-Type: application/json' \\
  -d '{"text": "Deploy #482 shipped"}' \\
  <the URL above>`}
            </CodeBlock>

            <Prose>A successful delivery answers 201 with:</Prose>

            <CodeBlock>
                {`{"ok": true, "message_id": 9123, "thread_id": 9123}`}
            </CodeBlock>

            <Prose>
                Anything else answers{' '}
                <Code>{'{"ok": false, "error": "…"}'}</Code> with a status that
                says why: 401 for a missing or wrong signature on a signed
                webhook, 404 for a URL that no longer works, 410 for an archived
                channel, 422 for a payload that could not be posted, 429 when
                you are over the rate limit.
            </Prose>

            <SubHeading>Top-level fields</SubHeading>

            <FieldTable
                rows={[
                    [
                        'text',
                        'The message itself. Required unless you send cards.',
                        `${limits.max_text_length.toLocaleString()} chars`,
                    ],
                    [
                        'attachments',
                        'Cards shown under the message — see Cards.',
                        `${limits.max_attachments} cards`,
                    ],
                    [
                        'username',
                        'Name shown on this one message, e.g. "Kusina POS". Still marked as a bot.',
                        '80 chars',
                    ],
                    [
                        'icon_emoji',
                        'Emoji shown as the avatar, e.g. ":moneybag:".',
                        '—',
                    ],
                    [
                        'thread_key',
                        'Your own id for a thread — see Threads.',
                        '255 chars',
                    ],
                    [
                        'thread_id',
                        'Reply under an earlier message_id — see Threads.',
                        '—',
                    ],
                ]}
            />

            <ul className="space-y-1 text-xs text-muted-foreground tabular-nums">
                <li>
                    {limits.rate_limit_per_minute} deliveries a minute per
                    webhook.
                </li>
                <li>
                    The channel is fixed by the webhook and cannot be set by the
                    payload.
                </li>
                <li>
                    Browser apps can call the URL directly; cross-origin
                    requests are allowed.
                </li>
            </ul>
        </div>
    );
}

const CARD_EXAMPLE: WebhookAttachment = {
    color: 'good',
    pretext: 'Payment received',
    author_name: 'Kusina POS',
    title: 'TXN-1042',
    title_link: 'https://example.com/transactions/1042',
    text: 'Paid in full at the counter.',
    fields: [
        { title: 'Amount', value: '₱1,250.00', short: true },
        { title: 'Method', value: 'GCash', short: true },
        { title: 'Note', value: 'Table 4, two orders merged', short: false },
    ],
    footer: 'Branch: Makati',
    ts: 1_793_000_000,
};

function Cards({ limits }: { limits: WebhookLimits }) {
    return (
        <div className="space-y-3">
            <Prose>
                <Code>attachments</Code> are <strong>cards</strong>: boxes of
                structured details drawn under the message. They are not files —
                a webhook cannot upload files or images. Send up to{' '}
                {limits.max_attachments} cards per message, each with up to{' '}
                {limits.max_fields} fields.
            </Prose>

            <Example
                code={`{
  "text": "TXN-1042 paid",
  "attachments": [{
    "color": "good",
    "pretext": "Payment received",
    "author_name": "Kusina POS",
    "title": "TXN-1042",
    "title_link": "https://pos.example.com/txn/1042",
    "text": "Paid in full at the counter.",
    "fields": [
      {"title": "Amount", "value": "₱1,250.00", "short": true},
      {"title": "Method", "value": "GCash", "short": true},
      {"title": "Note", "value": "Table 4, two orders merged"}
    ],
    "footer": "Branch: Makati",
    "ts": 1793000000
  }]
}`}
            >
                <MessagePreview text="TXN-1042 paid">
                    <WebhookAttachments attachments={[CARD_EXAMPLE]} />
                </MessagePreview>
            </Example>

            <SubHeading>Card fields</SubHeading>

            <FieldTable
                rows={[
                    [
                        'color',
                        'The bar down the card’s left edge — see Colours.',
                        '—',
                    ],
                    ['pretext', 'A line above the card.', '500 chars'],
                    ['author_name', 'A small "from" line.', '120 chars'],
                    ['title', 'The card’s heading.', '300 chars'],
                    [
                        'title_link',
                        'Makes the title a link. http and https only.',
                        '2,000 chars',
                    ],
                    [
                        'text',
                        'The body. Line breaks and *bold* work.',
                        '2,000 chars',
                    ],
                    [
                        'fields',
                        'Label/value pairs. "short": true puts two side by side; otherwise each takes a full row.',
                        `${limits.max_fields} per card`,
                    ],
                    ['footer', 'A small line at the bottom.', '200 chars'],
                    [
                        'ts',
                        'Unix seconds, shown in the footer in each reader’s own timezone.',
                        '—',
                    ],
                ]}
            />

            <ul className="space-y-1 text-xs text-muted-foreground">
                <li>
                    Every key is optional. Keys not listed here — Slack&apos;s{' '}
                    <Code>image_url</Code>, <Code>thumb_url</Code>,{' '}
                    <Code>author_icon</Code> and the rest — are ignored, not
                    rejected.
                </li>
                <li>
                    <Code>attachments</Code> is a list of cards:{' '}
                    <Code>{'[{...}]'}</Code>. A single card sent as an object,
                    and single values wrapped in a list like{' '}
                    <Code>{'["danger"]'}</Code>, are accepted too — the shapes
                    automation tools often produce.
                </li>
                <li>
                    No <Code>text</Code>? The first card&apos;s{' '}
                    <Code>fallback</Code>, <Code>title</Code>, <Code>text</Code>{' '}
                    or <Code>pretext</Code> becomes the message.
                </li>
            </ul>
        </div>
    );
}

const COLOR_EXAMPLES: { color: string; meaning: string }[] = [
    { color: 'good', meaning: 'Succeeded, paid, settled' },
    { color: 'warning', meaning: 'Pending, retrying, needs a look' },
    { color: 'danger', meaning: 'Failed, declined, down' },
    { color: 'default', meaning: 'Neutral information' },
    { color: '#6366f1', meaning: 'Any #rrggbb — your own brand colour' },
];

function Colors() {
    return (
        <div className="space-y-3">
            <Prose>
                <Code>color</Code> belongs to a <strong>card</strong>, not to
                the message. It tints the thin bar down the card&apos;s left
                edge so people can read a status at a glance. The message text
                above the card always looks like any other message.
            </Prose>

            <Prose>
                To make a status stand out, keep <Code>text</Code> short and put
                the details in a coloured card.
            </Prose>

            <ul className="grid gap-3 sm:grid-cols-2">
                {COLOR_EXAMPLES.map(({ color, meaning }) => (
                    <li key={color} className="min-w-0">
                        <p className="mb-1 text-xs text-muted-foreground">
                            <Code>{`"color": "${color}"`}</Code>
                        </p>
                        <WebhookAttachments
                            attachments={[
                                { color, title: meaning, text: 'Card body' },
                            ]}
                        />
                    </li>
                ))}
            </ul>

            <p className="text-xs text-muted-foreground">
                A colour that is not one of these falls back to{' '}
                <Code>default</Code> instead of failing the delivery.
            </p>
        </div>
    );
}

function Threads() {
    return (
        <div className="space-y-3">
            <Prose>
                Narrating something with a lifecycle — a transaction, an order,
                a deploy? Send your own id for it as <Code>thread_key</Code>.
                The first delivery with a new key posts a normal message and
                starts a thread; every later delivery with the same key replies
                inside it. You do not need to store anything Snack returns.
            </Prose>

            <Example
                code={`POST {"text": "TXN-1042 started", "thread_key": "TXN-1042"}
  → new message, starts the thread

POST {"text": "TXN-1042 paid",    "thread_key": "TXN-1042"}
POST {"text": "TXN-1042 settled", "thread_key": "TXN-1042"}
  → replies in that thread`}
            >
                <MessagePreview text="TXN-1042 started">
                    <p className="mt-1 text-xs font-medium text-primary">
                        2 replies
                    </p>
                    <div className="mt-1.5 space-y-1 border-l-2 border-border pl-3 text-sm">
                        <p>TXN-1042 paid</p>
                        <p>TXN-1042 settled</p>
                    </div>
                </MessagePreview>
            </Example>

            <SubHeading>In a Laravel app</SubHeading>

            <CodeBlock>
                {`Http::post(config('services.snack.webhook'), [
    'text' => "Transaction {$txn->ref} paid",
    'thread_key' => $txn->ref,
    'attachments' => [[
        'color' => 'good',
        'title' => "TXN {$txn->ref}",
        'title_link' => route('transactions.show', $txn),
        'fields' => [
            ['title' => 'Amount', 'value' => "₱{$txn->amount}", 'short' => true],
            ['title' => 'Status', 'value' => 'Paid', 'short' => true],
        ],
        'ts' => now()->timestamp,
    ]],
]);`}
            </CodeBlock>

            <ul className="space-y-1 text-xs text-muted-foreground">
                <li>
                    Keys belong to one webhook: two webhooks can both use{' '}
                    <Code>TXN-1042</Code> without sharing a thread.
                </li>
                <li>
                    If someone deletes the thread&apos;s first message, the next
                    delivery with that key starts a fresh thread.
                </li>
                <li>
                    Updates sent milliseconds apart still land in one thread.
                </li>
                <li>
                    Prefer Snack&apos;s ids? Send <Code>thread_id</Code> with a{' '}
                    <Code>message_id</Code> an earlier delivery from this
                    webhook returned. Send one of <Code>thread_key</Code> or{' '}
                    <Code>thread_id</Code>, not both.
                </li>
            </ul>
        </div>
    );
}

/**
 * A payload beside what it turns into: side by side on a wide screen, stacked
 * on a phone.
 */
function Example({ code, children }: { code: string; children: ReactNode }) {
    return (
        <div className="grid gap-3 lg:grid-cols-2">
            <CodeBlock>{code}</CodeBlock>
            <div className="min-w-0">
                <p className="mb-1.5 text-xs font-medium text-muted-foreground">
                    How it looks
                </p>
                {children}
            </div>
        </div>
    );
}

function MessagePreview({
    text,
    children,
}: {
    text: string;
    children: ReactNode;
}) {
    return (
        <div className="rounded-lg border border-border px-3 py-2">
            <p className="flex items-center gap-1.5 text-sm font-semibold">
                Kusina POS
                <span className="rounded bg-muted px-1 py-px text-[0.625rem] font-medium tracking-wide text-muted-foreground uppercase">
                    Bot
                </span>
            </p>
            <p className="text-sm">{text}</p>
            {children}
        </div>
    );
}

function FieldTable({ rows }: { rows: [string, string, string][] }) {
    return (
        <div className="snack-scroll overflow-x-auto">
            <table className="w-full min-w-[28rem] text-left text-sm">
                <thead className="text-xs text-muted-foreground">
                    <tr className="border-b border-border">
                        <th className="py-1.5 pr-3 font-medium">Key</th>
                        <th className="py-1.5 pr-3 font-medium">
                            What it does
                        </th>
                        <th className="py-1.5 font-medium">Limit</th>
                    </tr>
                </thead>
                <tbody>
                    {rows.map(([key, description, limit]) => (
                        <tr
                            key={key}
                            className="border-b border-border last:border-0"
                        >
                            <td className="py-1.5 pr-3 align-top">
                                <Code>{key}</Code>
                            </td>
                            <td className="py-1.5 pr-3 align-top text-pretty">
                                {description}
                            </td>
                            <td className="py-1.5 align-top text-xs whitespace-nowrap text-muted-foreground tabular-nums">
                                {limit}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function SubHeading({ children }: { children: ReactNode }) {
    return <h3 className="pt-1 text-sm font-semibold">{children}</h3>;
}

function Prose({ children }: { children: ReactNode }) {
    return (
        <p className="text-sm text-pretty text-muted-foreground">{children}</p>
    );
}

function Code({ children }: { children: ReactNode }) {
    return (
        <code className="rounded bg-muted px-1 py-px font-mono text-xs text-foreground">
            {children}
        </code>
    );
}

function CodeBlock({ children }: { children: string }) {
    return (
        <pre className="snack-scroll min-w-0 overflow-x-auto rounded bg-muted px-3 py-2 font-mono text-xs">
            {children}
        </pre>
    );
}
