import { Head, router, useForm } from '@inertiajs/react';
import {
    Check,
    Copy,
    Plug,
    Plus,
    Power,
    PowerOff,
    RefreshCw,
    ShieldCheck,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { WebhookDocs } from '@/components/snack/webhook-docs';
import type { WebhookLimits } from '@/components/snack/webhook-docs';
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
    restore as restoreWebhook,
    revoke as revokeWebhook,
    signing as signingWebhook,
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

type Props = {
    webhooks: IncomingWebhook[];
    channels: ChannelOption[];
    limits: WebhookLimits;
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
                                /*
                                 * Keyed on the states the row's buttons act on,
                                 * not just the id, so flipping one remounts the
                                 * row and discards the half-finished
                                 * confirmations inside it.
                                 *
                                 * Without this the flags outlive the action
                                 * that consumed them: turning a hook off armed
                                 * "Turn it off?", the row switched to its
                                 * revoked shape, and turning it back on
                                 * restored a button that was *already* asking
                                 * to be clicked a second time — handing an
                                 * admin who had just undone an accident a
                                 * hair-trigger for repeating it. The signing
                                 * controls have exactly the same shape and
                                 * would have grown exactly the same bug.
                                 */
                                <li
                                    key={[
                                        webhook.id,
                                        webhook.is_revoked,
                                        webhook.signing_required,
                                    ].join('-')}
                                >
                                    <WebhookRow
                                        webhook={webhook}
                                        workspaceSlug={workspace.slug}
                                        onEdit={() => setEditing(webhook)}
                                    />
                                </li>
                            ))}
                        </ul>
                    )}

                    <WebhookDocs limits={limits} />
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
    const [confirmingRevoke, setConfirmingRevoke] = useState(false);

    /*
     * Restoring gets a dialog where turning off and deleting get a second click.
     *
     * Not because it is more destructive — it is the least destructive of the
     * three — but because it is the only one whose consequence is not obvious
     * from the button. "Turn on" reads as harmless, and for the one case that
     * matters, a hook switched off because its URL leaked, it is the opposite.
     * That needs a sentence, and a sentence needs somewhere to live.
     */
    const [confirmingRestore, setConfirmingRestore] = useState(false);

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

                        {webhook.signing_required && (
                            /*
                             * Worth a badge because it changes what the URL
                             * below is worth. An admin scanning this list for
                             * "which of these would matter if it leaked" is
                             * asking exactly this question.
                             */
                            <span className="flex items-center gap-1 rounded bg-muted px-1.5 py-0.5 text-xs text-muted-foreground">
                                <ShieldCheck
                                    aria-hidden="true"
                                    className="size-3"
                                />
                                Signed
                            </span>
                        )}

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
                    {webhook.is_revoked ? (
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            title="Turn this webhook back on with the URL it had before"
                            onClick={() => setConfirmingRestore(true)}
                        >
                            <Power aria-hidden="true" />
                            Turn on
                        </Button>
                    ) : (
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
                                title={
                                    confirmingRevoke
                                        ? 'Every system holding this URL stops working'
                                        : 'Turn this webhook off'
                                }
                                onClick={() => {
                                    if (!confirmingRevoke) {
                                        setConfirmingRevoke(true);

                                        return;
                                    }

                                    router.patch(
                                        revokeWebhook.url({
                                            workspace: workspaceSlug,
                                            webhook: webhook.id,
                                        }),
                                        {},
                                        { preserveScroll: true },
                                    );
                                }}
                            >
                                <PowerOff aria-hidden="true" />
                                {/*
                                 * Two clicks, matching Delete beside it. This
                                 * was one click on a bare icon, which is how a
                                 * URL somebody else depends on gets switched
                                 * off by a misaimed cursor.
                                 */}
                                {confirmingRevoke ? 'Turn it off?' : ''}
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
                <>
                    <UrlField url={webhook.url} name={webhook.name} />
                    <SigningPanel
                        webhook={webhook}
                        workspaceSlug={workspaceSlug}
                    />
                </>
            )}

            <Dialog
                open={confirmingRestore}
                onOpenChange={setConfirmingRestore}
            >
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Turn {webhook.name} back on?</DialogTitle>
                        <DialogDescription>
                            It gets the URL it had before, so anything still
                            configured with it starts posting to #
                            {webhook.channel.slug} again without being changed.
                        </DialogDescription>
                    </DialogHeader>

                    {/*
                     * The one thing this dialog exists to say. Nothing on the
                     * row records *why* somebody turned a hook off, so the
                     * server cannot tell a misaimed click from a leaked URL —
                     * only the person reading this can.
                     */}
                    <p className="text-sm text-pretty text-muted-foreground">
                        If you turned it off because the URL leaked, delete it
                        instead and create a new one — restoring brings the
                        leaked URL back to life.
                    </p>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setConfirmingRestore(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="button"
                            onClick={() => {
                                router.patch(
                                    restoreWebhook.url({
                                        workspace: workspaceSlug,
                                        webhook: webhook.id,
                                    }),
                                    {},
                                    {
                                        preserveScroll: true,
                                        onFinish: () =>
                                            setConfirmingRestore(false),
                                    },
                                );
                            }}
                        >
                            <Power aria-hidden="true" />
                            Turn it back on
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
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
    return (
        <div className="mt-2.5 border-t border-border pt-2.5">
            <CopyableSecret
                value={url}
                label={`Copy the URL for ${name}`}
                promptTitle="Copy this URL"
            />
        </div>
    );
}

/**
 * A credential with a copy button.
 *
 * Shared by the URL and the signing secret, which behave identically: both are
 * read off this screen every time somebody wires up an integration, and both are
 * useless to whoever cannot open it.
 */
function CopyableSecret({
    value,
    label,
    promptTitle,
}: {
    value: string;
    label: string;
    promptTitle: string;
}) {
    const [copied, setCopied] = useState(false);

    return (
        <div className="flex items-center gap-2">
            <code className="min-w-0 flex-1 truncate rounded bg-muted px-2 py-1 font-mono text-xs">
                {value}
            </code>

            <Button
                type="button"
                variant="outline"
                size="sm"
                aria-label={label}
                onClick={async () => {
                    /*
                     * `navigator.clipboard` is undefined on plain HTTP, which is
                     * how Snack is served locally — see the note in
                     * lib/nonce.ts about insecure-context APIs. Prompting with
                     * the value is the honest fallback: the reader can still
                     * copy it, and nothing silently does nothing.
                     */
                    try {
                        await navigator.clipboard.writeText(value);
                        setCopied(true);
                        window.setTimeout(() => setCopied(false), 1500);
                    } catch {
                        window.prompt(promptTitle, value);
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
 * The optional signing secret: whether this hook demands one, and the code to
 * satisfy it.
 *
 * # Why the example lives here and not in the panel below
 *
 * Because signing is the one part of this API that is easy to get subtly wrong,
 * and the mistake is always the same: signing a payload and then letting the
 * HTTP client re-encode it, so the bytes that arrive are not the bytes that were
 * signed. The snippet that avoids it belongs beside the secret it uses, where
 * somebody copying one will read the other.
 */
function SigningPanel({
    webhook,
    workspaceSlug,
}: {
    webhook: IncomingWebhook;
    workspaceSlug: string;
}) {
    const [confirmingOff, setConfirmingOff] = useState(false);
    const [confirmingRotate, setConfirmingRotate] = useState(false);

    const submit = (mode: 'enable' | 'disable' | 'rotate') =>
        router.patch(
            signingWebhook.url({
                workspace: workspaceSlug,
                webhook: webhook.id,
            }),
            { mode },
            { preserveScroll: true },
        );

    if (!webhook.signing_required) {
        return (
            <div className="mt-2.5 flex flex-wrap items-center justify-between gap-2 border-t border-border pt-2.5">
                <p className="text-xs text-pretty text-muted-foreground">
                    Anyone with this URL can post to #{webhook.channel.slug}.
                    Require a signature and the URL alone stops being enough.
                </p>

                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() => submit('enable')}
                >
                    <ShieldCheck aria-hidden="true" />
                    Require signing
                </Button>
            </div>
        );
    }

    return (
        <div className="mt-2.5 space-y-2 border-t border-border pt-2.5">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="text-xs font-medium">Signing secret</p>

                <div className="flex items-center gap-2">
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        title={
                            confirmingRotate
                                ? 'Anything still signing with the current secret will be refused'
                                : 'Replace the secret — use this if it leaked'
                        }
                        onClick={() => {
                            if (!confirmingRotate) {
                                setConfirmingRotate(true);

                                return;
                            }

                            submit('rotate');
                        }}
                    >
                        <RefreshCw aria-hidden="true" />
                        {confirmingRotate ? 'Replace it?' : 'Regenerate'}
                    </Button>

                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        title={
                            confirmingOff
                                ? 'The URL on its own becomes enough again'
                                : 'Stop requiring signatures'
                        }
                        onClick={() => {
                            if (!confirmingOff) {
                                setConfirmingOff(true);

                                return;
                            }

                            submit('disable');
                        }}
                    >
                        {confirmingOff ? 'Turn signing off?' : 'Turn off'}
                    </Button>
                </div>
            </div>

            {webhook.signing_secret !== null && (
                <CopyableSecret
                    value={webhook.signing_secret}
                    label={`Copy the signing secret for ${webhook.name}`}
                    promptTitle="Copy this signing secret"
                />
            )}

            <pre className="snack-scroll overflow-x-auto rounded bg-muted px-3 py-2 font-mono text-xs">
                {`$body = json_encode(['text' => 'Deploy #482 shipped']);
$ts = time();

Http::withHeaders([
    'X-Snack-Timestamp' => $ts,
    'X-Snack-Signature' => 'v1='.hash_hmac('sha256', "v1:{$ts}:{$body}", $secret),
])->withBody($body, 'application/json')->post($url);`}
            </pre>

            <p className="text-xs text-pretty text-muted-foreground">
                Sign the exact bytes you send —{' '}
                <code className="text-[0.6875rem]">withBody</code>, not{' '}
                <code className="text-[0.6875rem]">post($url, $array)</code>,
                which re-encodes after you have signed. Timestamps more than
                five minutes from the server&rsquo;s clock are refused, so a
                captured request cannot be replayed later.
            </p>
        </div>
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
