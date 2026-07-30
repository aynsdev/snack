import { router } from '@inertiajs/react';
import { PanelLeft } from 'lucide-react';
import type { ReactNode } from 'react';
import { useEffect, useState } from 'react';
import { ConversationSync } from '@/components/snack/conversation-sync';
import { WorkspaceRail } from '@/components/snack/workspace-rail';
import { WorkspaceSidebar } from '@/components/snack/workspace-sidebar';
import { Sheet, SheetContent, SheetTitle } from '@/components/ui/sheet';
import { useIsMobile } from '@/hooks/use-mobile';
import { useCurrentUser, useCurrentWorkspace } from '@/hooks/use-workspace';

export type WorkspaceLayoutProps = {
    children: ReactNode;
};

/**
 * The collaboration shell: workspace rail, sidebar, and content area.
 *
 * The optional fourth column (threads, channel details, pinned messages) is
 * owned by each page through {@see RightPanel}, since what belongs in it —
 * and when it opens — is a property of the page, not the shell.
 *
 * Desktop-first, as the brief asks. Below `md` the rail and sidebar collapse
 * into a single drawer so the message area keeps the full width, which is the
 * only column that matters on a phone.
 *
 * The shell is `h-dvh overflow-hidden` and only the message list scrolls: that
 * is what keeps the composer pinned when iOS shows and hides its URL bar.
 */
export default function WorkspaceLayout({ children }: WorkspaceLayoutProps) {
    const current = useCurrentWorkspace();
    const currentUser = useCurrentUser();
    const isMobile = useIsMobile();
    const [sidebarOpen, setSidebarOpen] = useState(false);

    // Close the drawer whenever navigation lands somewhere new; otherwise it
    // stays open on top of the page the user just chose.
    //
    // Subscribing to the router rather than reacting to a `url` change: the
    // latter would set state during render-after-effect and cascade an extra
    // render on every single navigation, drawer or not.
    useEffect(() => router.on('navigate', () => setSidebarOpen(false)), []);

    // Rendered outside a workspace: the picker, or a redirect in flight.
    if (current === null) {
        return <div className="h-dvh overflow-y-auto">{children}</div>;
    }

    return (
        <div className="flex h-dvh overflow-hidden bg-background">
            {/*
             * Mounted once, here, so the DM list and its badges stay current
             * while the reader is somewhere else in the workspace. Rendered
             * inside this branch because it needs a signed-in user, and not
             * inside the sidebar because that is mounted twice on mobile.
             */}
            {currentUser !== null && (
                <ConversationSync userId={currentUser.id} />
            )}

            <div className="hidden md:flex">
                <WorkspaceRail currentWorkspaceSlug={current.workspace.slug} />
            </div>

            <aside className="hidden w-64 shrink-0 border-r border-sidebar-border md:block lg:w-72">
                <WorkspaceSidebar />
            </aside>

            {isMobile && (
                <Sheet open={sidebarOpen} onOpenChange={setSidebarOpen}>
                    <SheetContent side="left" className="w-[19rem] p-0">
                        <SheetTitle className="sr-only">
                            {current.workspace.name} navigation
                        </SheetTitle>

                        <div className="flex h-full">
                            <WorkspaceRail
                                currentWorkspaceSlug={current.workspace.slug}
                            />
                            <div className="min-w-0 flex-1">
                                <WorkspaceSidebar
                                    onNavigate={() => setSidebarOpen(false)}
                                />
                            </div>
                        </div>
                    </SheetContent>
                </Sheet>
            )}

            <div className="flex min-w-0 flex-1 flex-col">
                {isMobile && (
                    <button
                        type="button"
                        onClick={() => setSidebarOpen(true)}
                        className="flex items-center gap-2 border-b border-border px-4 py-2.5 text-sm hover:bg-accent md:hidden"
                    >
                        <PanelLeft aria-hidden="true" className="size-4" />
                        <span className="font-medium">
                            {current.workspace.name}
                        </span>
                    </button>
                )}

                <main className="flex min-h-0 flex-1">{children}</main>
            </div>
        </div>
    );
}
