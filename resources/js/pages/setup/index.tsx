import { Head, useForm } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRight,
    Check,
    Hash,
    Mail,
    Plus,
    Sparkles,
    Trash2,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { SnackLogo } from '@/components/snack/snack-logo';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import { store } from '@/routes/setup';

type Props = {
    passwordRules: string;
    maxInvitations: number;
    defaultChannels: string[];
};

type SetupForm = {
    admin_name: string;
    admin_email: string;
    password: string;
    password_confirmation: string;
    workspace_name: string;
    workspace_description: string;
    invitations: string[];
};

const STEPS = [
    { title: 'Your account', blurb: 'You will be the workspace owner.' },
    { title: 'Your workspace', blurb: 'Where your team will talk.' },
    { title: 'Your team', blurb: 'Invite people, or do it later.' },
] as const;

/**
 * Which step owns each field, so a server-side rejection can send the person
 * back to the screen holding the offending input. Without this an error on the
 * password would render on step one while the user sat on step three, and the
 * form would look like it silently refused to submit.
 */
const FIELD_STEPS: Record<string, number> = {
    admin_name: 0,
    admin_email: 0,
    password: 0,
    password_confirmation: 0,
    workspace_name: 1,
    workspace_description: 1,
    invitations: 2,
};

/**
 * First-run setup.
 *
 * Only ever seen once per installation, on a machine with no accounts. It is a
 * wizard rather than a single form because it asks for three unrelated things,
 * but it posts exactly once — there is no half-initialized state to recover
 * from if someone closes the tab midway, and no server round trip between
 * steps.
 */
export default function Setup({
    passwordRules,
    maxInvitations,
    defaultChannels,
}: Props) {
    const [step, setStep] = useState(0);

    const { data, setData, post, processing, errors } = useForm<SetupForm>({
        admin_name: '',
        admin_email: '',
        password: '',
        password_confirmation: '',
        workspace_name: '',
        workspace_description: '',
        invitations: [''],
    });

    // Invitation errors arrive keyed by index (`invitations.0`), so they cannot
    // be read straight off `errors` by field name.
    const invitationErrors = useMemo(
        () =>
            Object.entries(errors)
                .filter(([key]) => key.startsWith('invitations'))
                .map(([, message]) => message)
                .filter((message): message is string => Boolean(message)),
        [errors],
    );

    const canContinue = () => {
        if (step === 0) {
            return (
                data.admin_name.trim() !== '' &&
                data.admin_email.trim() !== '' &&
                data.password !== '' &&
                data.password === data.password_confirmation
            );
        }

        if (step === 1) {
            return data.workspace_name.trim().length >= 2;
        }

        return true;
    };

    const submit = () => {
        post(store().url, {
            onError: (submitted) => {
                const earliest = Object.keys(submitted)
                    .map((field) => FIELD_STEPS[field.split('.')[0]] ?? 2)
                    .sort((a, b) => a - b)[0];

                if (earliest !== undefined) {
                    setStep(earliest);
                }
            },
        });
    };

    const setInvitation = (index: number, value: string) => {
        setData(
            'invitations',
            data.invitations.map((email, position) =>
                position === index ? value : email,
            ),
        );
    };

    const addInvitation = () => {
        setData('invitations', [...data.invitations, '']);
    };

    const removeInvitation = (index: number) => {
        const remaining = data.invitations.filter(
            (_, position) => position !== index,
        );

        setData('invitations', remaining.length > 0 ? remaining : ['']);
    };

    return (
        <>
            <Head title="Set up Snack" />

            <div className="flex min-h-dvh flex-col items-center justify-center bg-background px-6 py-12">
                <SnackLogo className="mb-8" showTagline />

                <div className="w-full max-w-lg">
                    <ol className="mb-8 flex items-center gap-2">
                        {STEPS.map((entry, index) => (
                            <li
                                key={entry.title}
                                className="flex flex-1 items-center gap-2"
                            >
                                <span
                                    className={cn(
                                        'flex size-7 shrink-0 items-center justify-center rounded-full border text-xs font-semibold transition-colors',
                                        index < step &&
                                            'border-brand bg-brand text-white',
                                        index === step &&
                                            'border-brand text-brand',
                                        index > step &&
                                            'border-border text-muted-foreground',
                                    )}
                                >
                                    {index < step ? (
                                        <Check
                                            aria-hidden="true"
                                            className="size-3.5"
                                        />
                                    ) : (
                                        index + 1
                                    )}
                                </span>

                                <span
                                    className={cn(
                                        'hidden text-sm font-medium sm:block',
                                        index === step
                                            ? 'text-foreground'
                                            : 'text-muted-foreground',
                                    )}
                                >
                                    {entry.title}
                                </span>

                                {index < STEPS.length - 1 && (
                                    <span
                                        aria-hidden="true"
                                        className="h-px flex-1 bg-border"
                                    />
                                )}
                            </li>
                        ))}
                    </ol>

                    <div className="rounded-2xl border border-border bg-card p-6 shadow-sm">
                        <header className="mb-6">
                            <h1 className="text-xl font-bold tracking-tight">
                                {STEPS[step].title}
                            </h1>
                            <p className="mt-1 text-sm text-muted-foreground">
                                {STEPS[step].blurb}
                            </p>
                        </header>

                        {step === 0 && (
                            <div className="space-y-5">
                                <div className="space-y-2">
                                    <Label htmlFor="admin_name">
                                        Your name
                                    </Label>
                                    <Input
                                        id="admin_name"
                                        name="admin_name"
                                        autoFocus
                                        autoComplete="name"
                                        placeholder="Ada Lovelace"
                                        value={data.admin_name}
                                        onChange={(event) =>
                                            setData(
                                                'admin_name',
                                                event.target.value,
                                            )
                                        }
                                    />
                                    <InputError message={errors.admin_name} />
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="admin_email">
                                        Email address
                                    </Label>
                                    <Input
                                        id="admin_email"
                                        name="admin_email"
                                        type="email"
                                        autoComplete="email"
                                        placeholder="you@company.com"
                                        value={data.admin_email}
                                        onChange={(event) =>
                                            setData(
                                                'admin_email',
                                                event.target.value,
                                            )
                                        }
                                    />
                                    <InputError message={errors.admin_email} />
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="password">Password</Label>
                                    <PasswordInput
                                        id="password"
                                        name="password"
                                        autoComplete="new-password"
                                        passwordrules={passwordRules}
                                        value={data.password}
                                        onChange={(event) =>
                                            setData(
                                                'password',
                                                event.target.value,
                                            )
                                        }
                                    />
                                    <InputError message={errors.password} />
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="password_confirmation">
                                        Confirm password
                                    </Label>
                                    <PasswordInput
                                        id="password_confirmation"
                                        name="password_confirmation"
                                        autoComplete="new-password"
                                        passwordrules={passwordRules}
                                        value={data.password_confirmation}
                                        onChange={(event) =>
                                            setData(
                                                'password_confirmation',
                                                event.target.value,
                                            )
                                        }
                                    />
                                    <InputError
                                        message={errors.password_confirmation}
                                    />
                                </div>
                            </div>
                        )}

                        {step === 1 && (
                            <div className="space-y-5">
                                <div className="space-y-2">
                                    <Label htmlFor="workspace_name">
                                        Workspace name
                                    </Label>
                                    <Input
                                        id="workspace_name"
                                        name="workspace_name"
                                        autoFocus
                                        autoComplete="organization"
                                        placeholder="e.g. Snack HQ"
                                        value={data.workspace_name}
                                        onChange={(event) =>
                                            setData(
                                                'workspace_name',
                                                event.target.value,
                                            )
                                        }
                                    />
                                    <InputError
                                        message={errors.workspace_name}
                                    />
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="workspace_description">
                                        Description{' '}
                                        <span className="font-normal text-muted-foreground">
                                            (optional)
                                        </span>
                                    </Label>
                                    <Textarea
                                        id="workspace_description"
                                        name="workspace_description"
                                        rows={3}
                                        placeholder="What does this team work on?"
                                        value={data.workspace_description}
                                        onChange={(event) =>
                                            setData(
                                                'workspace_description',
                                                event.target.value,
                                            )
                                        }
                                    />
                                    <InputError
                                        message={errors.workspace_description}
                                    />
                                </div>

                                {defaultChannels.length > 0 && (
                                    <div className="rounded-xl bg-muted/50 p-4">
                                        <p className="text-sm font-medium">
                                            Starting channels
                                        </p>
                                        <p className="mt-1 text-sm text-muted-foreground">
                                            These are created for you. Add or
                                            archive them whenever you like.
                                        </p>
                                        <ul className="mt-3 flex flex-wrap gap-2">
                                            {defaultChannels.map((channel) => (
                                                <li
                                                    key={channel}
                                                    className="inline-flex items-center gap-1 rounded-md border border-border bg-background px-2 py-1 text-sm"
                                                >
                                                    <Hash
                                                        aria-hidden="true"
                                                        className="size-3.5 text-muted-foreground"
                                                    />
                                                    {channel}
                                                </li>
                                            ))}
                                        </ul>
                                    </div>
                                )}
                            </div>
                        )}

                        {step === 2 && (
                            <div className="space-y-5">
                                <div className="space-y-3">
                                    {data.invitations.map((email, index) => (
                                        <div
                                            // Keyed by position deliberately:
                                            // the rows hold no identity of
                                            // their own, and an email is not
                                            // unique until it has been typed.
                                            key={index}
                                            className="flex items-center gap-2"
                                        >
                                            <div className="relative flex-1">
                                                <Mail
                                                    aria-hidden="true"
                                                    className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                                                />
                                                <Input
                                                    type="email"
                                                    className="pl-9"
                                                    autoFocus={index === 0}
                                                    aria-label={`Invitation email ${index + 1}`}
                                                    placeholder="teammate@company.com"
                                                    value={email}
                                                    onChange={(event) =>
                                                        setInvitation(
                                                            index,
                                                            event.target.value,
                                                        )
                                                    }
                                                />
                                            </div>

                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                aria-label={`Remove invitation ${index + 1}`}
                                                disabled={
                                                    data.invitations.length ===
                                                        1 && email === ''
                                                }
                                                onClick={() =>
                                                    removeInvitation(index)
                                                }
                                            >
                                                <Trash2 aria-hidden="true" />
                                            </Button>
                                        </div>
                                    ))}
                                </div>

                                {invitationErrors.map((message) => (
                                    <InputError
                                        key={message}
                                        message={message}
                                    />
                                ))}

                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    disabled={
                                        data.invitations.length >=
                                        maxInvitations
                                    }
                                    onClick={addInvitation}
                                >
                                    <Plus aria-hidden="true" />
                                    Add another
                                </Button>

                                <p className="text-sm text-muted-foreground">
                                    Everyone invited joins as a member and lands
                                    in your starting channels. You can invite
                                    more people at any time — this step is
                                    optional.
                                </p>
                            </div>
                        )}
                    </div>

                    <div className="mt-6 flex items-center justify-between gap-3">
                        <Button
                            type="button"
                            variant="ghost"
                            disabled={step === 0 || processing}
                            onClick={() => setStep((current) => current - 1)}
                        >
                            <ArrowLeft aria-hidden="true" />
                            Back
                        </Button>

                        {step < STEPS.length - 1 ? (
                            <Button
                                type="button"
                                disabled={!canContinue()}
                                onClick={() =>
                                    setStep((current) => current + 1)
                                }
                            >
                                Continue
                                <ArrowRight aria-hidden="true" />
                            </Button>
                        ) : (
                            <Button
                                type="button"
                                disabled={processing}
                                onClick={submit}
                            >
                                {processing ? (
                                    <Spinner />
                                ) : (
                                    <Sparkles aria-hidden="true" />
                                )}
                                Create workspace
                            </Button>
                        )}
                    </div>
                </div>
            </div>
        </>
    );
}
