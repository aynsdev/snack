import { Form, Head, router, usePage } from '@inertiajs/react';
import { Bot, Check, Copy, KeyRound, Plus } from 'lucide-react';
import { useRef, useState } from 'react';
import InputError from '@/components/input-error';
import { EmptyState } from '@/components/snack/empty-state';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useClipboard } from '@/hooks/use-clipboard';
import { useWorkspace } from '@/hooks/use-workspace';
import { cn } from '@/lib/utils';
import { destroy, store } from '@/routes/connectors';
import type { Connector } from '@/types';

type Props = {
    connectors: Connector[];
    /** The MCP endpoint, absolute — it is pasted into software elsewhere. */
    server_url: string;
    limit: number;
};

type ClientId = 'claude-code' | 'claude-desktop' | 'cursor';

const CLIENTS: { id: ClientId; label: string }[] = [
    { id: 'claude-code', label: 'Claude Code' },
    { id: 'claude-desktop', label: 'Claude Desktop' },
    { id: 'cursor', label: 'Cursor / VS Code' },
];

const TOKEN_PLACEHOLDER = '<your-token>';

/**
 * AI connectors: MCP tokens that let an assistant work in this workspace as
 * the person who created them.
 *
 * The token itself is shown exactly once, from the flash data on the response
 * that issued it, and is gone on the next visit — Sanctum keeps only a hash.
 * The setup snippets fill it in while it is on screen and fall back to a
 * placeholder otherwise, so the instructions stay useful for connecting a
 * second client with a token pasted from somewhere else.
 */
export default function WorkspaceConnectors({
    connectors,
    server_url,
    limit,
}: Props) {
    const workspace = useWorkspace();
    const issued = usePage().flash.connector;
    const token = issued?.token ?? TOKEN_PLACEHOLDER;

    return (
        <>
            <Head title={`AI connectors · ${workspace.name}`} />

            <div className="snack-scroll min-w-0 flex-1 overflow-y-auto">
                <div className="mx-auto max-w-3xl px-6 py-8">
                    <header className="mb-6">
                        <h1 className="text-2xl font-bold tracking-tight">
                            AI connectors
                        </h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Connect Claude, Cursor, or any MCP client to{' '}
                            {workspace.name}. A connector can search and read
                            what you can see, and post or file tasks under your
                            name. It never sees more than you do.
                        </p>
                    </header>

                    <section className="mb-8 space-y-2">
                        <Label htmlFor="server-url">Server URL</Label>
                        <CopyField id="server-url" value={server_url} />
                    </section>

                    {issued && (
                        <section
                            className="mb-8 space-y-3 rounded-xl border border-primary/40 bg-primary/5 p-4"
                            aria-live="polite"
                        >
                            <div className="flex items-center gap-2">
                                <KeyRound
                                    aria-hidden="true"
                                    className="size-4 text-primary"
                                />
                                <h2 className="font-semibold">
                                    {issued.name} is ready
                                </h2>
                            </div>
                            <p className="text-sm text-muted-foreground">
                                Copy this token now. It will not be shown again
                                — if you lose it, revoke this connector and
                                create another.
                            </p>
                            <CopyField
                                id="new-token"
                                value={issued.token}
                                label="Connector token"
                            />
                        </section>
                    )}

                    <h2 className="mb-3 font-semibold">New connector</h2>

                    <Form
                        action={store(workspace.slug)}
                        resetOnSuccess
                        disableWhileProcessing
                        className="mb-8 rounded-xl border border-border p-4"
                    >
                        {({ errors, processing }) => (
                            <div className="flex flex-wrap items-end gap-3">
                                <div className="min-w-56 flex-1 space-y-2">
                                    <Label htmlFor="name">Name</Label>
                                    <Input
                                        id="name"
                                        name="name"
                                        required
                                        maxLength={80}
                                        placeholder="Claude on my laptop"
                                    />
                                    <InputError message={errors.name} />
                                </div>

                                <Button
                                    type="submit"
                                    disabled={
                                        processing || connectors.length >= limit
                                    }
                                >
                                    <Plus aria-hidden="true" />
                                    Create token
                                </Button>
                            </div>
                        )}
                    </Form>

                    <SetupInstructions serverUrl={server_url} token={token} />

                    <h2 className="mt-8 mb-3 font-semibold">Your connectors</h2>

                    {connectors.length === 0 ? (
                        <EmptyState
                            icon={Bot}
                            title="No connectors yet"
                            description="Create a token above, then add it to your AI client using the instructions for it."
                        />
                    ) : (
                        <ul className="divide-y divide-border overflow-hidden rounded-xl border border-border">
                            {connectors.map((connector) => (
                                <ConnectorRow
                                    key={connector.id}
                                    connector={connector}
                                    workspaceSlug={workspace.slug}
                                />
                            ))}
                        </ul>
                    )}
                </div>
            </div>
        </>
    );
}

function SetupInstructions({
    serverUrl,
    token,
}: {
    serverUrl: string;
    token: string;
}) {
    const [client, setClient] = useState<ClientId>('claude-code');

    const snippets: Record<ClientId, { hint: string; code: string }> = {
        'claude-code': {
            hint: 'Run this in your terminal.',
            code: `claude mcp add --transport http snack ${serverUrl} \\\n  --header "Authorization: Bearer ${token}"`,
        },
        'claude-desktop': {
            hint: 'Add this to claude_desktop_config.json (Settings → Developer → Edit Config), then restart Claude. Requires Node.js.',
            code: JSON.stringify(
                {
                    mcpServers: {
                        snack: {
                            command: 'npx',
                            args: [
                                '-y',
                                'mcp-remote',
                                serverUrl,
                                '--header',
                                `Authorization: Bearer ${token}`,
                            ],
                        },
                    },
                },
                null,
                2,
            ),
        },
        cursor: {
            hint: 'Add this to .cursor/mcp.json, or to .vscode/mcp.json under "servers" instead of "mcpServers".',
            code: JSON.stringify(
                {
                    mcpServers: {
                        snack: {
                            url: serverUrl,
                            headers: { Authorization: `Bearer ${token}` },
                        },
                    },
                },
                null,
                2,
            ),
        },
    };

    const current = snippets[client];

    return (
        <section className="space-y-3">
            <h2 className="font-semibold">Connect a client</h2>

            <div
                role="tablist"
                aria-label="MCP client"
                className="inline-flex rounded-lg border border-border p-0.5"
            >
                {CLIENTS.map(({ id, label }) => (
                    <button
                        key={id}
                        type="button"
                        role="tab"
                        aria-selected={client === id}
                        onClick={() => setClient(id)}
                        className={cn(
                            'rounded-md px-3 py-1.5 text-sm transition-colors',
                            client === id
                                ? 'bg-accent font-medium text-accent-foreground'
                                : 'text-muted-foreground hover:text-foreground',
                        )}
                    >
                        {label}
                    </button>
                ))}
            </div>

            <p className="text-sm text-muted-foreground">{current.hint}</p>

            <CodeBlock code={current.code} />
        </section>
    );
}

function CodeBlock({ code }: { code: string }) {
    const [, copy] = useClipboard();
    const [copied, setCopied] = useState(false);

    const handleCopy = async () => {
        if (await copy(code)) {
            setCopied(true);
            window.setTimeout(() => setCopied(false), 2000);
        }
    };

    return (
        <div className="relative">
            <pre className="overflow-x-auto rounded-xl border border-border bg-muted/50 p-4 pr-12 font-mono text-xs leading-relaxed">
                {code}
            </pre>
            <Button
                type="button"
                variant="ghost"
                size="icon"
                onClick={handleCopy}
                className="absolute top-2 right-2"
            >
                {copied ? (
                    <Check aria-hidden="true" />
                ) : (
                    <Copy aria-hidden="true" />
                )}
                <span className="sr-only">{copied ? 'Copied' : 'Copy'}</span>
            </Button>
        </div>
    );
}

function CopyField({
    id,
    value,
    label,
}: {
    id: string;
    value: string;
    label?: string;
}) {
    const [, copy] = useClipboard();
    const [copied, setCopied] = useState(false);
    const field = useRef<HTMLInputElement>(null);

    const handleCopy = async () => {
        // Selected either way: on an insecure origin the clipboard API is
        // missing, and a selected field lets the copy finish by keyboard.
        field.current?.select();

        if (await copy(value)) {
            setCopied(true);
            window.setTimeout(() => setCopied(false), 2000);
        }
    };

    return (
        <div className="flex flex-wrap items-center gap-2">
            <Input
                ref={field}
                id={id}
                readOnly
                value={value}
                aria-label={label}
                onFocus={(event) => event.currentTarget.select()}
                className="min-w-56 flex-1 font-mono text-xs"
            />
            <Button type="button" variant="outline" onClick={handleCopy}>
                {copied ? (
                    <Check aria-hidden="true" />
                ) : (
                    <Copy aria-hidden="true" />
                )}
                {copied ? 'Copied' : 'Copy'}
            </Button>
        </div>
    );
}

function ConnectorRow({
    connector,
    workspaceSlug,
}: {
    connector: Connector;
    workspaceSlug: string;
}) {
    return (
        <li className="flex flex-wrap items-center gap-4 px-4 py-3">
            <div className="min-w-0 flex-1">
                <span className="block truncate font-medium">
                    {connector.name}
                </span>
                <p className="truncate text-sm text-muted-foreground">
                    {connector.created_at &&
                        `Created ${new Date(connector.created_at).toLocaleDateString()}`}
                    {' · '}
                    {connector.last_used_at
                        ? `last used ${new Date(connector.last_used_at).toLocaleString()}`
                        : 'never used'}
                </p>
            </div>

            <Button
                variant="ghost"
                size="sm"
                onClick={() =>
                    router.delete(
                        destroy({
                            workspace: workspaceSlug,
                            token: connector.id,
                        }),
                        { preserveScroll: true },
                    )
                }
            >
                Revoke
            </Button>
        </li>
    );
}
