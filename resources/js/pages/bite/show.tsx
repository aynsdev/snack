import { Head, router, usePoll } from '@inertiajs/react';
import {
    Archive,
    ArrowUpRight,
    Film,
    Image,
    MessageSquare,
} from 'lucide-react';
import { useState } from 'react';
import { BiteChatActions } from '@/components/bite/bite-chat-actions';
import {
    BiteGenerationProgress,
    BiteThinking,
} from '@/components/bite/bite-generation-progress';
import { BiteIcon } from '@/components/bite/bite-icon';
import { BiteModelControls } from '@/components/bite/bite-model-controls';
import { MessageComposer } from '@/components/snack/message-composer';
import { MessageList } from '@/components/snack/message-list';
import { useBiteReplying } from '@/hooks/use-bite-replying';
import { useConversationMessages } from '@/hooks/use-conversation-messages';
import { useCurrentUser, useWorkspace } from '@/hooks/use-workspace';
import { channels } from '@/lib/echo';
import { store as storeChat } from '@/routes/bite/chats';
import type { Conversation, MessagePage } from '@/types';
import type {
    BiteChatSummary,
    BiteMediaModel,
    BitePendingGeneration,
} from '@/types/bite';

type Props = {
    /** Read by the sidebar; see BiteSidebar. */
    biteChats: BiteChatSummary[];
    /** The open chat, or null for the "new chat" state. */
    chat: Conversation | null;
    history: MessagePage | null;
    canPost: boolean;
    mediaModels: BiteMediaModel[];
    mediaGenerations: BitePendingGeneration[];
    chatMenu?: BiteChatSummary;
    biteArchived?: boolean;
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
    mediaGenerations = [],
    chatMenu,
    biteArchived = false,
}: Props) {
    return chat && history && chatMenu ? (
        <BiteChat
            key={chat.id}
            chat={chat}
            history={history}
            canPost={canPost}
            mediaModels={mediaModels}
            mediaGenerations={mediaGenerations}
            chatMenu={chatMenu}
        />
    ) : (
        <NewBiteChat mediaModels={mediaModels} archived={biteArchived} />
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

function NewBiteChat({
    mediaModels,
    archived,
}: {
    mediaModels: BiteMediaModel[];
    archived: boolean;
}) {
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

    if (archived) {
        return (
            <>
                <Head title="Archived chats · Bite" />
                <div className="flex min-w-0 flex-1 flex-col items-center justify-center gap-3 px-6 text-center">
                    <Archive
                        aria-hidden="true"
                        className="size-7 text-muted-foreground"
                    />
                    <h1 className="text-xl font-semibold text-balance">
                        Archived chats
                    </h1>
                    <p className="max-w-sm text-sm text-pretty text-muted-foreground">
                        Choose a chat from the sidebar. Restore it from its menu
                        whenever you want to continue.
                    </p>
                </div>
            </>
        );
    }

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
    mediaGenerations,
    chatMenu,
}: {
    chat: Conversation;
    history: MessagePage;
    canPost: boolean;
    mediaModels: BiteMediaModel[];
    mediaGenerations: BitePendingGeneration[];
    chatMenu: BiteChatSummary;
}) {
    const workspace = useWorkspace();
    const currentUser = useCurrentUser();
    const [model, setModel] = useState<string | null>(null);

    const timeline = useConversationMessages({
        conversation: chat,
        workspaceSlug: workspace.slug,
        initial: history,
        currentUser,
    });

    const biteReplying = useBiteReplying({
        channelName: channels.conversation(chat.id),
    });

    usePoll(4000, { only: ['mediaGenerations'] }, { mode: 'rest' });

    const activity =
        mediaGenerations.length > 0 ? (
            <div>
                {mediaGenerations.map((generation) => (
                    <BiteGenerationProgress
                        key={generation.id}
                        generation={generation}
                    />
                ))}
            </div>
        ) : biteReplying !== null ||
          timeline.pending.some((message) => !message.failed) ? (
            <BiteThinking />
        ) : null;

    return (
        <>
            <Head title={`${chat.display_name} · Bite`} />

            <div className="flex min-w-0 flex-1 flex-col">
                <header className="flex min-h-16 items-center justify-between gap-2 border-b border-border/70 bg-card px-4 py-3 sm:px-6">
                    <h1 className="flex min-w-0 items-center gap-2 text-[0.9375rem] font-semibold">
                        <BiteIcon className="size-5 shrink-0" />
                        <span className="truncate">{chat.display_name}</span>
                    </h1>

                    <BiteChatActions chat={chatMenu} label="Chat options" />
                </header>

                <MessageList
                    variant="assistant"
                    footer={activity}
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
                />

                {canPost ? (
                    <MessageComposer
                        channelName="Bite"
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
                            {chatMenu.is_archived
                                ? 'This chat is archived. Restore it from the chat menu to continue.'
                                : 'Bite is switched off for you in this workspace. You can still read this chat.'}
                        </p>
                    </div>
                )}
            </div>
        </>
    );
}
