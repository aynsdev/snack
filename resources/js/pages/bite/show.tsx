import { Form, Head, router } from '@inertiajs/react';
import { MoreVertical, Pencil, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import { BiteIcon } from '@/components/bite/bite-icon';
import InputError from '@/components/input-error';
import type { MentionSource } from '@/components/snack/mention-picker';
import { MessageComposer } from '@/components/snack/message-composer';
import { MessageList } from '@/components/snack/message-list';
import { ThreadPanel } from '@/components/snack/thread-panel';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useBiteReplying } from '@/hooks/use-bite-replying';
import { useConversationMessages } from '@/hooks/use-conversation-messages';
import { useCurrentUser, useWorkspace } from '@/hooks/use-workspace';
import { channels } from '@/lib/echo';
import {
    destroy as destroyChat,
    store as storeChat,
    update as updateChat,
} from '@/routes/bite/chats';
import { store as conversationMessageStore } from '@/routes/conversations/messages';
import type { Conversation, Message, MessagePage } from '@/types';
import type { BiteChatSummary } from '@/types/bite';

type Props = {
    /** Read by the sidebar; see BiteSidebar. */
    biteChats: BiteChatSummary[];
    /** The open chat, or null for the "new chat" state. */
    chat: Conversation | null;
    history: MessagePage | null;
    canPost: boolean;
};

/**
 * The Bite page: one chat with Bite, or a fresh one waiting for its first
 * question.
 *
 * A chat is an ordinary conversation, so the timeline is the DM page's —
 * the same hooks, list and composer — minus the parts that only make sense
 * between people: huddles, pins, canvases, the roster.
 */
export default function BiteShow({ chat, history, canPost }: Props) {
    return chat && history ? (
        <BiteChat
            key={chat.id}
            chat={chat}
            history={history}
            canPost={canPost}
        />
    ) : (
        <NewBiteChat />
    );
}

const SUGGESTIONS = [
    'Summarise what happened in my channels today',
    'Make a 16:9 banner for our product launch',
    'Create a 5 second video of a rocket taking off at sunrise',
    'Which tasks are assigned to me this sprint?',
];

function NewBiteChat() {
    const workspace = useWorkspace();
    const [sending, setSending] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const start = (body: string, attachmentIds: number[] = []) => {
        setSending(true);
        setError(null);

        router.post(
            storeChat.url(workspace.slug),
            { body, attachment_ids: attachmentIds },
            {
                onError: (errors) =>
                    setError(errors.body ?? Object.values(errors)[0] ?? null),
                onFinish: () => setSending(false),
            },
        );
    };

    return (
        <>
            <Head title="Bite" />

            <div className="flex min-w-0 flex-1 flex-col">
                <div className="flex flex-1 flex-col items-center justify-center gap-6 overflow-y-auto px-4 py-10">
                    <BiteIcon className="size-14 rounded-2xl" />

                    <div className="text-center">
                        <h1 className="text-xl font-semibold tracking-tight">
                            What can Bite do for you?
                        </h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Ask about this workspace, or have it make an image
                            or a video.
                        </p>
                    </div>

                    <ul className="grid w-full max-w-2xl gap-2 sm:grid-cols-2">
                        {SUGGESTIONS.map((suggestion) => (
                            <li key={suggestion}>
                                <button
                                    type="button"
                                    disabled={sending}
                                    onClick={() => start(suggestion)}
                                    className="w-full rounded-xl border border-border px-3 py-2.5 text-left text-sm text-muted-foreground transition-colors hover:bg-muted hover:text-foreground disabled:opacity-50"
                                >
                                    {suggestion}
                                </button>
                            </li>
                        ))}
                    </ul>

                    {error && (
                        <p role="alert" className="text-sm text-destructive">
                            {error}
                        </p>
                    )}
                </div>

                <MessageComposer
                    channelName="Bite"
                    disabled={sending}
                    workspaceSlug={workspace.slug}
                    onSend={(body, attachmentIds) => start(body, attachmentIds)}
                />
            </div>
        </>
    );
}

function BiteChat({
    chat,
    history,
    canPost,
}: {
    chat: Conversation;
    history: MessagePage;
    canPost: boolean;
}) {
    const workspace = useWorkspace();
    const currentUser = useCurrentUser();
    const [threadRootId, setThreadRootId] = useState<number | null>(null);
    const [renaming, setRenaming] = useState(false);

    // Nobody to mention in a chat with Bite.
    const mentionSource = useMemo<MentionSource>(
        () => ({ kind: 'local', people: [] }),
        [],
    );

    const timeline = useConversationMessages({
        conversation: chat,
        workspaceSlug: workspace.slug,
        initial: history,
        currentUser,
    });

    const biteReplying = useBiteReplying({
        channelName: channels.conversation(chat.id),
    });

    const remove = () => {
        if (
            window.confirm(
                'Delete this chat? Its messages, images and videos are removed for good.',
            )
        ) {
            router.delete(
                destroyChat.url({
                    workspace: workspace.slug,
                    conversation: chat.id,
                }),
            );
        }
    };

    return (
        <>
            <Head title={`${chat.display_name} · Bite`} />

            <div className="flex min-w-0 flex-1 flex-col">
                <header className="flex items-center justify-between gap-2 border-b border-border px-4 py-2.5">
                    <h1 className="flex min-w-0 items-center gap-2 text-[0.9375rem] font-semibold">
                        <BiteIcon className="size-5 shrink-0" />
                        <span className="truncate">{chat.display_name}</span>
                    </h1>

                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button
                                variant="ghost"
                                size="icon"
                                aria-label="Chat options"
                            >
                                <MoreVertical className="size-4" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuItem
                                onSelect={() => setRenaming(true)}
                            >
                                <Pencil className="mr-2 size-4" />
                                Rename
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                onSelect={remove}
                                className="text-destructive focus:text-destructive"
                            >
                                <Trash2 className="mr-2 size-4" />
                                Delete chat
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </header>

                <MessageList
                    messages={timeline.messages}
                    pending={timeline.pending}
                    hasMore={timeline.hasMore}
                    loadingOlder={timeline.loadingOlder}
                    currentUserId={currentUser?.id ?? null}
                    onLoadOlder={timeline.loadOlder}
                    onEdit={timeline.edit}
                    onDelete={timeline.remove}
                    onRetry={timeline.retry}
                    onDiscard={timeline.discard}
                    onReachBottom={timeline.markAsRead}
                    onOpenThread={(message: Message) =>
                        setThreadRootId(message.id)
                    }
                />

                {canPost ? (
                    <MessageComposer
                        channelName="Bite"
                        mentionSource={mentionSource}
                        replying={biteReplying}
                        workspaceSlug={workspace.slug}
                        onSend={timeline.send}
                    />
                ) : (
                    <div className="border-t border-border bg-muted/40 px-4 py-3">
                        <p className="text-sm text-muted-foreground">
                            Bite is switched off for you in this workspace. You
                            can still read this chat.
                        </p>
                    </div>
                )}
            </div>

            {threadRootId !== null && (
                <ThreadPanel
                    key={threadRootId}
                    rootId={threadRootId}
                    workspaceSlug={workspace.slug}
                    sendUrl={conversationMessageStore.url({
                        workspace: workspace.slug,
                        conversation: chat.id,
                    })}
                    containerName={chat.display_name}
                    mentionSource={mentionSource}
                    currentUser={currentUser}
                    canPost={canPost}
                    onClose={() => setThreadRootId(null)}
                />
            )}

            <Dialog open={renaming} onOpenChange={setRenaming}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Rename chat</DialogTitle>
                    </DialogHeader>

                    <Form
                        action={updateChat({
                            workspace: workspace.slug,
                            conversation: chat.id,
                        })}
                        options={{ preserveScroll: true }}
                        onSuccess={() => setRenaming(false)}
                        className="space-y-4"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="space-y-2">
                                    <Label htmlFor="bite-chat-name">
                                        Title
                                    </Label>
                                    <Input
                                        id="bite-chat-name"
                                        name="name"
                                        autoFocus
                                        required
                                        maxLength={80}
                                        defaultValue={chat.name ?? ''}
                                    />
                                    <InputError message={errors.name} />
                                </div>

                                <DialogFooter>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        onClick={() => setRenaming(false)}
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
        </>
    );
}
