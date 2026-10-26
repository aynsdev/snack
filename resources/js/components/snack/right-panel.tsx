import { Maximize2, Minimize2, X } from 'lucide-react';
import type { ReactNode } from 'react';
import { Sheet, SheetContent, SheetTitle } from '@/components/ui/sheet';
import { useIsMobile } from '@/hooks/use-mobile';
import {
    PANEL_MIN_WIDTH,
    useResizablePanel,
} from '@/hooks/use-resizable-panel';
import { cn } from '@/lib/utils';

/**
 * The optional fourth column: thread view, channel details, member list,
 * pinned messages, shared files, search details.
 *
 * Owned by the page rather than the layout, because what belongs in it is
 * entirely a property of the page you are on — and because a thread panel needs
 * to open and close in response to a click on a specific message.
 *
 * Docked beside the content on wide screens; a drawer below `md`, where two
 * side-by-side scroll regions would leave neither usable.
 */
export function RightPanel({
    title,
    onClose,
    resizeKey,
    children,
}: {
    title: string;
    onClose: () => void;
    /** Makes the docked panel resizable and expandable, remembered under this key. */
    resizeKey?: string;
    children: ReactNode;
}) {
    const isMobile = useIsMobile();

    if (isMobile) {
        return (
            <Sheet open onOpenChange={(open) => !open && onClose()}>
                <SheetContent side="right" className="w-full p-0 sm:w-96">
                    <SheetTitle className="px-4 py-3 text-sm font-semibold">
                        {title}
                    </SheetTitle>
                    <div className="snack-scroll h-full overflow-y-auto">
                        {children}
                    </div>
                </SheetContent>
            </Sheet>
        );
    }

    if (resizeKey !== undefined) {
        return (
            <ResizablePanel
                title={title}
                onClose={onClose}
                resizeKey={resizeKey}
            >
                {children}
            </ResizablePanel>
        );
    }

    return (
        <aside
            aria-label={title}
            className="hidden w-80 shrink-0 flex-col border-l border-border bg-background lg:flex xl:w-96"
        >
            <header className="flex items-center justify-between border-b border-border px-4 py-3">
                <h2 className="text-sm font-semibold">{title}</h2>

                <button
                    type="button"
                    onClick={onClose}
                    className="rounded-md p-1.5 transition-colors hover:bg-accent"
                >
                    <X aria-hidden="true" className="size-4" />
                    <span className="sr-only">Close {title}</span>
                </button>
            </header>

            <div className="snack-scroll min-h-0 flex-1 overflow-y-auto">
                {children}
            </div>
        </aside>
    );
}

/**
 * Expanded, the panel takes the whole row and squeezes the content beside it
 * to nothing, so it covers the conversation without the page knowing.
 */
function ResizablePanel({
    title,
    onClose,
    resizeKey,
    children,
}: {
    title: string;
    onClose: () => void;
    resizeKey: string;
    children: ReactNode;
}) {
    const { panelRef, width, maxWidth, expanded, toggleExpanded, handleProps } =
        useResizablePanel(resizeKey);

    return (
        <aside
            ref={panelRef}
            aria-label={title}
            style={
                expanded || width === null
                    ? undefined
                    : { width, maxWidth: 'calc(100% - 20rem)' }
            }
            className={cn(
                'relative hidden shrink-0 flex-col border-l border-border bg-background lg:flex',
                expanded
                    ? 'basis-full border-l-0'
                    : width === null && 'w-80 xl:w-96',
            )}
        >
            {!expanded && (
                <div
                    role="separator"
                    aria-orientation="vertical"
                    aria-label={`Resize ${title}`}
                    aria-valuemin={PANEL_MIN_WIDTH}
                    aria-valuemax={maxWidth}
                    aria-valuenow={width ?? undefined}
                    tabIndex={0}
                    {...handleProps}
                    className="absolute inset-y-0 -left-1 z-10 w-2 cursor-col-resize touch-none outline-none after:absolute after:inset-y-0 after:left-1/2 after:w-px after:-translate-x-1/2 after:transition-colors hover:after:bg-brand focus-visible:after:w-0.5 focus-visible:after:bg-ring"
                />
            )}

            <header className="flex items-center justify-between gap-2 border-b border-border px-4 py-3">
                <h2 className="text-sm font-semibold">{title}</h2>

                <div className="flex items-center gap-1">
                    <button
                        type="button"
                        onClick={toggleExpanded}
                        aria-label={
                            expanded ? `Collapse ${title}` : `Expand ${title}`
                        }
                        aria-pressed={expanded}
                        className="rounded-md p-1.5 transition-colors hover:bg-accent"
                    >
                        {expanded ? (
                            <Minimize2 aria-hidden="true" className="size-4" />
                        ) : (
                            <Maximize2 aria-hidden="true" className="size-4" />
                        )}
                    </button>

                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded-md p-1.5 transition-colors hover:bg-accent"
                    >
                        <X aria-hidden="true" className="size-4" />
                        <span className="sr-only">Close {title}</span>
                    </button>
                </div>
            </header>

            <div className="snack-scroll min-h-0 flex-1 overflow-y-auto">
                {children}
            </div>
        </aside>
    );
}
