import { Form } from '@inertiajs/react';
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
import { Textarea } from '@/components/ui/textarea';
import { store, update } from '@/routes/assets/folders';
import type { AssetFolder } from '@/types';

/**
 * Naming a shelf, or renaming one.
 *
 * One dialog for both because they are one form with the same two fields: a null
 * `folder` is the create case. Splitting them would be two components differing
 * in a verb — the same call {@see ChannelSectionDialog} makes.
 *
 * `parentId` only applies to the create case: it is where the new shelf goes.
 * A rename deliberately sends no `parent_id` at all, because an absent field and
 * a null one mean different things to the server — see UpdateAssetFolderRequest,
 * where a missing key means "leave it where it is" and null means "move it to
 * the top of the library".
 */
export function FolderDialog({
    workspaceSlug,
    folder = null,
    parentId = null,
    open,
    onOpenChange,
}: {
    workspaceSlug: string;
    folder?: AssetFolder | null;
    /** The shelf the new folder goes on. Ignored when renaming. */
    parentId?: number | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const isRename = folder !== null;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>
                        {isRename ? 'Rename folder' : 'New folder'}
                    </DialogTitle>

                    <DialogDescription>
                        {isRename
                            ? 'Everything inside stays where it is.'
                            : parentId === null
                              ? 'A shelf at the top of the library. Everyone in the workspace can see it.'
                              : 'A shelf inside the folder you have open.'}
                    </DialogDescription>
                </DialogHeader>

                <Form
                    action={
                        isRename
                            ? update({
                                  workspace: workspaceSlug,
                                  assetFolder: folder.id,
                              })
                            : store(workspaceSlug)
                    }
                    options={{ preserveScroll: true }}
                    onSuccess={() => onOpenChange(false)}
                    className="space-y-4"
                    resetOnSuccess
                >
                    {({ processing, errors }) => (
                        <>
                            {!isRename && parentId !== null && (
                                <input
                                    type="hidden"
                                    name="parent_id"
                                    value={parentId}
                                />
                            )}

                            <div className="space-y-2">
                                <Label htmlFor="asset-folder-name">Name</Label>

                                <Input
                                    id="asset-folder-name"
                                    name="name"
                                    autoFocus
                                    required
                                    maxLength={120}
                                    defaultValue={folder?.name ?? ''}
                                    placeholder="Brand"
                                />

                                <InputError message={errors.name} />
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="asset-folder-description">
                                    Description{' '}
                                    <span className="font-normal text-muted-foreground">
                                        (optional)
                                    </span>
                                </Label>

                                <Textarea
                                    id="asset-folder-description"
                                    name="description"
                                    rows={2}
                                    maxLength={500}
                                    defaultValue={folder?.description ?? ''}
                                    placeholder="Logos, colours, and typography. Ask #brand before changing anything here."
                                />

                                <InputError message={errors.description} />
                            </div>

                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={() => onOpenChange(false)}
                                >
                                    Cancel
                                </Button>

                                <Button type="submit" disabled={processing}>
                                    {isRename ? 'Save' : 'Create folder'}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
