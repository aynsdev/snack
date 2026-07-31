import { Form, Head, router, usePage } from '@inertiajs/react';
import { Link } from '@inertiajs/react';
import { Trash2, Upload } from 'lucide-react';
import { useRef, useState } from 'react';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import DeleteUser from '@/components/delete-user';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { UserAvatar } from '@/components/snack/user-avatar';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/profile';
import {
    destroy as avatarDestroy,
    store as avatarStore,
} from '@/routes/profile/avatar';
import { send } from '@/routes/verification';
import type { Auth, SnackUser } from '@/types';

type PageProps = {
    auth: Auth & { profile: SnackUser | null };
};

export default function Profile({
    mustVerifyEmail,
    status,
}: {
    mustVerifyEmail: boolean;
    status?: string;
}) {
    const { auth } = usePage<PageProps>().props;

    return (
        <>
            <Head title="Profile settings" />

            <h1 className="sr-only">Profile settings</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Profile picture"
                    description="Shown next to everything you post"
                />

                <AvatarField profile={auth.profile} />

                <Heading
                    variant="small"
                    title="Profile"
                    description="Update your name and email address"
                />

                <Form
                    {...ProfileController.update.form()}
                    options={{
                        preserveScroll: true,
                    }}
                    className="space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="name">Name</Label>

                                <Input
                                    id="name"
                                    className="mt-1 block w-full"
                                    defaultValue={auth.user.name}
                                    name="name"
                                    required
                                    autoComplete="name"
                                    placeholder="Full name"
                                />

                                <InputError
                                    className="mt-2"
                                    message={errors.name}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="email">Email address</Label>

                                <Input
                                    id="email"
                                    type="email"
                                    className="mt-1 block w-full"
                                    defaultValue={auth.user.email}
                                    name="email"
                                    required
                                    autoComplete="username"
                                    placeholder="Email address"
                                />

                                <InputError
                                    className="mt-2"
                                    message={errors.email}
                                />
                            </div>

                            {mustVerifyEmail &&
                                auth.user.email_verified_at === null && (
                                    <div>
                                        <p className="-mt-4 text-sm text-muted-foreground">
                                            Your email address is unverified.{' '}
                                            <Link
                                                href={send()}
                                                as="button"
                                                className="text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current! dark:decoration-neutral-500"
                                            >
                                                Click here to re-send the
                                                verification email.
                                            </Link>
                                        </p>

                                        {status ===
                                            'verification-link-sent' && (
                                            <div className="mt-2 text-sm font-medium text-green-600">
                                                A new verification link has been
                                                sent to your email address.
                                            </div>
                                        )}
                                    </div>
                                )}

                            <div className="flex items-center gap-4">
                                <Button
                                    disabled={processing}
                                    data-test="update-profile-button"
                                >
                                    Save
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>

            <DeleteUser />
        </>
    );
}

/**
 * The profile picture.
 *
 * Submits on choosing a file rather than behind a second "Save" — picking a
 * picture *is* the decision, and a two-step flow here only creates a state where
 * the preview and the stored avatar disagree.
 *
 * The upload is a plain Inertia visit, so the response refreshes the shared
 * `auth` props and every avatar on the page — the sidebar, the header, and each
 * message this person has written — changes at once. Doing it as a background
 * request would leave all of those showing the old picture until the next
 * navigation.
 */
function AvatarField({ profile }: { profile: SnackUser | null }) {
    const picker = useRef<HTMLInputElement>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const upload = (file: File | undefined) => {
        if (file === undefined) {
            return;
        }

        setBusy(true);
        setError(null);

        router.post(
            avatarStore.url(),
            { avatar: file },
            {
                // The server re-encodes the image, so a slow decode of a large
                // photo is normal rather than a hang.
                forceFormData: true,
                preserveScroll: true,
                onError: (errors) =>
                    setError(errors.avatar ?? 'That image could not be saved.'),
                onFinish: () => setBusy(false),
            },
        );
    };

    return (
        <div className="flex flex-wrap items-center gap-5">
            {profile !== null && <UserAvatar user={profile} size="xl" />}

            <div className="space-y-2">
                <input
                    ref={picker}
                    type="file"
                    accept="image/png,image/jpeg,image/webp,image/gif"
                    className="sr-only"
                    aria-label="Choose a profile picture"
                    onChange={(event) => {
                        upload(event.target.files?.[0]);

                        // Cleared so re-picking the same file still fires.
                        event.target.value = '';
                    }}
                />

                <div className="flex flex-wrap items-center gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        disabled={busy}
                        onClick={() => picker.current?.click()}
                        className="gap-1.5"
                        data-test="upload-avatar-button"
                    >
                        <Upload aria-hidden="true" className="size-4" />
                        {busy
                            ? 'Uploading…'
                            : profile?.avatar_url === null
                              ? 'Upload a picture'
                              : 'Change picture'}
                    </Button>

                    {profile?.avatar_url !== null && (
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            disabled={busy}
                            onClick={() =>
                                router.delete(avatarDestroy.url(), {
                                    preserveScroll: true,
                                })
                            }
                            className="gap-1.5 text-muted-foreground"
                            data-test="remove-avatar-button"
                        >
                            <Trash2 aria-hidden="true" className="size-4" />
                            Remove
                        </Button>
                    )}
                </div>

                <p className="text-xs text-muted-foreground">
                    JPEG, PNG, GIF, or WebP, up to 4 MB. It will be cropped to a
                    square.
                </p>

                <InputError message={error ?? undefined} />
            </div>
        </div>
    );
}

Profile.layout = {
    breadcrumbs: [
        {
            title: 'Profile settings',
            href: edit(),
        },
    ],
};
