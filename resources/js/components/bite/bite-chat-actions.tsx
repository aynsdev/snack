import { Form, router } from '@inertiajs/react';
import {
    Archive,
    ArchiveRestore,
    Copy,
    MoreHorizontal,
    Pencil,
    Pin,
    PinOff,
    Share2,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
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
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useClipboard } from '@/hooks/use-clipboard';
import { useWorkspace } from '@/hooks/use-workspace';
import { api, ApiError } from '@/lib/api';
import { cn } from '@/lib/utils';
import { destroy, update } from '@/routes/bite/chats';
import {
    destroy as revokeShare,
    store as createShare,
} from '@/routes/bite/chats/share';
import type { BiteChatSummary } from '@/types/bite';

type Mode = 'rename' | 'share' | 'delete' | null;

export function BiteChatActions({
    chat,
    className,
    label,
}: {
    chat: BiteChatSummary;
    className?: string;
    label?: string;
}) {
    const workspace = useWorkspace();
    const args = { workspace: workspace.slug, conversation: chat.id };
    const [mode, setMode] = useState<Mode>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [shareUrl, setShareUrl] = useState<string | null>(chat.share_url);
    const [copied, setCopied] = useState(false);
    const [, copy] = useClipboard();

    const open = (next: Mode) => {
        setError(null);
        setCopied(false);
        setShareUrl(chat.share_url);
        setMode(next);
    };

    const share = async (revoke = false) => {
        setBusy(true);
        setError(null);
        setCopied(false);

        try {
            if (revoke) {
                await api.delete(revokeShare.url(args));
                setShareUrl(null);
            } else {
                const response = await api.post<{ url: string }>(
                    createShare.url(args),
                );
                setShareUrl(response.url);
            }

            router.reload({ only: ['biteChats', 'chatMenu'] });
        } catch (exception) {
            setError(
                exception instanceof ApiError
                    ? exception.userMessage
                    : 'The share link could not be updated. Try again.',
            );
        } finally {
            setBusy(false);
        }
    };

    const preference = (data: { is_pinned?: boolean; is_archived?: boolean }) =>
        router.patch(update.url(args), data, {
            preserveScroll: true,
            onError: (errors) =>
                setError(
                    Object.values(errors)[0] ?? 'Could not update this chat.',
                ),
        });

    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <button
                        type="button"
                        aria-label={label ?? `Options for ${chat.title}`}
                        className={cn(
                            'flex size-7 shrink-0 items-center justify-center rounded-md text-muted-foreground hover:bg-foreground/5 hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                            className,
                        )}
                    >
                        <MoreHorizontal aria-hidden="true" className="size-4" />
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-44">
                    <DropdownMenuItem onSelect={() => open('rename')}>
                        <Pencil className="size-4" />
                        Rename
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        onSelect={() =>
                            preference({ is_pinned: !chat.is_pinned })
                        }
                    >
                        {chat.is_pinned ? (
                            <PinOff className="size-4" />
                        ) : (
                            <Pin className="size-4" />
                        )}
                        {chat.is_pinned ? 'Unpin' : 'Pin'}
                    </DropdownMenuItem>
                    <DropdownMenuItem onSelect={() => open('share')}>
                        <Share2 className="size-4" />
                        Share
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        onSelect={() =>
                            preference({ is_archived: !chat.is_archived })
                        }
                    >
                        {chat.is_archived ? (
                            <ArchiveRestore className="size-4" />
                        ) : (
                            <Archive className="size-4" />
                        )}
                        {chat.is_archived ? 'Restore' : 'Archive'}
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                        variant="destructive"
                        onSelect={() => open('delete')}
                    >
                        <Trash2 className="size-4" />
                        Delete
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>

            <Dialog
                open={mode === 'rename'}
                onOpenChange={(isOpen) => !isOpen && setMode(null)}
            >
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Rename chat</DialogTitle>
                        <DialogDescription>
                            Give this chat a name you can find again.
                        </DialogDescription>
                    </DialogHeader>
                    <Form
                        action={update(args)}
                        options={{ preserveScroll: true }}
                        onSuccess={() => setMode(null)}
                        className="space-y-4"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="space-y-2">
                                    <Label htmlFor={`bite-title-${chat.id}`}>
                                        Title
                                    </Label>
                                    <Input
                                        id={`bite-title-${chat.id}`}
                                        name="name"
                                        required
                                        maxLength={80}
                                        defaultValue={chat.title}
                                        autoFocus
                                    />
                                    <InputError message={errors.name} />
                                </div>
                                <DialogFooter>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        onClick={() => setMode(null)}
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
                </DialogContent>
            </Dialog>

            <Dialog
                open={mode === 'share'}
                onOpenChange={(isOpen) => !isOpen && setMode(null)}
            >
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Share chat</DialogTitle>
                        <DialogDescription>
                            Anyone in {workspace.name} with this link can read
                            the messages and files shared up to now. New
                            messages stay private.
                        </DialogDescription>
                    </DialogHeader>
                    {shareUrl && (
                        <div className="space-y-2">
                            <Label htmlFor={`bite-share-${chat.id}`}>
                                Read-only link
                            </Label>
                            <div className="flex gap-2">
                                <Input
                                    id={`bite-share-${chat.id}`}
                                    readOnly
                                    value={shareUrl}
                                    onFocus={(event) => event.target.select()}
                                />
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={async () =>
                                        setCopied(await copy(shareUrl))
                                    }
                                >
                                    <Copy className="size-4" />
                                    {copied ? 'Copied' : 'Copy'}
                                </Button>
                            </div>
                            <p className="text-xs text-muted-foreground">
                                You can also select the link and copy it.
                            </p>
                        </div>
                    )}
                    <InputError message={error ?? undefined} />
                    <DialogFooter>
                        {shareUrl && (
                            <Button
                                type="button"
                                variant="ghost"
                                disabled={busy}
                                onClick={() => void share(true)}
                            >
                                Stop sharing
                            </Button>
                        )}
                        <Button
                            type="button"
                            disabled={busy}
                            onClick={() => void share()}
                        >
                            {busy
                                ? 'Updating…'
                                : shareUrl
                                  ? 'Update shared messages'
                                  : 'Create link'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog
                open={mode === 'delete'}
                onOpenChange={(isOpen) => !isOpen && !busy && setMode(null)}
            >
                <DialogContent
                    role="alertdialog"
                    className="sm:max-w-md"
                    onPointerDownOutside={(event) => event.preventDefault()}
                >
                    <DialogHeader>
                        <DialogTitle>Delete chat?</DialogTitle>
                        <DialogDescription>
                            “{chat.title}” and its messages, images, videos, and
                            shared link will be permanently removed.
                        </DialogDescription>
                    </DialogHeader>
                    <InputError message={error ?? undefined} />
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="ghost"
                            autoFocus
                            disabled={busy}
                            onClick={() => setMode(null)}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="button"
                            variant="destructive"
                            disabled={busy}
                            onClick={() => {
                                setBusy(true);
                                router.delete(destroy.url(args), {
                                    onSuccess: () => setMode(null),
                                    onError: () =>
                                        setError(
                                            'Could not delete this chat. Try again.',
                                        ),
                                    onFinish: () => setBusy(false),
                                });
                            }}
                        >
                            {busy ? 'Deleting…' : 'Delete chat'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
            {error && mode === null && (
                <p role="alert" className="text-xs text-destructive">
                    {error}
                </p>
            )}
        </>
    );
}
