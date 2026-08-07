import { useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
    store as storeRelease,
    update as updateRelease,
} from '@/routes/projects/releases';
import type { Release } from '@/types';

/**
 * Planning a version, and editing one.
 *
 * The same dialog both ways — a version's name, the window it covers, and the
 * description — because they are the same four fields and a second form would
 * be a second place for the date rules to drift.
 *
 * Nothing here saves on change, unlike the controls on a card. A description is
 * written rather than picked, and a field that saved as you typed would put
 * half-finished sentences in front of everybody reading the table.
 */
export function ReleaseDialog({
    release,
    workspaceSlug,
    projectSlug,
    open,
    onOpenChange,
}: {
    /** The release being edited, or undefined when planning a new one. */
    release?: Release;
    workspaceSlug: string;
    projectSlug: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const form = useForm({
        name: release?.name ?? '',
        notes: release?.notes ?? '',
        starts_on: release?.starts_on ?? '',
        ends_on: release?.ends_on ?? '',
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        // An empty date is "not decided", which is a null — not the empty
        // string a date input hands over.
        form.transform((data) => ({
            ...data,
            starts_on: data.starts_on === '' ? null : data.starts_on,
            ends_on: data.ends_on === '' ? null : data.ends_on,
        }));

        const options = {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onOpenChange(false);
            },
        };

        if (release === undefined) {
            form.post(
                storeRelease.url({
                    workspace: workspaceSlug,
                    project: projectSlug,
                }),
                options,
            );

            return;
        }

        form.patch(
            updateRelease.url({
                workspace: workspaceSlug,
                project: projectSlug,
                release: release.id,
            }),
            options,
        );
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogTitle>
                    {release === undefined
                        ? 'Create release'
                        : `Edit ${release.name}`}
                </DialogTitle>

                <DialogDescription>
                    A version and the window it covers. What goes into it is set
                    on the cards themselves.
                </DialogDescription>

                <form onSubmit={submit} className="space-y-4">
                    <div className="space-y-1.5">
                        <Label htmlFor="release-name">Name</Label>
                        <Input
                            id="release-name"
                            autoFocus
                            value={form.data.name}
                            maxLength={40}
                            placeholder="1.4.0"
                            onChange={(event) =>
                                form.setData('name', event.target.value)
                            }
                        />
                        {form.errors.name !== undefined && (
                            <p className="text-xs text-destructive">
                                {form.errors.name}
                            </p>
                        )}
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-1.5">
                            <Label htmlFor="release-start">Start date</Label>
                            <Input
                                id="release-start"
                                type="date"
                                value={form.data.starts_on}
                                onChange={(event) =>
                                    form.setData(
                                        'starts_on',
                                        event.target.value,
                                    )
                                }
                            />
                        </div>

                        <div className="space-y-1.5">
                            <Label htmlFor="release-end">Release date</Label>
                            <Input
                                id="release-end"
                                type="date"
                                value={form.data.ends_on}
                                onChange={(event) =>
                                    form.setData('ends_on', event.target.value)
                                }
                            />
                            {form.errors.ends_on !== undefined && (
                                <p className="text-xs text-destructive">
                                    {form.errors.ends_on}
                                </p>
                            )}
                        </div>
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor="release-notes">Description</Label>
                        <Textarea
                            id="release-notes"
                            rows={4}
                            value={form.data.notes}
                            placeholder="What is going out, and anything the people installing it need to know."
                            onChange={(event) =>
                                form.setData('notes', event.target.value)
                            }
                        />
                        {form.errors.notes !== undefined && (
                            <p className="text-xs text-destructive">
                                {form.errors.notes}
                            </p>
                        )}
                    </div>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => onOpenChange(false)}
                            className="text-muted-foreground"
                        >
                            Cancel
                        </Button>

                        <Button type="submit" disabled={form.processing}>
                            {release === undefined ? 'Create' : 'Save'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
