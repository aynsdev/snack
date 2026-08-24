import { Download } from 'lucide-react';
import {
    KIND_ICONS,
    kindLabel,
} from '@/components/snack/assets/asset-tile';
import {
    ContainerLabel,
    useContainerHref,
} from '@/components/snack/container-label';
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
import { dayLabel } from '@/lib/message-grouping';
import { cn } from '@/lib/utils';
import type { FileRow } from '@/types';

/**
 * One file from the browser, opened.
 *
 * A row in the Files view is something you are looking for, not something you
 * were handed — the question is usually "is this the right one?" — so clicking
 * opens a preview and the download is one deliberate step further in.
 */
export function FilePreviewDialog({
    file,
    open,
    onOpenChange,
}: {
    file: FileRow | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const containerHref = useContainerHref(file?.container ?? null);

    if (file === null) {
        return null;
    }

    const Icon = KIND_ICONS[file.kind];
    const previewSource = filePreviewSource(file);
    const hasLargePreview = previewSource !== null;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                className={cn(
                    hasLargePreview
                        ? 'flex w-full max-w-[min(calc(100vw-4rem),72rem)] max-h-[calc(100dvh-4rem)] flex-col gap-4 sm:max-w-[min(calc(100vw-4rem),72rem)]'
                        : 'sm:max-w-2xl',
                )}
            >
                <DialogHeader className="shrink-0">
                    <DialogTitle className="truncate">{file.name}</DialogTitle>

                    <DialogDescription>
                        {kindLabel(file.kind)} · {file.human_size}
                    </DialogDescription>
                </DialogHeader>

                <div
                    className={cn(
                        'overflow-hidden rounded-xl border border-border',
                        hasLargePreview
                            ? 'flex h-[min(calc(100dvh-16rem),72vh)] min-h-80 items-center justify-center'
                            : 'flex max-h-64 items-center justify-center bg-muted',
                        file.kind === 'image' && 'snack-checkerboard',
                        file.kind === 'pdf' && hasLargePreview && 'bg-muted',
                    )}
                >
                    {file.kind === 'pdf' && previewSource !== null ? (
                        <iframe
                            src={previewSource}
                            title={file.name}
                            className="h-full w-full"
                        />
                    ) : previewSource !== null ? (
                        <img
                            src={previewSource}
                            alt={file.name}
                            className={cn(
                                hasLargePreview
                                    ? 'max-h-full max-w-full object-contain'
                                    : 'max-h-64 w-auto object-contain',
                            )}
                        />
                    ) : (
                        <span className="flex h-32 w-full items-center justify-center text-muted-foreground">
                            <Icon aria-hidden="true" className="size-10" />
                        </span>
                    )}
                </div>

                {file.uploader !== null && (
                    <p className="flex shrink-0 items-center gap-2 text-xs text-muted-foreground">
                        <UserAvatar user={file.uploader} size="sm" />
                        Shared by {file.uploader.name}
                        {file.created_at !== null && (
                            <> · {dayLabel(file.created_at)}</>
                        )}
                    </p>
                )}

                <DialogFooter className="shrink-0 sm:justify-between">
                    {containerHref !== null ? (
                        <Button asChild variant="outline" size="sm">
                            <a href={containerHref}>
                                <ContainerLabel container={file.container} />
                            </a>
                        </Button>
                    ) : (
                        <span />
                    )}

                    <Button asChild size="sm">
                        <a href={file.download_url} download={file.name}>
                            <Download aria-hidden="true" className="size-4" />
                            Download
                        </a>
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/** The best inline preview available for this file, or null when there is none. */
function filePreviewSource(file: FileRow): string | null {
    if (file.kind === 'pdf') {
        return file.preview_url;
    }

    if (file.kind !== 'image') {
        return null;
    }

    if (file.is_animated) {
        return file.preview_url;
    }

    return file.thumbnail_url ?? file.preview_url;
}
