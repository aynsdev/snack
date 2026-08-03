import { Form, router } from '@inertiajs/react';
import { Check, Copy, Link2, Link2Off, RefreshCw } from 'lucide-react';
import { useRef, useState } from 'react';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useClipboard } from '@/hooks/use-clipboard';
import { destroy, store } from '@/routes/workspace-invite-link';
import type { WorkspaceInvitation } from '@/types';

/**
 * The workspace's one shareable invite link.
 *
 * Sits above the emailed-invitation list because it answers a different
 * question: that list is "who have I written to", this is "what do I paste into
 * the onboarding doc". They share a table but never a row — see
 * {@see App\Http\Controllers\Workspaces\InvitationController::index()}.
 *
 * # Why the URL is a readonly input rather than just text
 *
 * `navigator.clipboard` is undefined on insecure origins, so the copy button
 * cannot be the only way to get the link out — on an internal deployment served
 * over plain HTTP it would fail silently and leave the admin with no way to
 * share anything. A real input can be focused, selected, and copied by hand, so
 * the button becomes the fast path rather than the only path.
 */
export function InviteLinkCard({
    link,
    workspaceSlug,
}: {
    link: WorkspaceInvitation | null;
    workspaceSlug: string;
}) {
    const isLive = link !== null && link.status === 'pending';

    return (
        <section className="mb-8 rounded-xl border border-border p-4">
            <header className="flex flex-wrap items-center gap-2">
                <Link2 aria-hidden="true" className="size-4 text-brand" />
                <h2 className="font-semibold">Invite link</h2>

                {link !== null && !isLive && (
                    <Badge variant="outline">{statusLabel(link)}</Badge>
                )}

                <p className="w-full text-sm text-pretty text-muted-foreground">
                    Anyone with this link can join. Turn it off or regenerate it
                    at any time — regenerating immediately stops the old link
                    working.
                </p>
            </header>

            {isLive ? (
                <LiveLink link={link} workspaceSlug={workspaceSlug} />
            ) : (
                <CreateLinkForm
                    workspaceSlug={workspaceSlug}
                    hadPreviousLink={link !== null}
                />
            )}
        </section>
    );
}

function LiveLink({
    link,
    workspaceSlug,
}: {
    link: WorkspaceInvitation;
    workspaceSlug: string;
}) {
    const [, copy] = useClipboard();
    const [copied, setCopied] = useState(false);
    const field = useRef<HTMLInputElement>(null);
    const url = link.url ?? '';

    const handleCopy = async () => {
        // Select regardless of whether the clipboard API is available: on an
        // insecure origin the copy fails, and a selected field is the thing
        // that lets the admin finish the job with the keyboard.
        field.current?.select();

        if (await copy(url)) {
            setCopied(true);
            window.setTimeout(() => setCopied(false), 2000);
        }
    };

    return (
        <div className="mt-4 space-y-3">
            <div className="flex flex-wrap items-center gap-2">
                <Input
                    ref={field}
                    readOnly
                    value={url}
                    aria-label="Invite link"
                    onFocus={(event) => event.currentTarget.select()}
                    className="min-w-56 flex-1 font-mono text-xs"
                />

                <Button type="button" onClick={handleCopy}>
                    {copied ? (
                        <Check aria-hidden="true" />
                    ) : (
                        <Copy aria-hidden="true" />
                    )}
                    {copied ? 'Copied' : 'Copy'}
                </Button>
            </div>

            <p className="text-sm text-muted-foreground">
                Joins as {link.role_label.toLowerCase()} ·{' '}
                {link.max_uses === null
                    ? `${link.use_count} ${link.use_count === 1 ? 'person has' : 'people have'} joined`
                    : `${link.use_count} of ${link.max_uses} uses`}{' '}
                · expires {new Date(link.expires_at).toLocaleDateString()}
            </p>

            <div className="flex flex-wrap gap-2">
                <Form
                    action={store(workspaceSlug)}
                    options={{ preserveScroll: true }}
                    disableWhileProcessing
                >
                    {({ processing }) => (
                        <>
                            {/* Regenerating keeps the settings the link already
                                has; changing them means turning it off and
                                making a new one, which is the same number of
                                clicks and never leaves an admin unsure which
                                role the live link grants. */}
                            <input
                                type="hidden"
                                name="role"
                                value={link.role}
                            />
                            {link.max_uses !== null && (
                                <input
                                    type="hidden"
                                    name="max_uses"
                                    value={link.max_uses}
                                />
                            )}

                            <Button
                                type="submit"
                                variant="outline"
                                size="sm"
                                disabled={processing}
                            >
                                <RefreshCw aria-hidden="true" />
                                Regenerate
                            </Button>
                        </>
                    )}
                </Form>

                <Button
                    variant="ghost"
                    size="sm"
                    onClick={() =>
                        router.delete(destroy(workspaceSlug), {
                            preserveScroll: true,
                        })
                    }
                >
                    <Link2Off aria-hidden="true" />
                    Turn off
                </Button>
            </div>
        </div>
    );
}

function CreateLinkForm({
    workspaceSlug,
    hadPreviousLink,
}: {
    workspaceSlug: string;
    hadPreviousLink: boolean;
}) {
    return (
        <Form
            action={store(workspaceSlug)}
            options={{ preserveScroll: true }}
            disableWhileProcessing
            className="mt-4"
        >
            {({ errors, processing }) => (
                <div className="flex flex-wrap items-end gap-3">
                    <div className="w-40 space-y-2">
                        <Label htmlFor="link-role">Joins as</Label>
                        {/*
                         * Guest is absent on purpose: a guest is auto-joined to
                         * no channels, so one arriving through a shared URL
                         * would land in an empty workspace with nobody
                         * expecting them. The server rejects it too.
                         */}
                        <Select name="role" defaultValue="member">
                            <SelectTrigger id="link-role">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="member">Member</SelectItem>
                                <SelectItem value="admin">Admin</SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError message={errors.role} />
                    </div>

                    <div className="w-40 space-y-2">
                        <Label htmlFor="link-max-uses">Max uses</Label>
                        <Input
                            id="link-max-uses"
                            name="max_uses"
                            type="number"
                            min={1}
                            placeholder="Unlimited"
                        />
                        <InputError message={errors.max_uses} />
                    </div>

                    <Button type="submit" disabled={processing}>
                        <Link2 aria-hidden="true" />
                        {hadPreviousLink ? 'Create new link' : 'Create link'}
                    </Button>
                </div>
            )}
        </Form>
    );
}

/** Why a link that exists is not usable. */
function statusLabel(link: WorkspaceInvitation): string {
    switch (link.status) {
        case 'expired':
            return 'expired';
        case 'used_up':
            return 'all uses taken';
        default:
            return 'off';
    }
}
