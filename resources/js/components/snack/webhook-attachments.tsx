import { MessageText } from '@/components/snack/message-text';
import { cn } from '@/lib/utils';
import type { WebhookAttachment } from '@/types';

/**
 * The colour bar down the left of a card.
 *
 * Slack's four words map to theme tokens rather than to literal colours, so a
 * card reads correctly in both themes and matches the rest of the app —
 * `#36a64f` on a dark background is the sort of thing that looks fine in the
 * screenshot and glows in practice.
 *
 * A caller's own hex is still honoured, because that is the point of the field
 * and a build system's brand colour is theirs to choose. It is applied as an
 * inline `borderColor` on a 2px rule and nowhere else: the value came from an
 * unauthenticated request, so it is allowed to tint one border and is never
 * interpolated into a class name or a background a person has to read text off.
 */
const NAMED_COLORS: Record<string, string> = {
    good: 'border-l-emerald-500',
    warning: 'border-l-amber-500',
    danger: 'border-l-destructive',
    default: 'border-l-border',
};

/**
 * The cards beneath a webhook message — Slack's legacy attachment shape.
 *
 * # Why this renders text and not markup
 *
 * Every string here came from a request nobody authenticated. They are rendered
 * as React text nodes, exactly like a message body, so a hostile string is the
 * characters it contains and never markup — see lib/message-format.ts. The
 * server has already rebuilt the structure key by key and dropped what it did
 * not recognise (App\Support\WebhookPayload), so the *shape* is ours even though
 * the content is not.
 *
 * `title_link` is the one attribute a caller can influence, and the server has
 * already restricted it to http and https. `rel="noopener noreferrer"` because
 * the destination is by definition somebody else's server.
 */
export function WebhookAttachments({
    attachments,
}: {
    attachments: WebhookAttachment[];
}) {
    if (attachments.length === 0) {
        return null;
    }

    return (
        <div className="mt-1.5 space-y-1.5">
            {attachments.map((attachment, index) => (
                <WebhookCard
                    /*
                     * Index as key: the cards have no ids of their own, they are
                     * never reordered, and the list is replaced wholesale when
                     * the message reloads. There is no state inside a card for a
                     * reused key to strand.
                     */
                    key={index}
                    attachment={attachment}
                />
            ))}
        </div>
    );
}

function WebhookCard({ attachment }: { attachment: WebhookAttachment }) {
    const color = attachment.color ?? 'default';
    const named = NAMED_COLORS[color];

    return (
        <div className="max-w-2xl">
            {attachment.pretext !== undefined && (
                <p className="mb-1 text-sm text-pretty text-muted-foreground">
                    {attachment.pretext}
                </p>
            )}

            <div
                className={cn(
                    'rounded-r border-l-2 bg-muted/40 py-2 pr-3 pl-3',
                    named ?? 'border-l-transparent',
                )}
                // Only for a caller's own hex — a named colour is already a
                // class, and passing both would have the inline value win.
                style={
                    named === undefined ? { borderLeftColor: color } : undefined
                }
            >
                {attachment.author_name !== undefined && (
                    <p className="text-xs font-medium text-muted-foreground">
                        {attachment.author_name}
                    </p>
                )}

                {attachment.title !== undefined &&
                    (attachment.title_link !== undefined ? (
                        <a
                            href={attachment.title_link}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="text-sm font-semibold text-balance text-primary hover:underline"
                        >
                            {attachment.title}
                        </a>
                    ) : (
                        <p className="text-sm font-semibold text-balance">
                            {attachment.title}
                        </p>
                    ))}

                {attachment.text !== undefined && (
                    <div className="mt-0.5 text-sm text-pretty">
                        {/*
                         * Through the same tokenizer a message body uses, so a
                         * card's `*bold*` and its links look like everyone
                         * else's rather than like a second dialect nobody
                         * documented.
                         */}
                        <MessageText body={attachment.text} />
                    </div>
                )}

                {attachment.fields !== undefined &&
                    attachment.fields.length > 0 && (
                        <dl className="mt-2 grid grid-cols-2 gap-x-4 gap-y-2">
                            {attachment.fields.map((field, index) => (
                                <div
                                    key={index}
                                    // Slack's `short` flag: a half-width field
                                    // pairs up, a long one takes the row.
                                    className={cn(
                                        'min-w-0',
                                        !field.short && 'col-span-2',
                                    )}
                                >
                                    <dt className="text-xs font-medium text-muted-foreground">
                                        {field.title}
                                    </dt>
                                    <dd className="text-sm break-words tabular-nums">
                                        {field.value}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                    )}

                {(attachment.footer !== undefined ||
                    attachment.ts !== undefined) && (
                    <p className="mt-2 text-xs text-muted-foreground">
                        {attachment.footer}
                        {attachment.footer !== undefined &&
                            attachment.ts !== undefined &&
                            ' · '}
                        {attachment.ts !== undefined && (
                            <time
                                dateTime={new Date(
                                    attachment.ts * 1000,
                                ).toISOString()}
                                className="tabular-nums"
                            >
                                {/*
                                 * Rendered in the reader's own locale from a
                                 * Unix timestamp, rather than trusting a
                                 * caller's preformatted date string — which
                                 * would be in whatever timezone their build
                                 * server happens to run in.
                                 */}
                                {new Date(
                                    attachment.ts * 1000,
                                ).toLocaleString()}
                            </time>
                        )}
                    </p>
                )}
            </div>
        </div>
    );
}
