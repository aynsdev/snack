import { Form, Head, router } from '@inertiajs/react';
import {
    ArrowUpRight,
    Film,
    Image,
    MessageSquare,
    MoreVertical,
    Pencil,
    Trash2,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { BiteIcon } from '@/components/bite/bite-icon';
import { BiteModelControls } from '@/components/bite/bite-model-controls';
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
import type { BiteChatSummary, BiteMediaModel } from '@/types/bite';

type Props = {
    /** Read by the sidebar; see BiteSidebar. */
    biteChats: BiteChatSummary[];
    /** The open chat, or null for the "new chat" state. */
    chat: Conversation | null;
    history: MessagePage | null;
    canPost: boolean;
    mediaModels: BiteMediaModel[];
};

/**
 * The Bite page: one chat with Bite, or a fresh one waiting for its first
 * question.
 *
 * A chat is an ordinary conversation, so the timeline is the DM page's —
 * the same hooks, list and composer — minus the parts that only make sense
 * between people: huddles, pins, canvases, the roster.
 */
export default function BiteShow({
    chat,
    history,
    canPost,
    mediaModels = [],
}: Props) {
    return chat && history ? (
        <BiteChat
            key={chat.id}
            chat={chat}
            history={history}
            canPost={canPost}
            mediaModels={mediaModels}
        />
    ) : (
        <NewBiteChat mediaModels={mediaModels} />
    );
}

const SUGGESTIONS = [
    {
        title: 'Catch me up',
        body: 'Summarise what happened in my channels today',
        icon: MessageSquare,
        kind: null,
    },
    {
        title: 'Create an image',
        body: 'Make a 16:9 banner for our product launch',
        icon: Image,
        kind: 'image',
    },
    {
        title: 'Bring an idea to life',
        body: 'Create a 5 second video of a rocket taking off at sunrise',
        icon: Film,
        kind: 'video',
    },
    {
        title: 'Plan my next move',
        body: 'Which tasks are assigned to me this sprint?',
        icon: ArrowUpRight,
        kind: null,
    },
];

function NewBiteChat({ mediaModels }: { mediaModels: BiteMediaModel[] }) {
    const [model, setModel] = useState<string | null>(null);
    const workspace = useWorkspace();
    const [sending, setSending] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const start = (
        body: string,
        attachmentIds: number[] = [],
        selectedModel = model,
    ) => {
        setSending(true);
        setError(null);

        router.post(
            storeChat.url(workspace.slug),
            { body, attachment_ids: attachmentIds, bite_model: selectedModel },
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
                <div className="snack-scroll flex flex-1 flex-col items-center justify-start gap-8 overflow-y-auto px-5 py-10 md:justify-center">
                    <BiteIcon className="size-16 rounded-2xl shadow-sm" />

                    <div className="text-center">
                        <h1 className="text-3xl font-semibold text-balance">
                            A little Bite. A big head start.
                        </h1>
                        <p className="mx-auto mt-3 max-w-md text-sm leading-6 text-pretty text-muted-foreground">
                            Turn a question into clarity, an idea into an image,
                            or a busy day into a plan.
                        </p>
                    </div>

                    <ul className="grid w-full max-w-2xl gap-3 sm:grid-cols-2">
                        {SUGGESTIONS.map((suggestion) => (
                            <li key={suggestion.title}>
                                <button
                                    type="button"
                                    disabled={sending}
                                    onClick={() =>
                                        start(
                                            suggestion.body,
                                            [],
                                            suggestion.kind === null
                                                ? null
                                                : (mediaModels.find(
                                                      (item) =>
                                                          item.id === model &&
                                                          item.kind ===
                                                              suggestion.kind,
                                                  )?.id ??
                                                      mediaModels.find(
                                                          (item) =>
                                                              item.kind ===
                                                              suggestion.kind,
                                                      )?.id ??
                                                      null),
                                        )
                                    }
                                    className="group flex h-full w-full items-start gap-3 rounded-2xl border border-border/70 bg-card p-4 text-left shadow-xs hover:border-primary/40 hover:bg-accent disabled:opacity-50"
                                >
                                    <suggestion.icon
                                        aria-hidden="true"
                                        className="mt-0.5 size-4 shrink-0 text-muted-foreground"
                                    />
                                    <span>
                                        <span className="block text-sm font-medium">
                                            {suggestion.title}
                                        </span>
                                        <span className="mt-1 block text-xs leading-5 text-pretty text-muted-foreground">
                                            {suggestion.body}
                                        </span>
                                    </span>
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
                    controls={
                        <BiteModelControls
                            models={mediaModels}
                            value={model}
                            onChange={setModel}
                            disabled={sending}
                        />
                    }
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
    mediaModels,
}: {
    chat: Conversation;
    history: MessagePage;
    canPost: boolean;
    mediaModels: BiteMediaModel[];
}) {
    const workspace = useWorkspace();
    const currentUser = useCurrentUser();
    const [threadRootId, setThreadRootId] = useState<number | null>(null);
    const [renaming, setRenaming] = useState(false);
    const [model, setModel] = useState<string | null>(null);

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
                <header className="flex min-h-16 items-center justify-between gap-2 border-b border-border/70 bg-card px-4 py-3 sm:px-6">
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
                        controls={
                            <BiteModelControls
                                models={mediaModels}
                                value={model}
                                onChange={setModel}
                            />
                        }
                        onSend={(body, attachments) =>
                            timeline.send(body, attachments, model)
                        }
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
