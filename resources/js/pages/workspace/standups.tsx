import { Head, router, useForm } from '@inertiajs/react';
import {
    ClipboardList,
    GripVertical,
    Pause,
    Play,
    Plus,
    Trash2,
    X,
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
import { useWorkspace } from '@/hooks/use-workspace';
import { cn } from '@/lib/utils';
import {
    destroy as destroyStandup,
    store as storeStandup,
    update as updateStandup,
} from '@/routes/standups';
import type { Standup } from '@/types';

/** ISO weekday numbers, in the order a working week runs. */
const WEEKDAYS = [
    { value: 1, short: 'Mon' },
    { value: 2, short: 'Tue' },
    { value: 3, short: 'Wed' },
    { value: 4, short: 'Thu' },
    { value: 5, short: 'Fri' },
    { value: 6, short: 'Sat' },
    { value: 7, short: 'Sun' },
];

type ChannelOption = {
    id: number;
    name: string;
    slug: string;
    type: string;
};

type Defaults = {
    questions: string[];
    days_of_week: number[];
    ask_at: string;
    timezone: string;
    reminder_after_minutes: number;
    closes_after_minutes: number;
};

type Props = {
    standups: Standup[];
    channels: ChannelOption[];
    timezones: string[];
    defaults: Defaults;
    bot: { name: string; username: string; exists: boolean };
    max_questions: number;
};

/**
 * The standup app: what it asks, who it asks, and when.
 *
 * Admin-gated, because a standup schedules recurring direct messages to
 * everybody in a channel — the one thing an ordinary member could otherwise set
 * up that lands in other people's inboxes without their asking.
 *
 * # Why the roster is not on this page
 *
 * There is no participant picker, and that is the design rather than an
 * omission. The roster *is* the channel's membership, so the way to add
 * somebody to the standup is to add them to the team's channel — one list, one
 * place, and no daily opportunity for the two to disagree about who is on the
 * team.
 */
export default function StandupsPage({
    standups,
    channels,
    timezones,
    defaults,
    bot,
    max_questions,
}: Props) {
    const workspace = useWorkspace();

    // `null` closes the dialog, a standup opens it on that one, and `'new'`
    // opens it empty. One piece of state rather than three booleans that can
    // contradict each other.
    const [editing, setEditing] = useState<Standup | 'new' | null>(null);

    return (
        <>
            <Head title={`Standups · ${workspace.name}`} />

            <div className="snack-scroll min-w-0 flex-1 overflow-y-auto">
                <div className="mx-auto max-w-3xl space-y-6 px-6 py-8">
                    <header className="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <h1 className="text-2xl font-bold tracking-tight">
                                Standups
                            </h1>
                            <p className="mt-1 max-w-xl text-sm text-muted-foreground">
                                {bot.name} messages everyone in a channel on a
                                schedule, collects their answers privately, and
                                posts the write-up back to that channel.
                            </p>
                        </div>

                        <Button type="button" onClick={() => setEditing('new')}>
                            <Plus aria-hidden="true" />
                            New standup
                        </Button>
                    </header>

                    {standups.length === 0 ? (
                        <EmptyState onCreate={() => setEditing('new')} />
                    ) : (
                        <ul className="space-y-3">
                            {standups.map((standup) => (
                                <li key={standup.id}>
                                    <StandupRow
                                        standup={standup}
                                        workspaceSlug={workspace.slug}
                                        onEdit={() => setEditing(standup)}
                                    />
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            </div>

            {editing !== null && (
                <StandupDialog
                    /*
                     * Keyed so switching from one standup to another rebuilds
                     * the form rather than reusing the previous one's state —
                     * `useForm` seeds from its initial values, so without this
                     * the second standup opened would show the first's answers.
                     */
                    key={editing === 'new' ? 'new' : editing.id}
                    standup={editing === 'new' ? null : editing}
                    channels={channels}
                    timezones={timezones}
                    defaults={defaults}
                    maxQuestions={max_questions}
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
            <ClipboardList
                aria-hidden="true"
                className="mx-auto size-8 text-muted-foreground"
            />

            <h2 className="mt-3 text-sm font-semibold">No standups yet</h2>

            <p className="mx-auto mt-1 max-w-sm text-sm text-muted-foreground">
                Pick a channel and a few questions. Everyone in that channel
                gets asked on the days you choose, and their answers are posted
                back there as one message.
            </p>

            <Button
                type="button"
                variant="outline"
                className="mt-4"
                onClick={onCreate}
            >
                <Plus aria-hidden="true" />
                Create a standup
            </Button>
        </div>
    );
}

function StandupRow({
    standup,
    workspaceSlug,
    onEdit,
}: {
    standup: Standup;
    workspaceSlug: string;
    onEdit: () => void;
}) {
    const [confirming, setConfirming] = useState(false);

    return (
        <div
            className={cn(
                'rounded-xl border border-border bg-card px-4 py-3',
                !standup.is_active && 'opacity-70',
            )}
        >
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                        <h2 className="text-sm font-semibold">
                            {standup.name}
                        </h2>

                        <span className="rounded bg-muted px-1.5 py-0.5 text-xs text-muted-foreground">
                            #{standup.channel.slug}
                        </span>

                        {!standup.is_active && (
                            <span className="flex items-center gap-1 rounded bg-muted px-1.5 py-0.5 text-xs text-muted-foreground">
                                <Pause aria-hidden="true" className="size-3" />
                                Paused
                            </span>
                        )}
                    </div>

                    <p className="mt-1 text-sm text-muted-foreground">
                        {standup.schedule_summary}
                    </p>

                    <p className="mt-0.5 text-xs text-muted-foreground">
                        {standup.questions.length} question
                        {standup.questions.length === 1 ? '' : 's'}
                        {standup.participant_count !== undefined && (
                            <>
                                {' · '}
                                {standup.participant_count} to ask
                            </>
                        )}
                        {standup.reminder_after_minutes !== null && (
                            <>
                                {' · '}
                                reminder after{' '}
                                {formatMinutes(standup.reminder_after_minutes)}
                            </>
                        )}
                        {' · '}
                        posts after{' '}
                        {formatMinutes(standup.closes_after_minutes)}
                    </p>

                    {standup.last_run !== null && (
                        <p className="mt-1 text-xs text-muted-foreground">
                            Last run {standup.last_run.date_label}:{' '}
                            {standup.last_run.answered} of{' '}
                            {standup.last_run.asked} answered
                            {standup.last_run.state === 'collecting'
                                ? ' — still open'
                                : ''}
                        </p>
                    )}
                </div>

                <div className="flex shrink-0 items-center gap-2">
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
                            confirming
                                ? 'Deleting also removes every answer it has collected'
                                : 'Delete this standup'
                        }
                        onClick={() => {
                            if (!confirming) {
                                setConfirming(true);

                                return;
                            }

                            router.delete(
                                destroyStandup.url({
                                    workspace: workspaceSlug,
                                    standup: standup.id,
                                }),
                                { preserveScroll: true },
                            );
                        }}
                    >
                        <Trash2 aria-hidden="true" />
                        {/*
                         * Two clicks rather than a browser confirm(): a modal
                         * dialog for a destructive action people take once a
                         * year is heavier than the risk, and the second click
                         * says what it will take with it.
                         */}
                        {confirming ? 'Delete for good?' : ''}
                    </Button>
                </div>
            </div>

            <ol className="mt-2.5 space-y-1 border-t border-border pt-2.5">
                {standup.questions.map((question, index) => (
                    <li
                        key={question}
                        className="text-xs text-muted-foreground"
                    >
                        {index + 1}. {question}
                    </li>
                ))}
            </ol>
        </div>
    );
}

/**
 * The one form, for creating and for editing.
 *
 * Shared deliberately: the fields are identical, and two copies of a form with
 * seven fields and a repeater is how the edit path quietly loses a control the
 * create path gained.
 */
function StandupDialog({
    standup,
    channels,
    timezones,
    defaults,
    maxQuestions,
    workspaceSlug,
    onClose,
}: {
    standup: Standup | null;
    channels: ChannelOption[];
    timezones: string[];
    defaults: Defaults;
    maxQuestions: number;
    workspaceSlug: string;
    onClose: () => void;
}) {
    const form = useForm({
        name: standup?.name ?? 'Daily standup',
        channel_id: standup?.channel.id ?? channels[0]?.id ?? 0,
        questions: standup?.questions ?? defaults.questions,
        days_of_week: standup?.days_of_week ?? defaults.days_of_week,
        ask_at: standup?.ask_at ?? defaults.ask_at,
        timezone: standup?.timezone ?? defaults.timezone,
        /*
         * A nullable number in a form: "" is the empty box and null is what the
         * server needs to mean "do not chase". Kept as a string here and
         * converted on submit, because a controlled number input that flips to
         * null while you are deleting digits fights the person typing.
         */
        reminder_after_minutes:
            standup === null
                ? String(defaults.reminder_after_minutes)
                : (standup.reminder_after_minutes?.toString() ?? ''),
        closes_after_minutes: String(
            standup?.closes_after_minutes ?? defaults.closes_after_minutes,
        ),
        is_active: standup?.is_active ?? true,
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        const options = {
            preserveScroll: true,
            onSuccess: onClose,
        } as const;

        /*
         * The two minute fields are held as strings and converted on the way
         * out. A controlled number input that flips to null while somebody is
         * deleting digits fights the person typing — and an empty box has to
         * reach the server as null rather than 0, because "do not chase" and
         * "chase immediately" are opposite instructions.
         */
        form.transform((data) => ({
            ...data,
            reminder_after_minutes:
                data.reminder_after_minutes.trim() === ''
                    ? null
                    : Number(data.reminder_after_minutes),
            closes_after_minutes: Number(data.closes_after_minutes),
        }));

        if (standup === null) {
            form.post(storeStandup.url(workspaceSlug), options);

            return;
        }

        form.patch(
            updateStandup.url({
                workspace: workspaceSlug,
                standup: standup.id,
            }),
            options,
        );
    };

    const setQuestion = (index: number, value: string) =>
        form.setData(
            'questions',
            form.data.questions.map((question, position) =>
                position === index ? value : question,
            ),
        );

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-h-[85vh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        {standup === null ? 'New standup' : 'Edit standup'}
                    </DialogTitle>
                    <DialogDescription>
                        Everyone in the channel is asked privately, and the
                        answers are posted there as one message.
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={submit} className="space-y-4">
                    <div className="space-y-2">
                        <Label htmlFor="standup-name">Name</Label>
                        <Input
                            id="standup-name"
                            value={form.data.name}
                            onChange={(event) =>
                                form.setData('name', event.target.value)
                            }
                            maxLength={80}
                            required
                            autoFocus
                        />
                        <InputError message={form.errors.name} />
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="standup-channel">Channel</Label>

                        <Select
                            value={String(form.data.channel_id)}
                            onValueChange={(value) =>
                                form.setData('channel_id', Number(value))
                            }
                        >
                            <SelectTrigger
                                id="standup-channel"
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

                        <p className="text-xs text-muted-foreground">
                            Its members are who gets asked. Adding somebody to
                            the standup means adding them to this channel.
                        </p>

                        <InputError message={form.errors.channel_id} />
                    </div>

                    <fieldset className="space-y-2">
                        <legend className="text-sm font-medium">
                            Questions
                        </legend>

                        {form.data.questions.map((question, index) => (
                            <div
                                // Index-keyed on purpose: these rows are
                                // reorderable-by-editing text boxes, and keying
                                // on the value would remount the input the
                                // moment somebody typed into it.
                                key={index}
                                className="flex items-center gap-1.5"
                            >
                                <GripVertical
                                    aria-hidden="true"
                                    className="size-4 shrink-0 text-muted-foreground/50"
                                />

                                <Input
                                    value={question}
                                    onChange={(event) =>
                                        setQuestion(index, event.target.value)
                                    }
                                    placeholder={`Question ${index + 1}`}
                                    maxLength={200}
                                    aria-label={`Question ${index + 1}`}
                                />

                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    disabled={form.data.questions.length === 1}
                                    aria-label={`Remove question ${index + 1}`}
                                    onClick={() =>
                                        form.setData(
                                            'questions',
                                            form.data.questions.filter(
                                                (_, position) =>
                                                    position !== index,
                                            ),
                                        )
                                    }
                                >
                                    <X aria-hidden="true" />
                                </Button>
                            </div>
                        ))}

                        {form.data.questions.length < maxQuestions && (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    form.setData('questions', [
                                        ...form.data.questions,
                                        '',
                                    ])
                                }
                            >
                                <Plus aria-hidden="true" />
                                Add question
                            </Button>
                        )}

                        <InputError message={form.errors.questions} />
                    </fieldset>

                    <fieldset className="space-y-2">
                        <legend className="text-sm font-medium">Days</legend>

                        <div className="flex flex-wrap gap-1.5">
                            {WEEKDAYS.map((day) => {
                                const on = form.data.days_of_week.includes(
                                    day.value,
                                );

                                return (
                                    <button
                                        key={day.value}
                                        type="button"
                                        aria-pressed={on}
                                        onClick={() =>
                                            form.setData(
                                                'days_of_week',
                                                on
                                                    ? form.data.days_of_week.filter(
                                                          (value) =>
                                                              value !==
                                                              day.value,
                                                      )
                                                    : [
                                                          ...form.data
                                                              .days_of_week,
                                                          day.value,
                                                      ].sort((a, b) => a - b),
                                            )
                                        }
                                        className={cn(
                                            'rounded-lg border px-2.5 py-1.5 text-xs font-medium transition-colors',
                                            on
                                                ? 'border-transparent bg-primary text-primary-foreground'
                                                : 'border-border text-muted-foreground hover:bg-accent',
                                        )}
                                    >
                                        {day.short}
                                    </button>
                                );
                            })}
                        </div>

                        <InputError message={form.errors.days_of_week} />
                    </fieldset>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-2">
                            <Label htmlFor="standup-time">Ask at</Label>
                            <Input
                                id="standup-time"
                                type="time"
                                value={form.data.ask_at}
                                onChange={(event) =>
                                    form.setData('ask_at', event.target.value)
                                }
                                required
                            />
                            <InputError message={form.errors.ask_at} />
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="standup-timezone">Timezone</Label>

                            <Select
                                value={form.data.timezone}
                                onValueChange={(value) =>
                                    form.setData('timezone', value)
                                }
                            >
                                <SelectTrigger
                                    id="standup-timezone"
                                    className="w-full"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {timezones.map((zone) => (
                                        <SelectItem key={zone} value={zone}>
                                            {zone}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>

                            <p className="text-xs text-muted-foreground">
                                The team's clock, not each person's — a standup
                                is one moment.
                            </p>

                            <InputError message={form.errors.timezone} />
                        </div>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-2">
                            <Label htmlFor="standup-reminder">
                                Remind after (minutes)
                            </Label>
                            <Input
                                id="standup-reminder"
                                type="number"
                                min={1}
                                max={1440}
                                value={form.data.reminder_after_minutes}
                                onChange={(event) =>
                                    form.setData(
                                        'reminder_after_minutes',
                                        event.target.value,
                                    )
                                }
                                placeholder="Never"
                            />
                            <p className="text-xs text-muted-foreground">
                                Leave empty to never chase.
                            </p>
                            <InputError
                                message={form.errors.reminder_after_minutes}
                            />
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="standup-closes">
                                Post after (minutes)
                            </Label>
                            <Input
                                id="standup-closes"
                                type="number"
                                min={1}
                                max={1440}
                                value={form.data.closes_after_minutes}
                                onChange={(event) =>
                                    form.setData(
                                        'closes_after_minutes',
                                        event.target.value,
                                    )
                                }
                                required
                            />
                            <p className="text-xs text-muted-foreground">
                                Posts sooner if everyone answers first.
                            </p>
                            <InputError
                                message={form.errors.closes_after_minutes}
                            />
                        </div>
                    </div>

                    <label className="flex items-start gap-2.5">
                        <Checkbox
                            checked={form.data.is_active}
                            onCheckedChange={(checked) =>
                                form.setData('is_active', checked === true)
                            }
                            className="mt-0.5"
                        />

                        <span>
                            <span className="flex items-center gap-1.5 text-sm font-medium">
                                {form.data.is_active ? (
                                    <Play
                                        aria-hidden="true"
                                        className="size-3.5"
                                    />
                                ) : (
                                    <Pause
                                        aria-hidden="true"
                                        className="size-3.5"
                                    />
                                )}
                                Running
                            </span>
                            <span className="block text-xs text-muted-foreground">
                                Pausing keeps the questions and every past
                                answer; it just stops asking.
                            </span>
                        </span>
                    </label>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                        >
                            Cancel
                        </Button>

                        <Button type="submit" disabled={form.processing}>
                            {standup === null
                                ? 'Create standup'
                                : 'Save changes'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/**
 * "90 minutes" reads worse than "1h 30m" on a row people scan.
 */
function formatMinutes(minutes: number): string {
    if (minutes < 60) {
        return `${minutes}m`;
    }

    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;

    return rest === 0 ? `${hours}h` : `${hours}h ${rest}m`;
}
