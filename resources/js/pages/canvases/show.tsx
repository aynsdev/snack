import { Head, Link, router } from '@inertiajs/react';
import type { JSONContent } from '@tiptap/core';
import { ChevronLeft, FileImage, Save, Trash2, Upload } from 'lucide-react';
import { useRef, useState } from 'react';
import InputError from '@/components/input-error';
import { CanvasRichTextEditor } from '@/components/snack/canvas-rich-text-editor';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useWorkspace } from '@/hooks/use-workspace';
import { index, update } from '@/routes/canvases';
import {
    destroy as destroyCover,
    store as storeCover,
} from '@/routes/canvases/cover';
import type { CanvasDocument } from '@/types';

export default function CanvasShow({ canvas }: { canvas: CanvasDocument }) {
    return <CanvasEditor key={canvas.id} canvas={canvas} />;
}

function CanvasEditor({ canvas }: { canvas: CanvasDocument }) {
    const workspace = useWorkspace();
    const initialContent = canvas.content ?? legacyContent(canvas.body ?? '');
    const [title, setTitle] = useState(canvas.title);
    const [content, setContent] = useState<JSONContent>(initialContent);
    const [savedSignature, setSavedSignature] = useState(() =>
        signature(canvas.title, initialContent),
    );
    const [saving, setSaving] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [coverBusy, setCoverBusy] = useState(false);
    const [coverError, setCoverError] = useState<string | null>(null);
    const coverPicker = useRef<HTMLInputElement>(null);
    const dirty = signature(title, content) !== savedSignature;

    const save = () => {
        setSaving(true);
        setErrors({});

        router.patch(
            update.url({ workspace: workspace.slug, canvas: canvas.id }),
            { title, content },
            {
                preserveScroll: true,
                onSuccess: () => setSavedSignature(signature(title, content)),
                onError: (nextErrors) => setErrors(nextErrors),
                onFinish: () => setSaving(false),
            },
        );
    };

    const uploadCover = (file: File | undefined) => {
        if (file === undefined) {
            return;
        }

        setCoverBusy(true);
        setCoverError(null);

        router.post(
            storeCover.url({ workspace: workspace.slug, canvas: canvas.id }),
            { cover: file },
            {
                forceFormData: true,
                preserveScroll: true,
                onError: (nextErrors) =>
                    setCoverError(
                        nextErrors.cover ??
                            'That cover image could not be saved.',
                    ),
                onFinish: () => setCoverBusy(false),
            },
        );
    };

    const removeCover = () => {
        setCoverBusy(true);
        setCoverError(null);

        router.delete(
            destroyCover.url({
                workspace: workspace.slug,
                canvas: canvas.id,
            }),
            {
                preserveScroll: true,
                onFinish: () => setCoverBusy(false),
            },
        );
    };

    return (
        <>
            <Head title={canvas.title} />

            <div className="flex min-w-0 flex-1 flex-col bg-background">
                <header className="flex min-h-14 flex-wrap items-center gap-2 border-b border-border px-3 py-2 sm:px-4">
                    <Button asChild variant="ghost" size="sm">
                        <Link
                            href={
                                canvas.container?.url ??
                                index.url(workspace.slug)
                            }
                        >
                            <ChevronLeft aria-hidden="true" />
                            {canvas.container?.label ?? 'Canvases'}
                        </Link>
                    </Button>

                    <div className="min-w-24 flex-1 truncate text-right text-xs text-muted-foreground">
                        {dirty
                            ? 'Unsaved changes'
                            : `Saved${canvas.editor ? ` · Edited by ${canvas.editor.name}` : ''}`}
                    </div>

                    {canvas.can_update && (
                        <Button
                            type="button"
                            size="sm"
                            disabled={saving || !dirty}
                            onClick={save}
                        >
                            <Save aria-hidden="true" />
                            {saving ? 'Saving…' : 'Save'}
                        </Button>
                    )}
                </header>

                <main className="snack-scroll min-h-0 flex-1 overflow-y-auto">
                    <div className="relative flex min-h-full flex-col pb-24">
                        {canvas.cover_url ? (
                            <div className="group relative h-52 shrink-0 overflow-hidden border-b border-border bg-muted sm:h-64 lg:h-72">
                                <img
                                    src={canvas.cover_url}
                                    alt=""
                                    className="size-full object-cover"
                                />

                                {canvas.can_update && (
                                    <div className="absolute top-4 right-4 flex gap-2 rounded-lg border border-border bg-background/95 p-1 shadow-sm">
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            disabled={coverBusy}
                                            onClick={() =>
                                                coverPicker.current?.click()
                                            }
                                        >
                                            <Upload aria-hidden="true" />
                                            Change
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            aria-label="Remove cover"
                                            disabled={coverBusy}
                                            onClick={removeCover}
                                        >
                                            <Trash2 aria-hidden="true" />
                                        </Button>
                                    </div>
                                )}
                            </div>
                        ) : null}

                        <div className="mx-auto flex w-full max-w-4xl flex-1 flex-col px-5 py-8 sm:px-8 sm:py-10 lg:px-12">
                            {canvas.can_update && !canvas.cover_url && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    disabled={coverBusy}
                                    onClick={() => coverPicker.current?.click()}
                                    className="mb-5 w-fit text-muted-foreground"
                                >
                                    <FileImage aria-hidden="true" />
                                    {coverBusy
                                        ? 'Uploading cover…'
                                        : 'Add cover'}
                                </Button>
                            )}

                            <input
                                ref={coverPicker}
                                type="file"
                                accept="image/png,image/jpeg,image/webp,image/gif"
                                className="sr-only"
                                aria-label="Choose a Canvas cover image"
                                onChange={(event) => {
                                    uploadCover(event.target.files?.[0]);
                                    event.target.value = '';
                                }}
                            />

                            <InputError message={coverError ?? undefined} />

                            <Input
                                value={title}
                                onChange={(event) =>
                                    setTitle(event.target.value)
                                }
                                maxLength={160}
                                aria-label="Canvas title"
                                className="h-auto border-0 px-0 py-1 text-4xl font-bold text-balance shadow-none focus-visible:ring-0 sm:text-5xl"
                                required
                                readOnly={!canvas.can_update}
                            />
                            <InputError
                                className="mt-1"
                                message={errors.title}
                            />

                            <p className="mt-3 text-xs text-muted-foreground">
                                Created by{' '}
                                {canvas.creator?.name ?? 'a former member'}
                            </p>

                            <CanvasRichTextEditor
                                content={initialContent}
                                editable={canvas.can_update}
                                onChange={(nextContent) =>
                                    setContent(nextContent)
                                }
                            />
                            <InputError
                                className="mt-2"
                                message={errors.content}
                            />
                        </div>
                    </div>
                </main>
            </div>
        </>
    );
}

function legacyContent(body: string): JSONContent {
    const paragraphs = body
        .split(/\n{2,}/)
        .map((paragraph) => paragraph.trim())
        .filter(Boolean)
        .map((paragraph) => ({
            type: 'paragraph',
            content: [{ type: 'text', text: paragraph }],
        }));

    return {
        type: 'doc',
        content: paragraphs.length > 0 ? paragraphs : [{ type: 'paragraph' }],
    };
}

function signature(title: string, content: JSONContent): string {
    return `${title}\n${JSON.stringify(content)}`;
}
