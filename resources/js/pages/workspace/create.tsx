import { Form, Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import InputError from '@/components/input-error';
import { SnackLogo } from '@/components/snack/snack-logo';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { index as workspaceIndex, store } from '@/routes/workspaces';

/**
 * Creating a workspace.
 *
 * Deliberately two fields. The slug, default channels, owner membership, and
 * settings are all derived server-side by the CreateWorkspace action, so the
 * first thing a new team sees is a working #general rather than a setup wizard.
 */
export default function CreateWorkspace() {
    return (
        <>
            <Head title="Create a workspace" />

            <div className="flex min-h-dvh flex-col items-center justify-center px-6 py-12">
                <SnackLogo className="mb-10" />

                <div className="w-full max-w-md">
                    <h1 className="text-2xl font-bold tracking-tight text-balance">
                        Create a workspace
                    </h1>
                    <p className="mt-1.5 text-sm text-pretty text-muted-foreground">
                        A workspace is where your team talks. You can invite
                        people once it exists.
                    </p>

                    <Form
                        action={store()}
                        disableWhileProcessing
                        className="mt-8 space-y-5"
                    >
                        {({ errors, processing }) => (
                            <>
                                <div className="space-y-2">
                                    <Label htmlFor="name">Workspace name</Label>
                                    <Input
                                        id="name"
                                        name="name"
                                        required
                                        autoFocus
                                        autoComplete="organization"
                                        placeholder="e.g. Snack HQ"
                                    />
                                    <InputError message={errors.name} />
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="description">
                                        Description{' '}
                                        <span className="font-normal text-muted-foreground">
                                            (optional)
                                        </span>
                                    </Label>
                                    <Textarea
                                        id="description"
                                        name="description"
                                        rows={3}
                                        placeholder="What does this team work on?"
                                    />
                                    <InputError message={errors.description} />
                                </div>

                                <div className="flex items-center gap-3 pt-2">
                                    <Button type="submit" disabled={processing}>
                                        Create workspace
                                    </Button>

                                    <Button asChild variant="ghost">
                                        <Link href={workspaceIndex()}>
                                            <ArrowLeft aria-hidden="true" />
                                            Back
                                        </Link>
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                </div>
            </div>
        </>
    );
}
