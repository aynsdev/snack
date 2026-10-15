import { Head } from '@inertiajs/react';
import { Share2 } from 'lucide-react';
import { useState } from 'react';
import { MessageList } from '@/components/snack/message-list';
import { useWorkspace } from '@/hooks/use-workspace';
import { api } from '@/lib/api';
import { show } from '@/routes/bite/shared';
import type { MessagePage } from '@/types';

type Props = {
    sharedChat: {
        title: string;
        owner_id: number | null;
        shared_at: string | null;
    };
    shareToken: string;
    history: MessagePage;
};

export default function SharedBiteChat(props: Props) {
    return <SharedChat key={props.shareToken} {...props} />;
}

function SharedChat({ sharedChat, shareToken, history: initial }: Props) {
    const workspace = useWorkspace();
    const [history, setHistory] = useState(initial);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const loadOlder = async () => {
        if (loading || !history.has_more || history.next_cursor === null) {
            return;
        }

        setLoading(true);
        setError(null);

        try {
            const older = await api.get<MessagePage>(
                show.url(
                    { workspace: workspace.slug, token: shareToken },
                    { query: { before: history.next_cursor } },
                ),
            );
            setHistory((current) => ({
                ...older,
                messages: [...older.messages, ...current.messages],
            }));
        } catch {
            setError(
                'Earlier messages could not be loaded. Scroll up to try again.',
            );
        } finally {
            setLoading(false);
        }
    };

    return (
        <>
            <Head title={`${sharedChat.title} · Shared chat`} />
            <div className="flex min-w-0 flex-1 flex-col">
                <header className="border-b border-border/70 bg-card px-6 py-4">
                    <div className="flex items-center gap-2 text-xs text-muted-foreground">
                        <Share2 aria-hidden="true" className="size-3.5" />
                        Read-only shared chat
                    </div>
                    <h1 className="mt-1 text-lg font-semibold text-balance">
                        {sharedChat.title}
                    </h1>
                    <p className="mt-1 text-xs text-muted-foreground">
                        Shared with {workspace.name}. New messages in the
                        private chat are not included.
                    </p>
                    {error && (
                        <p
                            role="alert"
                            className="mt-2 text-sm text-destructive"
                        >
                            {error}
                        </p>
                    )}
                </header>
                <MessageList
                    variant="assistant"
                    messages={history.messages}
                    pending={[]}
                    hasMore={history.has_more}
                    loadingOlder={loading}
                    currentUserId={sharedChat.owner_id}
                    onLoadOlder={() => void loadOlder()}
                    onEdit={async () => {}}
                    onDelete={() => {}}
                    onRetry={() => {}}
                    onDiscard={() => {}}
                    onReachBottom={() => {}}
                />
            </div>
        </>
    );
}
