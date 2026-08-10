import { Link, router } from '@inertiajs/react';
import { FileText, MessageCircle, Plus } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useWorkspace } from '@/hooks/use-workspace';
import { show as showCanvas } from '@/routes/canvases';
import type { CanvasDocument } from '@/types';

type Props = {
    canvases: CanvasDocument[];
    createUrl: string;
    canCreate: boolean;
    menuLabel: string;
};

export function ContainerCanvasTabs({
    canvases,
    createUrl,
    canCreate,
    menuLabel,
}: Props) {
    const workspace = useWorkspace();
    const [creating, setCreating] = useState(false);

    const createCanvas = () => {
        setCreating(true);
        router.post(createUrl, {}, { onFinish: () => setCreating(false) });
    };

    return (
        <nav
            aria-label="Conversation content"
            className="flex min-h-10 items-end gap-1 overflow-x-auto border-b border-border px-3"
        >
            <span
                aria-current="page"
                className="flex h-10 shrink-0 items-center gap-1.5 border-b-2 border-brand px-2 text-sm font-semibold"
            >
                <MessageCircle aria-hidden="true" className="size-4" />
                Messages
            </span>

            {canvases.map((canvas) => (
                <Link
                    key={canvas.id}
                    href={showCanvas({
                        workspace: workspace.slug,
                        canvas: canvas.id,
                    })}
                    className="flex h-10 max-w-48 shrink-0 items-center gap-1.5 rounded-t-md px-2 text-sm text-muted-foreground hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                >
                    <FileText aria-hidden="true" className="size-4 shrink-0" />
                    <span className="truncate">{canvas.title}</span>
                </Link>
            ))}

            {canCreate && (
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            aria-label="Add a tab"
                            title="Add a tab"
                            disabled={creating}
                            className="mb-0.5 size-9 shrink-0 text-muted-foreground"
                        >
                            <Plus aria-hidden="true" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="start" className="w-64">
                        <DropdownMenuLabel>{menuLabel}</DropdownMenuLabel>
                        <DropdownMenuItem
                            onSelect={createCanvas}
                            className="py-2.5"
                        >
                            <span className="flex size-8 items-center justify-center rounded-md bg-brand-subtle text-brand">
                                <FileText aria-hidden="true" />
                            </span>
                            <span>
                                <span className="block font-medium">
                                    Canvas
                                </span>
                                <span className="block text-xs text-muted-foreground">
                                    Create a shared document
                                </span>
                            </span>
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            )}
        </nav>
    );
}
