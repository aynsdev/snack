import { Form, Head, Link, router } from '@inertiajs/react';
import { ScrollText, Trash2, Upload } from 'lucide-react';
import { useRef, useState } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useWorkspace } from '@/hooks/use-workspace';
import { index as auditIndex } from '@/routes/audit';
import {
    destroy as removeLogo,
    store as uploadLogo,
} from '@/routes/workspace-logo';
import { update as updateSettings } from '@/routes/workspace-settings';
import type { Workspace, WorkspaceSettings } from '@/types';

type Props = {
    workspace: Workspace;
    settings: WorkspaceSettings;
    /** The floor the member limit may be set to. */
    member_count: number;
    is_owner: boolean;
};

/**
 * What the workspace is called, how large it may grow, and who can get in.
 *
 * Admin-gated and shared: every control here changes the workspace for
 * everybody in it. Its near-namesake — the notification preferences screen — is
 * the exact opposite, and private to the person looking at it.
 */
export default function WorkspaceSettingsPage({
    workspace,
    settings,
    member_count,
    is_owner,
}: Props) {
    const current = useWorkspace();

    return (
        <>
            <Head title={`Settings · ${workspace.name}`} />

            <div className="snack-scroll min-w-0 flex-1 overflow-y-auto">
                <div className="mx-auto max-w-2xl space-y-8 px-6 py-8">
                    <header>
                        <h1 className="text-2xl font-bold tracking-tight">
                            Workspace settings
                        </h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            These apply to everyone in {workspace.name}.
                        </p>
                    </header>

                    <LogoField workspace={workspace} />

                    <Form
                        {...updateSettings.form(current.slug)}
                        options={{ preserveScroll: true }}
                        className="space-y-6"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="name">Name</Label>
                                    <Input
                                        id="name"
                                        name="name"
                                        defaultValue={workspace.name}
                                        required
                                        maxLength={80}
                                    />
                                    <InputError message={errors.name} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="description">
                                        Description
                                    </Label>
                                    <Textarea
                                        id="description"
                                        name="description"
                                        defaultValue={
                                            workspace.description ?? ''
                                        }
                                        rows={3}
                                        maxLength={500}
                                    />
                                    <InputError message={errors.description} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="member_limit">
                                        Member limit
                                    </Label>
                                    <Input
                                        id="member_limit"
                                        name="member_limit"
                                        type="number"
                                        className="w-40"
                                        defaultValue={workspace.member_limit}
                                        min={Math.max(1, member_count)}
                                        required
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        {member_count} of{' '}
                                        {workspace.member_limit} seats used. The
                                        limit cannot go below the people already
                                        here.
                                    </p>
                                    <InputError message={errors.member_limit} />
                                </div>

                                <fieldset className="space-y-3">
                                    <legend className="text-sm font-medium">
                                        Access
                                    </legend>

                                    <CheckboxField
                                        name="allow_invitations"
                                        label="Allow invitations"
                                        description="Turning this off stops admins inviting anyone new, without touching the people already here."
                                        defaultChecked={
                                            settings.allow_invitations
                                        }
                                    />

                                    <CheckboxField
                                        name="allow_self_serve_joins"
                                        label="Let members join public channels themselves"
                                        description="Turning this off makes every channel invite-only — an admin has to add people."
                                        defaultChecked={
                                            settings.allow_self_serve_joins
                                        }
                                    />
                                </fieldset>

                                <Button type="submit" disabled={processing}>
                                    Save settings
                                </Button>
                            </>
                        )}
                    </Form>

                    <section className="space-y-3 border-t border-border pt-6">
                        <Heading
                            variant="small"
                            title="Audit log"
                            description="Every administrative change made in this workspace, and who made it."
                        />

                        <Button asChild variant="outline">
                            <Link href={auditIndex(current.slug)}>
                                <ScrollText aria-hidden="true" />
                                Open the audit log
                            </Link>
                        </Button>
                    </section>

                    {is_owner && (
                        <section className="space-y-2 border-t border-border pt-6">
                            <Heading
                                variant="small"
                                title="Ownership"
                                description="You own this workspace. Transferring it or deleting it are separate, deliberately narrower operations — neither is available here yet."
                            />
                        </section>
                    )}
                </div>
            </div>
        </>
    );
}

function LogoField({ workspace }: { workspace: Workspace }) {
    const input = useRef<HTMLInputElement>(null);
    const current = useWorkspace();

    /*
     * Submitted the moment a file is chosen rather than waiting for the form
     * below: the upload is its own endpoint — a picture change should not
     * re-validate the member limit — and a second "Save" for a control that
     * already looks finished is a step people skip.
     */
    const upload = (file: File | undefined) => {
        if (file === undefined) {
            return;
        }

        router.post(
            uploadLogo.url(current.slug),
            { logo: file },
            { preserveScroll: true, forceFormData: true },
        );
    };

    return (
        <section className="space-y-3">
            <Heading
                variant="small"
                title="Logo"
                description="Shown in the workspace rail. Square images work best."
            />

            <div className="flex items-center gap-4">
                <span className="flex size-16 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-muted text-lg font-bold">
                    {workspace.logo_url === null ? (
                        workspace.initials
                    ) : (
                        <img
                            src={workspace.logo_url}
                            alt=""
                            className="size-full object-cover"
                        />
                    )}
                </span>

                <div className="flex flex-wrap gap-2">
                    <input
                        ref={input}
                        type="file"
                        accept="image/*"
                        className="sr-only"
                        onChange={(event) => upload(event.target.files?.[0])}
                    />

                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => input.current?.click()}
                    >
                        <Upload aria-hidden="true" />
                        Upload
                    </Button>

                    {workspace.logo_url !== null && (
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() =>
                                router.delete(removeLogo.url(current.slug), {
                                    preserveScroll: true,
                                })
                            }
                        >
                            <Trash2 aria-hidden="true" />
                            Remove
                        </Button>
                    )}
                </div>
            </div>
        </section>
    );
}

function CheckboxField({
    name,
    label,
    description,
    defaultChecked,
}: {
    name: string;
    label: string;
    description: string;
    defaultChecked: boolean;
}) {
    const [checked, setChecked] = useState(defaultChecked);

    return (
        <div className="flex items-start gap-3">
            {/*
             * An unchecked box submits nothing, and the request validates these
             * as required booleans — so a hidden "0" rides along and the checked
             * input overwrites it. Without this, turning a policy off would be a
             * validation error rather than a save.
             */}
            <input type="hidden" name={name} value={checked ? '1' : '0'} />

            <Checkbox
                id={name}
                checked={checked}
                onCheckedChange={(next) => setChecked(next === true)}
            />

            <div className="grid gap-1">
                <Label htmlFor={name} className="font-normal">
                    {label}
                </Label>
                <p className="text-xs text-muted-foreground">{description}</p>
            </div>
        </div>
    );
}
