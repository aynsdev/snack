import { Head, Link, usePoll } from '@inertiajs/react';
import { Film, Image, Loader2, MessageSquare } from 'lucide-react';
import { useEffect } from 'react';
import { useWorkspace } from '@/hooks/use-workspace';
import { show as showChat } from '@/routes/bite/chats';
import type { BiteGeneration, MediaKind } from '@/types/bite';

type Props = {
    kind: MediaKind | null;
    generations: {
        data: BiteGeneration[];
        next_page_url: string | null;
        prev_page_url: string | null;
    };
};

/**
 * The Bite page's Media tab: everything Bite made for you, newest first.
 *
 * Items still being made show as placeholders and the page refreshes itself
 * while any are — a video takes minutes, and the gallery is where people wait.
 */
export default function BiteMedia({ kind, generations }: Props) {
    const inProgress = generations.data.some(
        (generation) =>
            generation.status === 'pending' ||
            generation.status === 'processing',
    );

    const poll = usePoll(
        10_000,
        { only: ['generations'] },
        { autoStart: false },
    );

    useEffect(() => {
        if (inProgress) {
            poll.start();
        } else {
            poll.stop();
        }
    }, [inProgress, poll]);

    const heading =
        kind === 'image' ? 'Images' : kind === 'video' ? 'Videos' : 'Media';

    return (
        <>
            <Head title={`${heading} · Bite`} />

            <div className="snack-scroll flex min-w-0 flex-1 flex-col overflow-y-auto">
                <header className="border-b border-border px-4 py-3">
                    <h1 className="text-[0.9375rem] font-semibold">
                        {heading}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Images and videos Bite made for you.
                    </p>
                </header>

                {generations.data.length === 0 ? (
                    <div className="flex flex-1 flex-col items-center justify-center gap-2 px-4 py-16 text-center">
                        <Image
                            aria-hidden="true"
                            className="size-8 text-muted-foreground"
                        />
                        <p className="text-sm text-muted-foreground">
                            Nothing yet. Ask Bite to make an image or a video
                            and it will appear here.
                        </p>
                    </div>
                ) : (
                    <ul className="grid grid-cols-2 gap-3 p-4 sm:grid-cols-3 xl:grid-cols-4">
                        {generations.data.map((generation) => (
                            <MediaTile
                                key={generation.id}
                                generation={generation}
                            />
                        ))}
                    </ul>
                )}

                {(generations.prev_page_url || generations.next_page_url) && (
                    <nav className="flex justify-between px-4 pb-6 text-sm">
                        {generations.prev_page_url ? (
                            <Link href={generations.prev_page_url}>
                                ← Newer
                            </Link>
                        ) : (
                            <span />
                        )}
                        {generations.next_page_url && (
                            <Link href={generations.next_page_url}>
                                Older →
                            </Link>
                        )}
                    </nav>
                )}
            </div>
        </>
    );
}

function MediaTile({ generation }: { generation: BiteGeneration }) {
    const workspace = useWorkspace();
    const file = generation.attachment;
    const isVideo = generation.kind === 'video';

    return (
        <li className="group overflow-hidden rounded-xl border border-border bg-card">
            <div className="relative aspect-square bg-muted">
                {file === null ? (
                    <div className="flex size-full flex-col items-center justify-center gap-2 text-muted-foreground">
                        <Loader2
                            aria-hidden="true"
                            className="size-5 motion-safe:animate-spin"
                        />
                        <span className="text-xs">
                            Making your {generation.kind}…
                        </span>
                    </div>
                ) : isVideo && file.stream_url ? (
                    <video
                        src={file.stream_url}
                        controls
                        preload="metadata"
                        className="size-full object-cover"
                    />
                ) : (
                    <a
                        href={file.download_url}
                        target="_blank"
                        rel="noreferrer"
                    >
                        <img
                            src={
                                file.thumbnail_url ??
                                file.preview_url ??
                                file.download_url
                            }
                            alt={generation.prompt}
                            loading="lazy"
                            className="size-full object-cover"
                        />
                    </a>
                )}

                <span className="absolute top-2 left-2 inline-flex items-center gap-1 rounded-md bg-background/80 px-1.5 py-0.5 text-xs font-medium backdrop-blur">
                    {isVideo ? (
                        <Film aria-hidden="true" className="size-3" />
                    ) : (
                        <Image aria-hidden="true" className="size-3" />
                    )}
                    {generation.model}
                </span>
            </div>

            <div className="space-y-1 p-2.5">
                <p className="line-clamp-2 text-sm" title={generation.prompt}>
                    {generation.prompt}
                </p>

                {generation.chat_id && (
                    <Link
                        href={showChat.url({
                            workspace: workspace.slug,
                            conversation: generation.chat_id,
                        })}
                        className="inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"
                    >
                        <MessageSquare aria-hidden="true" className="size-3" />
                        Open chat
                    </Link>
                )}
            </div>
        </li>
    );
}
