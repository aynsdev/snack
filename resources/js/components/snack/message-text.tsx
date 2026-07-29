import { Fragment } from 'react';
import { parseMessage } from '@/lib/message-format';
import type { BlockNode, InlineNode } from '@/lib/message-format';
import { cn } from '@/lib/utils';

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

        case 'strike':
            return (
                <s className="text-muted-foreground">
                    <Inline nodes={node.children} />
                </s>
            );
    }
}
