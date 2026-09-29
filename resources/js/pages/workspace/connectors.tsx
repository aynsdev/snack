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
import { store } from '@/routes/connectors';
import { destroy as destroyApp } from '@/routes/connectors/apps';
import { destroy as destroyToken } from '@/routes/connectors/tokens';
import type { ConnectedApp, ConnectorToken } from '@/types';

type Props = {
    /** Clients that connected by signing in through the consent screen. */
    apps: ConnectedApp[];
    /** Personal tokens made on this page for clients configured by hand. */
    tokens: ConnectorToken[];
    /** The MCP endpoint, absolute — it is pasted into software elsewhere. */
    server_url: string;
    limit: number;
};

type ClientId = 'claude' | 'claude-code' | 'other';

const CLIENTS: { id: ClientId; label: string }[] = [
    { id: 'claude', label: 'Claude' },
    { id: 'claude-code', label: 'Claude Code' },
    { id: 'other', label: 'Other clients' },
];

const TOKEN_PLACEHOLDER = '<your-token>';

/**
 * AI connectors: every assistant working in this workspace as the person
 * looking at the page.
 *
 * Signing in is the way in for Claude and Claude Code — the client sends the
 * person to Snack's consent screen, where they pick this workspace — so the
 * setup instructions lead with it and need no token at all. Tokens remain for
 * clients that can only send a fixed header.
 *
 * A token is shown exactly once, from the flash data on the response that
 * issued it, and is gone on the next visit.
 */
export default function WorkspaceConnectors({
    apps,
    tokens,
    server_url,
    limit,
}: Props) {
    const workspace = useWorkspace();
    const issued = usePage().flash.connector;

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
                            Connect Claude, or any MCP client, to{' '}
                            {workspace.name}. It can search and read what you
                            can see, and post or file tasks under your name. It
                            never sees more than you do.
                        </p>
                    </header>

                    <section className="mb-8 space-y-2">
                        <Label htmlFor="server-url">Server URL</Label>
                        <CopyField id="server-url" value={server_url} />
                    </section>

                    <SetupInstructions
                        serverUrl={server_url}
                        token={issued?.token ?? TOKEN_PLACEHOLDER}
                    />

                    <h2 className="mt-8 mb-3 font-semibold">Connected apps</h2>

                    {apps.length === 0 ? (
                        <EmptyState
                            icon={Bot}
                            title="No apps connected"
                            description="Apps you connect by signing in, such as Claude, appear here."
                        />
                    ) : (
                        <ul className="divide-y divide-border overflow-hidden rounded-xl border border-border">
                            {apps.map((app) => (
                                <ConnectionRow
                                    key={app.id}
                                    name={app.name}
                                    detail={
                                        app.connected_at
                                            ? `Connected ${new Date(app.connected_at).toLocaleDateString()}`
                                            : 'Connected'
                                    }
                                    action="Disconnect"
                                    onRemove={() =>
                                        router.delete(
                                            destroyApp({
                                                workspace: workspace.slug,
                                                grant: app.id,
                                            }),
                                            { preserveScroll: true },
                                        )
                                    }
                                />
                            ))}
                        </ul>
                    )}

                    <h2 className="mt-8 mb-1 font-semibold">Tokens</h2>
                    <p className="mb-3 text-sm text-muted-foreground">
                        For clients that cannot sign in and need a token pasted
                        into their settings instead.
                    </p>

                    {issued && (
                        <section
                            className="mb-4 space-y-3 rounded-xl border border-primary/40 bg-primary/5 p-4"
                            aria-live="polite"
                        >
                            <div className="flex items-center gap-2">
                                <KeyRound
                                    aria-hidden="true"
                                    className="size-4 text-primary"
                                />
                                <h3 className="font-semibold">
                                    {issued.name} is ready
                                </h3>
                            </div>
                            <p className="text-sm text-muted-foreground">
                                Copy this token now. It will not be shown again
                                — if you lose it, revoke it and create another.
                            </p>
                            <CopyField
                                id="new-token"
                                value={issued.token}
                                label="Connector token"
                            />
                        </section>
                    )}

                    <Form
                        action={store(workspace.slug)}
                        resetOnSuccess
                        disableWhileProcessing
                        className="mb-4 rounded-xl border border-border p-4"
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
                                        placeholder="Cursor on my laptop"
                                    />
                                    <InputError message={errors.name} />
                                </div>

                                <Button
                                    type="submit"
                                    disabled={
                                        processing || tokens.length >= limit
                                    }
                                >
                                    <Plus aria-hidden="true" />
                                    Create token
                                </Button>
                            </div>
                        )}
                    </Form>

                    {tokens.length > 0 && (
                        <ul className="divide-y divide-border overflow-hidden rounded-xl border border-border">
                            {tokens.map((token) => (
                                <ConnectionRow
                                    key={token.id}
                                    name={token.name}
                                    detail={[
                                        token.created_at &&
                                            `Created ${new Date(token.created_at).toLocaleDateString()}`,
                                        token.expires_at &&
                                            `expires ${new Date(token.expires_at).toLocaleDateString()}`,
                                    ]
                                        .filter(Boolean)
                                        .join(' · ')}
                                    action="Revoke"
                                    onRemove={() =>
                                        router.delete(
                                            destroyToken({
                                                workspace: workspace.slug,
                                                token: token.id,
                                            }),
                                            { preserveScroll: true },
                                        )
                                    }
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
    const [client, setClient] = useState<ClientId>('claude');

    const snippets: Record<ClientId, { hint: string; code: string | null }> = {
        claude: {
            hint: 'In Claude, open Settings → Connectors → Add custom connector (for a whole organization, an owner does this under Organization settings → Connectors) and paste the server URL above. Choosing Connect brings you here to sign in and pick this workspace.',
            code: null,
        },
        'claude-code': {
            hint: 'Run this in your terminal, then run /mcp inside Claude Code and choose Authenticate to sign in.',
            code: `claude mcp add --transport http snack ${serverUrl}`,
        },
        other: {
            hint: 'For clients that take a URL and headers — Cursor (.cursor/mcp.json), VS Code (.vscode/mcp.json, under "servers") and others. Create a token below first.',
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

            {current.code !== null && <CodeBlock code={current.code} />}
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

function ConnectionRow({
    name,
    detail,
    action,
    onRemove,
}: {
    name: string;
    detail: string;
    action: string;
    onRemove: () => void;
}) {
    return (
        <li className="flex flex-wrap items-center gap-4 px-4 py-3">
            <div className="min-w-0 flex-1">
                <span className="block truncate font-medium">{name}</span>
                <p className="truncate text-sm text-muted-foreground">
                    {detail}
                </p>
            </div>

            <Button variant="ghost" size="sm" onClick={onRemove}>
                {action}
            </Button>
        </li>
    );
}
