import { Head, router, useForm } from '@inertiajs/react';
import {
    BookOpen,
    Hash,
    Lock,
    Megaphone,
    Pencil,
    Plus,
    Trash2,
    TriangleAlert,
} from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import { Textarea } from '@/components/ui/textarea';
import { useWorkspace } from '@/hooks/use-workspace';
import { cn } from '@/lib/utils';
import {
    destroy as destroyMemory,
    store as storeMemory,
    update as updateMemory,
} from '@/routes/bite-memories';
import { update as updateSettings } from '@/routes/bite-settings';
/*
 * The assistant's own face, imported rather than copied into `public/`.
 *
 * This is the same file App\Services\BiteBot processes into the bot's stored
 * avatar, so the picture on this screen and the one beside its messages cannot
 * drift apart — there is one image in the repository and Vite fingerprints it
 * for the browser.
 */
import biteLogo from '../../../images/bite-avatar.png';

type ChannelOption = {
    id: number;
    name: string;
    slug: string;
    type: string;
};

type Option = {
    value: string;
    label: string;
    description?: string;
};

type Capability = Option & { writes: boolean };

type Memory = {
    id: number;
    title: string;
    body: string;
    is_enabled: boolean;
    author: string | null;
    updated_at: string | null;
};

type Settings = {
    is_enabled: boolean;
    audience: string;
    channel_mode: string;
    channel_ids: number[];
    instructions: string | null;
    context_messages: number | null;
    max_steps: number | null;
    capabilities: string[];
};

type Props = {
    settings: Settings;
    is_configured: boolean;
    last_saved: { at: string | null; by: string | null } | null;
    /** Whether the installation itself has Bite switched on and keyed. */
    is_available: boolean;
    defaults: { context_messages: number; max_steps: number };
    limits: {
        instructions: number;
        memory_body: number;
        knowledge_total: number;
    };
    audiences: Option[];
    channel_modes: (Option & { uses_list: boolean })[];
    capabilities: Capability[];
    channels: ChannelOption[];
    memories: Memory[];
};

/**
 * What the assistant may do in this workspace, and what it knows.
 *
 * Admin-gated including the read — see App\Policies\BiteSettingPolicy.
 *
 * # Everything here narrows
 *
 * Not one control on this page can give Bite access to something. What any
 * given answer may contain is decided per person, per question, against the
 * asker's own permissions, and these settings are applied on top of that. The
 * page is written to say so, because an admin who believed otherwise would
 * reasonably expect the knowledge base to be a way of briefing the assistant
 * about things people cannot otherwise see.
 */
export default function BitePage({
    settings,
    is_configured,
    last_saved,
    is_available,
    defaults,
    limits,
    audiences,
    channel_modes,
    capabilities,
    channels,
    memories,
}: Props) {
    const workspace = useWorkspace();

    return (
        <>
            <Head title={`Bite · ${workspace.name}`} />

            <div className="snack-scroll min-w-0 flex-1 overflow-y-auto">
                <div className="mx-auto max-w-3xl space-y-8 px-6 py-8">
                    <header>
                        <h1 className="flex items-center gap-2.5 text-2xl font-bold tracking-tight">
                            <img
                                src={biteLogo}
                                alt=""
                                width={32}
                                height={32}
                                className="size-8 rounded-lg"
                            />
                            Bite
                        </h1>

                        <p className="mt-1 max-w-xl text-sm text-muted-foreground">
                            The workspace assistant. It answers when someone
                            types <code className="text-xs">@bite</code> in a
                            channel, or in a direct message with it.
                        </p>

                        <p className="mt-2 max-w-xl text-xs text-muted-foreground">
                            Bite only ever sees what the person asking can
                            already see. Nothing on this page widens that — the
                            controls here take capabilities away.
                        </p>

                        {!is_available && <UnavailableNotice />}

                        {is_configured && last_saved?.by !== null && (
                            <p className="mt-3 text-xs text-muted-foreground">
                                Last saved by {last_saved?.by}.
                            </p>
                        )}
                    </header>

                    <SettingsForm
                        settings={settings}
                        defaults={defaults}
                        limits={limits}
                        audiences={audiences}
                        channelModes={channel_modes}
                        capabilities={capabilities}
                        channels={channels}
                        workspaceSlug={workspace.slug}
                    />

                    <KnowledgeSection
                        memories={memories}
                        limits={limits}
                        workspaceSlug={workspace.slug}
                    />
                </div>
            </div>
        </>
    );
}

/**
 * The deployment has Bite switched off or unkeyed.
 *
 * Worth saying loudly, because every control below it will save happily and
 * change nothing — which is indistinguishable from the feature being broken.
 */
function UnavailableNotice() {
    return (
        <div className="mt-4 flex items-start gap-3 rounded-xl border border-amber-500/40 bg-amber-500/10 px-4 py-3">
            <TriangleAlert
                aria-hidden="true"
                className="mt-0.5 size-4 shrink-0 text-amber-500"
            />

            <div className="text-sm">
                <p className="font-semibold">
                    Bite is switched off for this installation.
                </p>
                <p className="mt-0.5 text-muted-foreground">
                    Whatever you set here is saved, but Bite will not answer
                    until whoever runs this server enables it and configures an
                    AI provider key.
                </p>
            </div>
        </div>
    );
}

function SettingsForm({
    settings,
    defaults,
    limits,
    audiences,
    channelModes,
    capabilities,
    channels,
    workspaceSlug,
}: {
    settings: Settings;
    defaults: Props['defaults'];
    limits: Props['limits'];
    audiences: Option[];
    channelModes: (Option & { uses_list: boolean })[];
    capabilities: Capability[];
    channels: ChannelOption[];
    workspaceSlug: string;
}) {
    const form = useForm({
        is_enabled: settings.is_enabled,
        audience: settings.audience,
        channel_mode: settings.channel_mode,
        channel_ids: settings.channel_ids,
        instructions: settings.instructions ?? '',
        /*
         * Empty string rather than null in the form's own state: a number input
         * bound to null renders as uncontrolled and React complains the first
         * time somebody types in it. It is turned back into null on submit,
         * which is what "use the installation default" is stored as.
         */
        context_messages: settings.context_messages?.toString() ?? '',
        max_steps: settings.max_steps?.toString() ?? '',
        capabilities: settings.capabilities,
    });

    const mode = channelModes.find(
        (option) => option.value === form.data.channel_mode,
    );

    const toggle = (key: 'capabilities', value: string) => {
        const current = form.data[key];

        form.setData(
            key,
            current.includes(value)
                ? current.filter((item) => item !== value)
                : [...current, value],
        );
    };

    const toggleChannel = (id: number) => {
        form.setData(
            'channel_ids',
            form.data.channel_ids.includes(id)
                ? form.data.channel_ids.filter((item) => item !== id)
                : [...form.data.channel_ids, id],
        );
    };

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        form.transform((data) => ({
            ...data,
            context_messages:
                data.context_messages === ''
                    ? null
                    : Number(data.context_messages),
            max_steps: data.max_steps === '' ? null : Number(data.max_steps),
        }));

        form.patch(updateSettings.url(workspaceSlug), {
            preserveScroll: true,
        });
    };

    return (
        <form onSubmit={submit} className="space-y-8">
            <Section
                title="Availability"
                description="Whether this workspace has an assistant, who may speak to it, and where."
            >
                <SwitchRow
                    label="Bite is on in this workspace"
                    description="Turning this off stops Bite answering anywhere, including in direct messages."
                    checked={form.data.is_enabled}
                    onChange={(next) => form.setData('is_enabled', next)}
                />

                <Field label="Who can summon Bite" htmlFor="audience">
                    <Select
                        value={form.data.audience}
                        onValueChange={(value) =>
                            form.setData('audience', value)
                        }
                    >
                        <SelectTrigger id="audience">
                            <SelectValue />
                        </SelectTrigger>

                        <SelectContent>
                            {audiences.map((option) => (
                                <SelectItem
                                    key={option.value}
                                    value={option.value}
                                >
                                    {option.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <p className="mt-1.5 text-xs text-muted-foreground">
                        {
                            audiences.find(
                                (option) => option.value === form.data.audience,
                            )?.description
                        }
                    </p>

                    <InputError message={form.errors.audience} />
                </Field>

                <Field label="Where Bite can answer" htmlFor="channel_mode">
                    <Select
                        value={form.data.channel_mode}
                        onValueChange={(value) =>
                            form.setData('channel_mode', value)
                        }
                    >
                        <SelectTrigger id="channel_mode">
                            <SelectValue />
                        </SelectTrigger>

                        <SelectContent>
                            {channelModes.map((option) => (
                                <SelectItem
                                    key={option.value}
                                    value={option.value}
                                >
                                    {option.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <p className="mt-1.5 text-xs text-muted-foreground">
                        Direct messages with Bite are never affected by this.
                    </p>

                    {mode?.uses_list === true && (
                        <ChannelPicker
                            channels={channels}
                            selected={form.data.channel_ids}
                            onToggle={toggleChannel}
                        />
                    )}

                    <InputError message={form.errors.channel_mode} />
                    <InputError message={form.errors.channel_ids} />
                </Field>
            </Section>

            <Section
                title="Behaviour"
                description="How Bite writes, and how hard it works on one answer."
            >
                <Field label="House instructions" htmlFor="instructions">
                    <Textarea
                        id="instructions"
                        rows={5}
                        value={form.data.instructions}
                        onChange={(event) =>
                            form.setData('instructions', event.target.value)
                        }
                        placeholder={
                            'Answer in British English. Anything about pricing, point at #sales rather than guessing.'
                        }
                    />

                    <p className="mt-1.5 flex justify-between gap-4 text-xs text-muted-foreground">
                        <span>
                            Added to Bite&rsquo;s own instructions. It cannot
                            replace them — the rules that stop it inventing
                            answers stay in force.
                        </span>
                        <span className="shrink-0 tabular-nums">
                            {form.data.instructions.length}/
                            {limits.instructions}
                        </span>
                    </p>

                    <InputError message={form.errors.instructions} />
                </Field>

                <div className="grid gap-4 sm:grid-cols-2">
                    <Field
                        label="Messages of context"
                        htmlFor="context_messages"
                    >
                        <Input
                            id="context_messages"
                            type="number"
                            min={2}
                            max={100}
                            value={form.data.context_messages}
                            onChange={(event) =>
                                form.setData(
                                    'context_messages',
                                    event.target.value,
                                )
                            }
                            placeholder={`Default (${defaults.context_messages})`}
                        />

                        <p className="mt-1.5 text-xs text-muted-foreground">
                            How much of the surrounding conversation Bite reads
                            before answering.
                        </p>

                        <InputError message={form.errors.context_messages} />
                    </Field>

                    <Field label="Maximum steps" htmlFor="max_steps">
                        <Input
                            id="max_steps"
                            type="number"
                            min={2}
                            max={16}
                            value={form.data.max_steps}
                            onChange={(event) =>
                                form.setData('max_steps', event.target.value)
                            }
                            placeholder={`Default (${defaults.max_steps})`}
                        />

                        <p className="mt-1.5 text-xs text-muted-foreground">
                            How many searches and reads it may chain together.
                            Higher answers harder questions and costs more.
                        </p>

                        <InputError message={form.errors.max_steps} />
                    </Field>
                </div>
            </Section>

            <Section
                title="What Bite can do"
                description="Switched off means the ability is never offered to the model at all, not that it is asked politely to avoid it."
            >
                <div className="space-y-3">
                    {capabilities.map((capability) => (
                        <label
                            key={capability.value}
                            className="flex items-start gap-3"
                        >
                            <Checkbox
                                checked={form.data.capabilities.includes(
                                    capability.value,
                                )}
                                onCheckedChange={() =>
                                    toggle('capabilities', capability.value)
                                }
                            />

                            <span className="grid gap-1">
                                <span className="flex items-center gap-2 text-sm font-medium">
                                    {capability.label}

                                    {capability.writes && (
                                        <span className="rounded-full bg-amber-500/15 px-2 py-0.5 text-[0.6875rem] font-semibold text-amber-600 dark:text-amber-400">
                                            Changes things
                                        </span>
                                    )}
                                </span>

                                <span className="text-xs text-muted-foreground">
                                    {capability.description}
                                </span>
                            </span>
                        </label>
                    ))}
                </div>

                <InputError message={form.errors.capabilities} />
            </Section>

            <div className="flex items-center gap-3">
                <Button type="submit" disabled={form.processing}>
                    Save settings
                </Button>

                {form.recentlySuccessful && (
                    <span className="text-sm text-muted-foreground">
                        Saved.
                    </span>
                )}
            </div>
        </form>
    );
}

/**
 * The channels the guard names.
 *
 * Private channels are in the list, and marked. An admin restricting an
 * assistant is usually thinking of exactly those, and they are being shown the
 * name rather than anything said in it.
 */
function ChannelPicker({
    channels,
    selected,
    onToggle,
}: {
    channels: ChannelOption[];
    selected: number[];
    onToggle: (id: number) => void;
}) {
    if (channels.length === 0) {
        return (
            <p className="mt-3 text-xs text-muted-foreground">
                This workspace has no channels yet.
            </p>
        );
    }

    return (
        <div className="snack-scroll mt-3 max-h-56 space-y-1 overflow-y-auto rounded-xl border border-border p-2">
            {channels.map((channel) => {
                const Icon =
                    channel.type === 'private'
                        ? Lock
                        : channel.type === 'announcement'
                          ? Megaphone
                          : Hash;

                return (
                    <label
                        key={channel.id}
                        className="flex items-center gap-2.5 rounded-lg px-2 py-1.5 transition-colors hover:bg-accent"
                    >
                        <Checkbox
                            checked={selected.includes(channel.id)}
                            onCheckedChange={() => onToggle(channel.id)}
                        />

                        <Icon
                            aria-hidden="true"
                            className="size-3.5 shrink-0 text-muted-foreground"
                        />

                        <span className="truncate text-sm">{channel.name}</span>
                    </label>
                );
            })}
        </div>
    );
}

/**
 * The facts Bite is told on every question.
 *
 * Its own section rather than a field on the form above, because these are rows
 * with their own lifecycle — added and removed one at a time — and folding them
 * into the settings form would mean losing a half-typed entry every time
 * somebody saved a checkbox.
 */
function KnowledgeSection({
    memories,
    limits,
    workspaceSlug,
}: {
    memories: Memory[];
    limits: Props['limits'];
    workspaceSlug: string;
}) {
    const [editing, setEditing] = useState<Memory | 'new' | null>(null);

    const used = memories
        .filter((memory) => memory.is_enabled)
        .reduce((total, memory) => total + memory.body.length, 0);

    return (
        <section className="space-y-4 border-t border-border pt-8">
            <header className="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 className="flex items-center gap-2 text-lg font-semibold">
                        <BookOpen aria-hidden="true" className="size-4" />
                        What Bite knows
                    </h2>

                    <p className="mt-1 max-w-xl text-sm text-muted-foreground">
                        Things that are true here and are written down nowhere
                        Bite could search — the release process, what the
                        acronyms mean, which staging environment is the real
                        one. Every entry is given to Bite on every question.
                    </p>
                </div>

                <Button type="button" onClick={() => setEditing('new')}>
                    <Plus aria-hidden="true" />
                    Add a fact
                </Button>
            </header>

            {memories.length === 0 ? (
                <div className="rounded-xl border border-dashed border-border px-6 py-10 text-center">
                    <img
                        src={biteLogo}
                        alt=""
                        width={40}
                        height={40}
                        className="mx-auto size-10 rounded-xl opacity-70"
                    />

                    <h3 className="mt-3 text-sm font-semibold">
                        Bite knows nothing in particular yet
                    </h3>

                    <p className="mx-auto mt-1 max-w-sm text-sm text-muted-foreground">
                        It can still read messages, files, and boards. This is
                        for the things nobody has written down.
                    </p>
                </div>
            ) : (
                <>
                    <ul className="space-y-2">
                        {memories.map((memory) => (
                            <li key={memory.id}>
                                <MemoryRow
                                    memory={memory}
                                    workspaceSlug={workspaceSlug}
                                    onEdit={() => setEditing(memory)}
                                />
                            </li>
                        ))}
                    </ul>

                    <BudgetBar used={used} total={limits.knowledge_total} />
                </>
            )}

            {editing !== null && (
                <MemoryDialog
                    /*
                     * Keyed so switching entries rebuilds the form. `useForm`
                     * seeds from its initial values, so without this the second
                     * entry opened would show the first one's text.
                     */
                    key={editing === 'new' ? 'new' : editing.id}
                    memory={editing === 'new' ? null : editing}
                    maxBody={limits.memory_body}
                    workspaceSlug={workspaceSlug}
                    onClose={() => setEditing(null)}
                />
            )}
        </section>
    );
}

/**
 * How much of the prompt budget the enabled entries take.
 *
 * Shown because the limit is otherwise invisible until the day an entry
 * silently stops reaching the model: entries past the cap are dropped whole,
 * bottom of the list first.
 */
function BudgetBar({ used, total }: { used: number; total: number }) {
    const share = Math.min(100, Math.round((used / total) * 100));
    const tight = share >= 80;

    return (
        <div className="space-y-1.5">
            <div className="h-1.5 overflow-hidden rounded-full bg-muted">
                <div
                    className={cn(
                        'h-full rounded-full transition-all',
                        tight ? 'bg-amber-500' : 'bg-brand',
                    )}
                    style={{ width: `${share}%` }}
                />
            </div>

            <p className="text-xs text-muted-foreground">
                {used.toLocaleString()} of {total.toLocaleString()} characters
                used.
                {tight &&
                    ' Entries past the limit are left out, starting from the bottom.'}
            </p>
        </div>
    );
}

function MemoryRow({
    memory,
    workspaceSlug,
    onEdit,
}: {
    memory: Memory;
    workspaceSlug: string;
    onEdit: () => void;
}) {
    const [confirming, setConfirming] = useState(false);

    const args = { workspace: workspaceSlug, biteMemory: memory.id };

    return (
        <div
            className={cn(
                'rounded-xl border border-border p-4 transition-opacity',
                !memory.is_enabled && 'opacity-60',
            )}
        >
            <div className="flex items-start justify-between gap-4">
                <div className="min-w-0">
                    <h3 className="truncate text-sm font-semibold">
                        {memory.title}
                    </h3>

                    <p className="mt-1 line-clamp-3 text-sm whitespace-pre-wrap text-muted-foreground">
                        {memory.body}
                    </p>

                    {memory.author !== null && (
                        <p className="mt-2 text-xs text-muted-foreground/70">
                            Written by {memory.author}
                        </p>
                    )}
                </div>

                <div className="flex shrink-0 items-center gap-1">
                    <label
                        className="mr-1 flex items-center gap-2 text-xs text-muted-foreground"
                        title={
                            memory.is_enabled
                                ? 'Bite is being told this'
                                : 'Bite is not being told this'
                        }
                    >
                        <Checkbox
                            checked={memory.is_enabled}
                            onCheckedChange={(next) =>
                                router.patch(
                                    updateMemory.url(args),
                                    { is_enabled: next === true },
                                    { preserveScroll: true },
                                )
                            }
                        />
                        In use
                    </label>

                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        onClick={onEdit}
                    >
                        <Pencil aria-hidden="true" className="size-4" />
                        <span className="sr-only">Edit {memory.title}</span>
                    </Button>

                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        onClick={() => setConfirming(true)}
                    >
                        <Trash2 aria-hidden="true" className="size-4" />
                        <span className="sr-only">Delete {memory.title}</span>
                    </Button>
                </div>
            </div>

            <Dialog open={confirming} onOpenChange={setConfirming}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Delete “{memory.title}”?</DialogTitle>
                        <DialogDescription>
                            Bite will stop being told this. If you only want to
                            pause it, untick &ldquo;In use&rdquo; instead — that
                            keeps the text.
                        </DialogDescription>
                    </DialogHeader>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => setConfirming(false)}
                        >
                            Cancel
                        </Button>

                        <Button
                            type="button"
                            variant="destructive"
                            onClick={() =>
                                router.delete(destroyMemory.url(args), {
                                    preserveScroll: true,
                                    onFinish: () => setConfirming(false),
                                })
                            }
                        >
                            Delete
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}

function MemoryDialog({
    memory,
    maxBody,
    workspaceSlug,
    onClose,
}: {
    memory: Memory | null;
    maxBody: number;
    workspaceSlug: string;
    onClose: () => void;
}) {
    const form = useForm({
        title: memory?.title ?? '',
        body: memory?.body ?? '',
        is_enabled: memory?.is_enabled ?? true,
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        const options = { preserveScroll: true, onSuccess: onClose };

        if (memory === null) {
            form.post(storeMemory.url(workspaceSlug), options);

            return;
        }

        form.patch(
            updateMemory.url({
                workspace: workspaceSlug,
                biteMemory: memory.id,
            }),
            options,
        );
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        {memory === null ? 'Add a fact' : 'Edit this fact'}
                    </DialogTitle>

                    <DialogDescription>
                        Write it the way you would tell a new colleague. Bite is
                        given this on every question, so keep it to things that
                        stay true.
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={submit} className="space-y-4">
                    <div className="grid gap-1.5">
                        <Label htmlFor="memory-title">Title</Label>

                        <Input
                            id="memory-title"
                            value={form.data.title}
                            onChange={(event) =>
                                form.setData('title', event.target.value)
                            }
                            placeholder="Release process"
                            autoFocus
                        />

                        <InputError message={form.errors.title} />
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="memory-body">
                            What Bite should know
                        </Label>

                        <Textarea
                            id="memory-body"
                            rows={6}
                            value={form.data.body}
                            onChange={(event) =>
                                form.setData('body', event.target.value)
                            }
                            placeholder="We ship on Thursdays. Anything going out on a Friday needs sign-off from whoever is on call."
                        />

                        <p className="flex justify-end text-xs text-muted-foreground tabular-nums">
                            {form.data.body.length}/{maxBody}
                        </p>

                        <InputError message={form.errors.body} />
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={onClose}>
                            Cancel
                        </Button>

                        <Button type="submit" disabled={form.processing}>
                            {memory === null ? 'Add' : 'Save'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function Section({
    title,
    description,
    children,
}: {
    title: string;
    description: string;
    children: React.ReactNode;
}) {
    return (
        <section className="space-y-4">
            <div>
                <h2 className="text-lg font-semibold">{title}</h2>
                <p className="mt-1 max-w-xl text-sm text-muted-foreground">
                    {description}
                </p>
            </div>

            {children}
        </section>
    );
}

function Field({
    label,
    htmlFor,
    children,
}: {
    label: string;
    htmlFor: string;
    children: React.ReactNode;
}) {
    return (
        <div className="grid gap-1.5">
            <Label htmlFor={htmlFor}>{label}</Label>
            {children}
        </div>
    );
}

/**
 * A checkbox that reads as a switch.
 *
 * The primitive rather than a Switch component, because the project does not
 * ship one and a checkbox with a label is the same control to a screen reader.
 */
function SwitchRow({
    label,
    description,
    checked,
    onChange,
}: {
    label: string;
    description: string;
    checked: boolean;
    onChange: (next: boolean) => void;
}) {
    return (
        <label className="flex items-start gap-3 rounded-xl border border-border p-4">
            <Checkbox
                checked={checked}
                onCheckedChange={(next) => onChange(next === true)}
            />

            <span className="grid gap-1">
                <span className="text-sm font-medium">{label}</span>
                <span className="text-xs text-muted-foreground">
                    {description}
                </span>
            </span>
        </label>
    );
}
