import { ExternalLink, Link2, Plus, X } from 'lucide-react';
import { useState } from 'react';
import { TaskPicker } from '@/components/snack/projects/task-picker';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
import type { TaskLink, TaskRelation, TaskRelationType } from '@/types';

const RELATION_TYPES: { value: TaskRelationType; label: string }[] = [
    { value: 'blocks', label: 'Blocks' },
    { value: 'relates_to', label: 'Relates to' },
    { value: 'duplicates', label: 'Duplicates' },
];

/**
 * What a card points at: web links such as pull requests, and other cards it
 * blocks, relates to, or duplicates.
 *
 * Links open in a new tab — they lead out of Snack, and the card should still
 * be there to come back to. A linked card shows its status, which is usually
 * the thing worth knowing ("is the thing blocking this done yet?").
 */
export function TaskLinks({
    workspaceSlug,
    taskId,
    links,
    relations,
    canContribute,
    onAddLink,
    onRemoveLink,
    onAddRelation,
    onRemoveRelation,
}: {
    workspaceSlug: string;
    taskId: number;
    links: TaskLink[];
    relations: TaskRelation[];
    canContribute: boolean;
    onAddLink: (url: string, title: string | null) => Promise<void>;
    onRemoveLink: (id: number) => void;
    onAddRelation: (taskId: number, type: TaskRelationType) => Promise<void>;
    onRemoveRelation: (id: number) => void;
}) {
    const [adding, setAdding] = useState<'link' | 'task' | null>(null);
    const [url, setUrl] = useState('');
    const [title, setTitle] = useState('');
    const [relationType, setRelationType] =
        useState<TaskRelationType>('relates_to');

    const close = () => {
        setAdding(null);
        setUrl('');
        setTitle('');
    };

    const empty = links.length === 0 && relations.length === 0;

    return (
        <section className="mt-6 border-t border-border pt-4">
            <div className="flex items-center justify-between gap-2">
                <h3 className="flex items-center gap-1.5 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                    <Link2 aria-hidden="true" className="size-3.5" />
                    Links
                </h3>

                {canContribute && adding === null && (
                    <div className="flex gap-1.5">
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            onClick={() => setAdding('link')}
                        >
                            <Plus aria-hidden="true" />
                            Web link
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            onClick={() => setAdding('task')}
                        >
                            <Plus aria-hidden="true" />
                            Task
                        </Button>
                    </div>
                )}
            </div>

            {adding === 'link' && (
                <form
                    className="mt-2 space-y-2 rounded-lg border border-border p-2.5"
                    onSubmit={(event) => {
                        event.preventDefault();

                        if (url.trim() === '') {
                            return;
                        }

                        onAddLink(url.trim(), title.trim() || null)
                            .then(close)
                            .catch(() => undefined);
                    }}
                >
                    <Input
                        type="url"
                        required
                        autoFocus
                        value={url}
                        placeholder="https://github.com/…/pull/123"
                        aria-label="Link address"
                        onChange={(event) => setUrl(event.target.value)}
                        className="h-8 text-sm"
                    />
                    <Input
                        value={title}
                        maxLength={200}
                        placeholder="Title (optional)"
                        aria-label="Link title"
                        onChange={(event) => setTitle(event.target.value)}
                        className="h-8 text-sm"
                    />
                    <div className="flex justify-end gap-1.5">
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            onClick={close}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" size="sm">
                            Add link
                        </Button>
                    </div>
                </form>
            )}

            {adding === 'task' && (
                <div className="mt-2 space-y-2 rounded-lg border border-border p-2.5">
                    <div className="flex gap-2">
                        <Select
                            value={relationType}
                            onValueChange={(value) =>
                                setRelationType(value as TaskRelationType)
                            }
                        >
                            <SelectTrigger
                                className="h-8 w-36 shrink-0 text-sm"
                                aria-label="How this task relates"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {RELATION_TYPES.map((type) => (
                                    <SelectItem
                                        key={type.value}
                                        value={type.value}
                                    >
                                        {type.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>

                        <div className="min-w-0 flex-1">
                            <TaskPicker
                                workspaceSlug={workspaceSlug}
                                taskId={taskId}
                                purpose="relation"
                                placeholder="Search tasks…"
                                onPick={(option) =>
                                    onAddRelation(option.id, relationType)
                                        .then(close)
                                        .catch(() => undefined)
                                }
                            />
                        </div>
                    </div>
                    <div className="flex justify-end">
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            onClick={close}
                        >
                            Cancel
                        </Button>
                    </div>
                </div>
            )}

            <ul className="mt-2 space-y-1.5">
                {links.map((link) => (
                    <li
                        key={`link-${link.id}`}
                        className="flex items-center gap-2.5 rounded-lg border border-border bg-card px-3 py-2"
                    >
                        <ExternalLink
                            aria-hidden="true"
                            className="size-4 shrink-0 text-brand"
                        />
                        <a
                            href={link.url}
                            target="_blank"
                            rel="noopener noreferrer"
                            title={link.url}
                            className="min-w-0 flex-1 truncate text-sm font-medium hover:underline"
                        >
                            {link.label}
                        </a>
                        {canContribute && (
                            <RemoveButton
                                label={`Remove link ${link.label}`}
                                onClick={() => onRemoveLink(link.id)}
                            />
                        )}
                    </li>
                ))}

                {relations.map((relation) => (
                    <li
                        key={`relation-${relation.id}`}
                        className="flex items-center gap-2.5 rounded-lg border border-border bg-card px-3 py-2 text-sm"
                    >
                        <span className="w-28 shrink-0 text-xs text-muted-foreground">
                            {relation.wording}
                        </span>
                        <span className="shrink-0 text-xs font-medium tabular-nums">
                            {relation.task.reference}
                        </span>
                        <span
                            className={cn(
                                'min-w-0 flex-1 truncate',
                                relation.task.is_complete &&
                                    'text-muted-foreground line-through',
                            )}
                        >
                            {relation.task.title}
                        </span>
                        <span className="shrink-0 rounded bg-muted px-1.5 py-0.5 text-[0.6875rem] text-muted-foreground">
                            {relation.task.status}
                        </span>
                        {canContribute && (
                            <RemoveButton
                                label={`Unlink ${relation.task.reference}`}
                                onClick={() => onRemoveRelation(relation.id)}
                            />
                        )}
                    </li>
                ))}
            </ul>

            {empty && adding === null && (
                <p className="mt-2 text-sm text-muted-foreground">
                    No links on this card.
                </p>
            )}
        </section>
    );
}

function RemoveButton({
    label,
    onClick,
}: {
    label: string;
    onClick: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            className="shrink-0 text-muted-foreground transition-colors hover:text-foreground"
        >
            <X aria-hidden="true" className="size-4" />
            <span className="sr-only">{label}</span>
        </button>
    );
}
