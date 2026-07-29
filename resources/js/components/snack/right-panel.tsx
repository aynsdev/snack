import { X } from 'lucide-react';
import type { ReactNode } from 'react';
import { Sheet, SheetContent, SheetTitle } from '@/components/ui/sheet';
import { useIsMobile } from '@/hooks/use-mobile';

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
    children,
}: {
    title: string;
    onClose: () => void;
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
