import { Form, router } from '@inertiajs/react';
import { Download, Pencil, Trash2 } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { KIND_ICONS, kindLabel } from '@/components/snack/assets/asset-tile';
import { FolderSelect } from '@/components/snack/assets/folder-select';
import { UserAvatar } from '@/components/snack/user-avatar';
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
import { dayLabel } from '@/lib/message-grouping';
import { destroy, update } from '@/routes/assets';
import type { AssetFolder, LibraryAsset } from '@/types';

/**
 * One asset, opened.
 *
 * Answers the question people actually arrive with — "is this the right one?" —
 * before it offers the download: a large preview, the note whoever filed it
 * left, the real filename, and who put it there. See {@see AssetTile} for why
 * the tile does not simply download.
 *
 * Editing happens in place rather than in a second dialog. A rename, a
 * clarifying note, and a move to the right shelf are the three things somebody
 * does *while* looking at an asset and realising it is filed wrong, and sending
 * them somewhere else to do it is how a library stays untidy.
 */
export function AssetDetailDialog({
    workspaceSlug,
    asset,
    folders,
    open,
    onOpenChange,
}: {
    workspaceSlug: string;
    asset: LibraryAsset;
    folders: AssetFolder[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const [editing, setEditing] = useState(false);
    const [confirmingDelete, setConfirmingDelete] = useState(false);

    const kind = asset.file?.kind ?? 'file';
    const Icon = KIND_ICONS[kind];

    const close = (next: boolean) => {
        if (!next) {
            // Reopening a different asset must not land in whatever state the
            // last one was left in.
            setEditing(false);
            setConfirmingDelete(false);
        }

        onOpenChange(next);
    };

    return (
        <Dialog open={open} onOpenChange={close}>
            <DialogContent className="sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle className="truncate">{asset.name}</DialogTitle>

                    <DialogDescription>
                        {asset.file === null
                            ? 'The file behind this entry is missing.'
                            : `${kindLabel(kind)} · ${asset.file.human_size} · ${asset.file.name}`}
                    </DialogDescription>
                </DialogHeader>

                <div
                    className={
                        'flex max-h-64 items-center justify-center overflow-hidden rounded-xl border border-border' +
                        (kind === 'image' ? ' snack-checkerboard' : ' bg-muted')
                    }
                >
                    {asset.file?.preview_url != null ? (
                        <img
                            src={asset.file.preview_url}
                            alt={asset.name}
                            className="max-h-64 w-auto object-contain"
                        />
                    ) : asset.file?.thumbnail_url != null ? (
                        <img
                            src={asset.file.thumbnail_url}
                            alt={asset.name}
                            className="max-h-64 w-auto object-contain"
                        />
                    ) : (
                        <span className="flex h-32 items-center justify-center text-muted-foreground">
                            <Icon aria-hidden="true" className="size-10" />
                        </span>
                    )}
                </div>

                {editing ? (
                    <Form
                        action={update({
                            workspace: workspaceSlug,
                            asset: asset.id,
                        })}
                        options={{ preserveScroll: true }}
                        onSuccess={() => setEditing(false)}
                        className="space-y-4"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="space-y-2">
                                    <Label htmlFor="asset-name">Name</Label>

                                    <Input
                                        id="asset-name"
                                        name="name"
                                        autoFocus
                                        required
                                        maxLength={200}
                                        defaultValue={asset.name}
                                    />

                                    <InputError message={errors.name} />
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="asset-description">
                                        Notes{' '}
                                        <span className="font-normal text-muted-foreground">
                                            (optional)
                                        </span>
                                    </Label>

                                    <Textarea
                                        id="asset-description"
                                        name="description"
                                        rows={3}
                                        maxLength={2000}
                                        defaultValue={asset.description ?? ''}
                                        placeholder="When to use this, and what to use instead."
                                    />

                                    <InputError message={errors.description} />
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="asset-folder">Folder</Label>

                                    <FolderSelect
                                        id="asset-folder"
                                        name="folder_id"
                                        folders={folders}
                                        value={asset.folder_id}
                                    />

                                    <InputError message={errors.folder_id} />
                                </div>

                                <DialogFooter>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        onClick={() => setEditing(false)}
                                    >
                                        Cancel
                                    </Button>

                                    <Button type="submit" disabled={processing}>
                                        Save
                                    </Button>
                                </DialogFooter>
                            </>
                        )}
                    </Form>
                ) : (
                    <>
                        {asset.description !== null && (
                            <p className="text-sm text-pretty text-muted-foreground">
                                {asset.description}
                            </p>
                        )}

                        {asset.creator !== null && (
                            <p className="flex items-center gap-2 text-xs text-muted-foreground">
                                <UserAvatar user={asset.creator} size="sm" />
                                Added by {asset.creator.name}
                                {asset.created_at !== null && (
                                    <> · {dayLabel(asset.created_at)}</>
                                )}
                            </p>
                        )}

                        <DialogFooter className="sm:justify-between">
                            <div className="flex gap-2">
                                {asset.can_manage && (
                                    <>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onClick={() => setEditing(true)}
                                        >
                                            <Pencil
                                                aria-hidden="true"
                                                className="size-4"
                                            />
                                            Edit
                                        </Button>

                                        {confirmingDelete ? (
                                            <Button
                                                type="button"
                                                variant="destructive"
                                                size="sm"
                                                onClick={() =>
                                                    router.delete(
                                                        destroy({
                                                            workspace:
                                                                workspaceSlug,
                                                            asset: asset.id,
                                                        }).url,
                                                        {
                                                            preserveScroll: true,
                                                            onSuccess: () =>
                                                                close(false),
                                                        },
                                                    )
                                                }
                                            >
                                                <Trash2
                                                    aria-hidden="true"
                                                    className="size-4"
                                                />
                                                Delete for everyone
                                            </Button>
                                        ) : (
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                onClick={() =>
                                                    setConfirmingDelete(true)
                                                }
                                            >
                                                <Trash2
                                                    aria-hidden="true"
                                                    className="size-4"
                                                />
                                                Delete
                                            </Button>
                                        )}
                                    </>
                                )}
                            </div>

                            {asset.file !== null && (
                                <Button asChild size="sm">
                                    <a
                                        href={asset.file.download_url}
                                        download={asset.file.name}
                                    >
                                        <Download
                                            aria-hidden="true"
                                            className="size-4"
                                        />
                                        Download
                                    </a>
                                </Button>
                            )}
                        </DialogFooter>
                    </>
                )}
            </DialogContent>
        </Dialog>
    );
}
