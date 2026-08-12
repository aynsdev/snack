import { Link } from '@inertiajs/react';
import { Fragment } from 'react';
import { useCurrentUser, useCurrentWorkspace } from '@/hooks/use-workspace';
import { parseMessage } from '@/lib/message-format';
import type { BlockNode, InlineNode } from '@/lib/message-format';
import { cn } from '@/lib/utils';
import { show as showChannel } from '@/routes/channels';

/**
 * A message body, rendered.
 *
 * Every branch below produces React elements or text nodes. Nothing is ever
 * assembled as an HTML string and nothing is passed to `dangerouslySetInnerHTML`
 * — which is what makes a hostile message body inert rather than sanitized. A
 * body of `<script>alert(1)</script>` renders as those literal characters,
 * because React escapes text nodes on the way into the DOM.
 */
export function MessageText({
    body,
    className,
}: {
    body: string;
    className?: string;
}) {
    const blocks = parseMessage(body);

    return (
        <div
            className={cn(
                'space-y-2 text-sm leading-relaxed break-words hyphens-auto',
                className,
            )}
        >
            {blocks.map((block, index) => (
                <Block key={index} block={block} />
            ))}
        </div>
    );
}

function Block({ block }: { block: BlockNode }) {
    if (block.type === 'codeblock') {
        return (
            <pre className="snack-scroll overflow-x-auto rounded-lg border border-border bg-muted/60 p-3 text-xs">
                <code>{block.value}</code>
            </pre>
        );
    }

    if (block.type === 'list') {
        // Tailwind's reset strips list styling, so the marker is put back
        // explicitly — without it every item renders as a bare line and the
        // list is invisible.
        const className = 'space-y-0.5 pl-5 marker:text-muted-foreground';

        const items = block.items.map((item, index) => (
            <li key={index}>
                <Inline nodes={item} />
            </li>
        ));

        return block.ordered ? (
            <ol className={cn(className, 'list-decimal')}>{items}</ol>
        ) : (
            <ul className={cn(className, 'list-disc')}>{items}</ul>
        );
    }

    if (block.type === 'quote') {
        return (
            <blockquote className="border-l-2 border-brand/60 pl-3 text-muted-foreground">
                <Inline nodes={block.children} />
            </blockquote>
        );
    }

    return (
        <p>
            <Inline nodes={block.children} />
        </p>
    );
}

function Inline({ nodes }: { nodes: InlineNode[] }) {
    return (
        <>
            {nodes.map((node, index) => (
                <InlineLeaf key={index} node={node} />
            ))}
        </>
    );
}

function InlineLeaf({ node }: { node: InlineNode }) {
    switch (node.type) {
        case 'text':
            // Newlines inside a paragraph are the author's own line breaks.
            return (
                <>
                    {node.value.split('\n').map((line, index) => (
                        <Fragment key={index}>
                            {index > 0 && <br />}
                            {line}
                        </Fragment>
                    ))}
                </>
            );

        case 'emoji':
            /*
             * The shortcode rides along as the accessible name: a screen reader
             * announcing "rocket" is the same information a sighted reader gets
             * from the glyph, where the bare character announces as anything
             * from a full description to nothing at all.
             */
            return (
                <span
                    role="img"
                    aria-label={node.shortcode}
                    className="text-[1.15em] leading-none"
                >
                    {node.glyph}
                </span>
            );

        case 'code':
            return (
                <code className="rounded bg-muted px-1 py-0.5 font-mono text-[0.8125em] text-foreground">
                    {node.value}
                </code>
            );

        case 'link':
            return (
                <a
                    href={node.href}
                    target="_blank"
                    /*
                     * noopener stops the destination reaching back through
                     * window.opener; noreferrer keeps the workspace URL — which
                     * names the tenant — out of the referrer header.
                     */
                    rel="noopener noreferrer nofollow"
                    className="text-brand underline underline-offset-2 hover:text-brand-hover"
                >
                    {node.value}
                </a>
            );

        case 'bold':
            return (
                <strong className="font-semibold">
                    <Inline nodes={node.children} />
                </strong>
            );

        case 'italic':
            return (
                <em>
                    <Inline nodes={node.children} />
                </em>
            );

        case 'mention':
            return <MentionChip node={node} />;

        case 'channel':
            return <ChannelChip slug={node.slug} />;

        case 'strike':
            return (
                <s className="text-muted-foreground">
                    <Inline nodes={node.children} />
                </s>
            );
    }
}

/**
 * An `@mention`.
 *
 * Two weights, and the distinction matters more than it looks: a mention that
 * addresses *you* is the reason you scan a channel at all, so it gets the loud
 * treatment, and every other mention stays a quiet reference to a person.
 *
 * Whether it is you is decided here, on the client, from the username already in
 * the page's props — there is nothing to fetch and nothing per-message to send.
 * It is presentation only; who actually gets notified was settled server-side
 * when the message was written.
 */
function MentionChip({
    node,
}: {
    node: Extract<InlineNode, { type: 'mention' }>;
}) {
    const currentUser = useCurrentUser();

    const addressesMe =
        node.kind !== 'user' ||
        (currentUser !== null &&
            currentUser.username.toLowerCase() === node.handle);

    return (
        <span
            className={cn(
                'rounded px-1 py-0.5 font-semibold',
                addressesMe
                    ? 'bg-mention text-mention-foreground'
                    : 'bg-brand-subtle text-brand',
            )}
        >
            @{node.handle}
        </span>
    );
}

/**
 * A `#channel` reference.
 *
 * Rendered as a link into the channel, but only inside a workspace — the same
 * body also renders in places with no workspace context, and a link built from
 * a missing slug would point at nothing.
 *
 * It does not check that the channel exists: doing so would need the full
 * directory, and a private channel's absence from that list would quietly turn
 * a valid reference into plain text for exactly the people who can see it.
 */
function ChannelChip({ slug }: { slug: string }) {
    const workspace = useCurrentWorkspace();

    if (workspace === null) {
        return <span className="font-semibold text-brand">#{slug}</span>;
    }

    return (
        <Link
            href={showChannel.url({
                workspace: workspace.workspace.slug,
                channel: slug,
            })}
            className="rounded px-0.5 font-semibold text-brand hover:underline"
        >
            #{slug}
        </Link>
    );
}
