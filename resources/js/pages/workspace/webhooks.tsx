import { Head, router, useForm } from '@inertiajs/react';
import { Check, Copy, Plug, Plus, PowerOff, Trash2 } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useWorkspace } from '@/hooks/use-workspace';
import { cn } from '@/lib/utils';
import {
    destroy as destroyWebhook,
    revoke as revokeWebhook,
    store as storeWebhook,
    update as updateWebhook,
} from '@/routes/webhooks';
import type { IncomingWebhook } from '@/types';

type ChannelOption = {
    id: number;
    name: string;
    slug: string;
    type: string;
};

type Limits = {
    max_text_length: number;
    max_attachments: number;
    max_fields: number;
    rate_limit_per_minute: number;
};

type Props = {
    webhooks: IncomingWebhook[];
    channels: ChannelOption[];
    limits: Limits;
};

const RELATIVE = new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
    timeStyle: 'short',
});

/**
 * The URLs outside systems post to, and where each one lands.
 *
 * Admin-gated, and unusually the *viewing* is the gate rather than the editing:
 * this page prints live tokens, so opening it is the same right as issuing one.
 * See App\Policies\IncomingWebhookPolicy.
 *
 * # Why a hook cannot be pointed somewhere else
 *
 * There is no channel picker in the edit dialog, and that is the design. By the
 * time anybody edits a hook its URL is already in a CI config somewhere, and
 * re-aiming it would silently redirect a live credential into a room its holder
 * never agreed to write to. Moving a hook means making a new one and revoking
 * the old — worse for the admin, and the only version where a URL's meaning is
 * fixed at the moment it is handed out.
 */
export default function WebhooksPage({ webhooks, channels, limits }: Props) {
    const workspace = useWorkspace();

    // `null` closes the dialog, a hook opens it on that one, `'new'` opens it
    // empty. One piece of state rather than three booleans that can contradict
    // each other.
    const [editing, setEditing] = useState<IncomingWebhook | 'new' | null>(
        null,
    );

    return (
        <>
            <Head title={`Webhooks · ${workspace.name}`} />

            <div className="snack-scroll min-w-0 flex-1 overflow-y-auto">
                <div className="mx-auto max-w-3xl space-y-6 px-6 py-8">
                    <header className="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <h1 className="text-2xl font-bold tracking-tight text-balance">
                                Incoming webhooks
                            </h1>
                            <p className="mt-1 max-w-xl text-sm text-pretty text-muted-foreground">
                                Give an outside system a URL and whatever it
                                posts there becomes a message in one channel.
                                Deploy notifications, alerts, a nightly report —
                                anything that can send an HTTP request.
                            </p>
                        </div>

                        <Button
                            type="button"
                            onClick={() => setEditing('new')}
                            disabled={channels.length === 0}
                        >
                            <Plus aria-hidden="true" />
                            New webhook
                        </Button>
                    </header>

                    {webhooks.length === 0 ? (
                        <EmptyState onCreate={() => setEditing('new')} />
                    ) : (
                        <ul className="space-y-3">
                            {webhooks.map((webhook) => (
                                <li key={webhook.id}>
                                    <WebhookRow
                                        webhook={webhook}
                                        workspaceSlug={workspace.slug}
                                        onEdit={() => setEditing(webhook)}
                                    />
                                </li>
                            ))}
                        </ul>
                    )}

                    <UsagePanel limits={limits} />
                </div>
            </div>

            {editing !== null && (
                <WebhookDialog
                    /*
                     * Keyed so switching from one hook to another rebuilds the
                     * form rather than reusing the previous one's state —
                     * `useForm` seeds from its initial values, so without this
                     * the second hook opened would show the first's answers.
                     */
                    key={editing === 'new' ? 'new' : editing.id}
                    webhook={editing === 'new' ? null : editing}
                    channels={channels}
                    workspaceSlug={workspace.slug}
                    onClose={() => setEditing(null)}
                />
            )}
        </>
    );
}

function EmptyState({ onCreate }: { onCreate: () => void }) {
    return (
        <div className="rounded-xl border border-dashed border-border px-6 py-12 text-center">
            <Plug
                aria-hidden="true"
                className="mx-auto size-8 text-muted-foreground"
            />

            <h2 className="mt-3 text-sm font-semibold">No webhooks yet</h2>

            <p className="mx-auto mt-1 max-w-sm text-sm text-pretty text-muted-foreground">
                Pick a channel and you get a URL. Anything that can POST JSON to
                it — a deploy script, a monitoring tool, your own app — can post
                there.
            </p>

            <Button
                type="button"
                variant="outline"
                className="mt-4"
                onClick={onCreate}
            >
                <Plus aria-hidden="true" />
                Create a webhook
            </Button>
        </div>
    );
}

function WebhookRow({
    webhook,
    workspaceSlug,
    onEdit,
}: {
    webhook: IncomingWebhook;
    workspaceSlug: string;
    onEdit: () => void;
}) {
    const [confirmingDelete, setConfirmingDelete] = useState(false);

    return (
        <div
            className={cn(
                'rounded-xl border border-border bg-card px-4 py-3',
                webhook.is_revoked && 'opacity-70',
            )}
        >
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                        <h2 className="text-sm font-semibold">
                            {webhook.name}
                        </h2>

                        <span className="rounded bg-muted px-1.5 py-0.5 text-xs text-muted-foreground">
                            #{webhook.channel.slug}
                        </span>

                        {webhook.is_revoked && (
                            <span className="flex items-center gap-1 rounded bg-muted px-1.5 py-0.5 text-xs text-muted-foreground">
                                <PowerOff
                                    aria-hidden="true"
                                    className="size-3"
                                />
                                Turned off
                            </span>
                        )}
                    </div>

                    <p className="mt-1 text-xs text-muted-foreground">
                        Posts as {webhook.display_name}
                        {webhook.default_icon !== null &&
                            ` · :${webhook.default_icon}:`}
                    </p>

                    <p className="mt-0.5 text-xs text-muted-foreground tabular-nums">
                        {webhook.delivery_count === 0
                            ? 'Never used'
                            : `${webhook.delivery_count} ${webhook.delivery_count === 1 ? 'delivery' : 'deliveries'}`}
                        {webhook.last_delivered_at !== null &&
                            ` · last ${RELATIVE.format(new Date(webhook.last_delivered_at))}`}
                    </p>
                </div>

                <div className="flex shrink-0 items-center gap-2">
                    {!webhook.is_revoked && (
                        <>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={onEdit}
                            >
                                Edit
                            </Button>

                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                title="Turn this webhook off — its URL stops working immediately"
                                onClick={() =>
                                    router.patch(
                                        revokeWebhook.url({
                                            workspace: workspaceSlug,
                                            webhook: webhook.id,
                                        }),
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                <PowerOff aria-hidden="true" />
                                <span className="sr-only">
                                    Turn off {webhook.name}
                                </span>
                            </Button>
                        </>
                    )}

                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        title={
                            confirmingDelete
                                ? 'Removes it from this list — the messages it posted stay'
                                : 'Delete this webhook'
                        }
                        onClick={() => {
                            if (!confirmingDelete) {
                                setConfirmingDelete(true);

                                return;
                            }

                            router.delete(
                                destroyWebhook.url({
                                    workspace: workspaceSlug,
                                    webhook: webhook.id,
                                }),
                                { preserveScroll: true },
                            );
                        }}
                    >
                        <Trash2 aria-hidden="true" />
                        {/*
                         * Two clicks rather than a modal, matching the standups
                         * screen: the second click says what it takes with it,
                         * and a dialog for something done once a year is heavier
                         * than the risk. Deleting is also the *milder* of the
                         * two destructive actions here — turning the hook off is
                         * what stops the URL.
                         */}
                        {confirmingDelete ? 'Delete for good?' : ''}
                        <span className="sr-only">Delete {webhook.name}</span>
                    </Button>
                </div>
            </div>

            {webhook.url !== null && (
                <UrlField url={webhook.url} name={webhook.name} />
            )}
        </div>
    );
}

/**
 * The URL, with a copy button.
 *
 * Shown in full rather than masked behind a "reveal". A masked credential is
 * good practice where the secret is long-lived and rarely needed; this one is
 * needed *every* time somebody opens this page, and the mask would be one extra
 * click before the same string appeared on the same screen. The protection that
 * matters is the policy on the page, not a toggle on the field.
 */
function UrlField({ url, name }: { url: string; name: string }) {
    const [copied, setCopied] = useState(false);

    return (
        <div className="mt-2.5 flex items-center gap-2 border-t border-border pt-2.5">
            <code className="min-w-0 flex-1 truncate rounded bg-muted px-2 py-1 font-mono text-xs">
                {url}
            </code>

            <Button
                type="button"
                variant="outline"
                size="sm"
                aria-label={`Copy the URL for ${name}`}
                onClick={async () => {
                    /*
                     * `navigator.clipboard` is undefined on plain HTTP, which is
                     * how Snack is served locally — see the note in
                     * lib/nonce.ts about insecure-context APIs. Selecting the
                     * text is the honest fallback: the reader can still copy it,
                     * and nothing silently does nothing.
                     */
                    try {
                        await navigator.clipboard.writeText(url);
                        setCopied(true);
                        window.setTimeout(() => setCopied(false), 1500);
                    } catch {
                        window.prompt('Copy this URL', url);
                    }
                }}
            >
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

/**
 * What to send, quoting this installation's own limits.
 *
 * On the page rather than in a docs site because the person configuring a hook
 * is looking at this screen with a terminal open beside it, and the numbers come
 * from the server so they cannot drift from what it actually enforces.
 */
function UsagePanel({ limits }: { limits: Limits }) {
    return (
        <section className="rounded-xl border border-border bg-card px-4 py-3">
            <h2 className="text-sm font-semibold">Sending to a webhook</h2>

            <p className="mt-1 text-sm text-pretty text-muted-foreground">
                POST JSON with a <code className="text-xs">text</code> field.
                Slack-shaped payloads work as they are, so an existing
                integration usually just needs its URL changed.
            </p>

            <pre className="snack-scroll mt-2.5 overflow-x-auto rounded bg-muted px-3 py-2 font-mono text-xs">
                {`curl -X POST -H 'Content-Type: application/json' \\
  -d '{"text": "Deploy #482 shipped"}' \\
  <the URL above>`}
            </pre>

            <p className="mt-2.5 text-sm text-pretty text-muted-foreground">
                In a Laravel app:
            </p>

            <pre className="snack-scroll mt-1 overflow-x-auto rounded bg-muted px-3 py-2 font-mono text-xs">
                {`Http::post(config('services.snack.webhook'), [
    'text' => "Deploy {$build} shipped",
    'attachments' => [[
        'color' => 'good',
        'title' => "Build {$build}",
        'title_link' => $url,
        'fields' => [
            ['title' => 'Branch', 'value' => $branch, 'short' => true],
        ],
    ]],
]);`}
            </pre>

            <ul className="mt-2.5 space-y-1 text-xs text-muted-foreground tabular-nums">
                <li>
                    Up to {limits.max_text_length.toLocaleString()} characters
                    of text, {limits.max_attachments} attachments,{' '}
                    {limits.max_fields} fields each.
                </li>
                <li>
                    {limits.rate_limit_per_minute} deliveries a minute per
                    webhook.
                </li>
                <li>
                    Optional <code>username</code> and <code>icon_emoji</code>{' '}
                    change what one message is attributed to. The channel is
                    fixed and cannot be set by the payload.
                </li>
            </ul>
        </section>
    );
}

/**
 * The one form, for creating and for editing.
 *
 * Shared deliberately, with the channel field disabled on edit rather than
 * absent: an admin looking at the dialog should be able to see where the hook
 * points, and be told plainly why they cannot change it.
 */
function WebhookDialog({
    webhook,
    channels,
    workspaceSlug,
    onClose,
}: {
    webhook: IncomingWebhook | null;
    channels: ChannelOption[];
    workspaceSlug: string;
    onClose: () => void;
}) {
    const form = useForm({
        name: webhook?.name ?? '',
        channel_id: webhook?.channel.id ?? channels[0]?.id ?? 0,
        default_username: webhook?.default_username ?? '',
        default_icon: webhook?.default_icon ?? '',
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        const options = { preserveScroll: true, onSuccess: onClose } as const;

        if (webhook === null) {
            form.post(storeWebhook.url(workspaceSlug), options);

            return;
        }

        form.patch(
            updateWebhook.url({
                workspace: workspaceSlug,
                webhook: webhook.id,
            }),
            options,
        );
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-h-[85dvh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        {webhook === null ? 'New webhook' : 'Edit webhook'}
                    </DialogTitle>
                    <DialogDescription>
                        {webhook === null
                            ? 'You get a URL once it is created. Anything holding that URL can post to the channel you pick.'
                            : 'The channel is fixed — its URL is already out there.'}
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={submit} className="space-y-4">
                    <div className="space-y-2">
                        <Label htmlFor="webhook-name">Name</Label>
                        <Input
                            id="webhook-name"
                            value={form.data.name}
                            onChange={(event) =>
                                form.setData('name', event.target.value)
                            }
                            placeholder="Deploy notifications"
                            maxLength={80}
                            required
                            autoFocus
                        />
                        <p className="text-xs text-muted-foreground">
                            What it is called here, and what its messages are
                            attributed to in the channel.
                        </p>
                        <InputError message={form.errors.name} />
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="webhook-channel">Channel</Label>

                        <Select
                            value={String(form.data.channel_id)}
                            onValueChange={(value) =>
                                form.setData('channel_id', Number(value))
                            }
                            disabled={webhook !== null}
                        >
                            <SelectTrigger
                                id="webhook-channel"
                                className="w-full"
                            >
                                <SelectValue placeholder="Pick a channel" />
                            </SelectTrigger>
                            <SelectContent>
                                {channels.map((channel) => (
                                    <SelectItem
                                        key={channel.id}
                                        value={String(channel.id)}
                                    >
                                        #{channel.slug}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>

                        <p className="text-xs text-pretty text-muted-foreground">
                            {webhook === null
                                ? 'Everything this webhook posts lands here. It cannot be changed later, because the URL will already be in somebody else’s config.'
                                : 'To post somewhere else, create a new webhook and turn this one off.'}
                        </p>

                        <InputError message={form.errors.channel_id} />
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-2">
                            <Label htmlFor="webhook-username">
                                Post as (optional)
                            </Label>
                            <Input
                                id="webhook-username"
                                value={form.data.default_username}
                                onChange={(event) =>
                                    form.setData(
                                        'default_username',
                                        event.target.value,
                                    )
                                }
                                placeholder={form.data.name || 'CI'}
                                maxLength={80}
                            />
                            <InputError
                                message={form.errors.default_username}
                            />
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="webhook-icon">
                                Icon (optional)
                            </Label>
                            <Input
                                id="webhook-icon"
                                value={form.data.default_icon}
                                onChange={(event) =>
                                    form.setData(
                                        'default_icon',
                                        event.target.value,
                                    )
                                }
                                placeholder="rocket"
                                maxLength={64}
                            />
                            <p className="text-xs text-muted-foreground">
                                An emoji shortcode, no colons.
                            </p>
                            <InputError message={form.errors.default_icon} />
                        </div>
                    </div>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {webhook === null ? 'Create webhook' : 'Save'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
