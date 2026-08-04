import { router } from '@inertiajs/react';
import { PanelLeft } from 'lucide-react';
import type { ReactNode } from 'react';
import { useEffect, useState } from 'react';
import { ChannelSync } from '@/components/snack/channel-sync';
import { ConversationSync } from '@/components/snack/conversation-sync';
import { HuddleTray } from '@/components/snack/huddle/huddle-tray';
import { WorkspaceRail } from '@/components/snack/workspace-rail';
import { WorkspaceSidebar } from '@/components/snack/workspace-sidebar';
import { Sheet, SheetContent, SheetTitle } from '@/components/ui/sheet';
import { HuddleProvider } from '@/hooks/use-huddle';
import { LivePresenceProvider } from '@/hooks/use-live-presence';
import { useIsMobile } from '@/hooks/use-mobile';
import { usePresence } from '@/hooks/use-presence';
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

    /*
     * Presence beats from the shell, above the `current === null` branch below,
     * for two reasons: hooks may not be called conditionally, and presence is a
     * property of the person rather than of a workspace — somebody sitting on
     * the workspace picker is still here.
     */
    usePresence(currentUser !== null);

    // Rendered outside a workspace: the picker, or a redirect in flight.
    if (current === null) {
        return <div className="h-dvh overflow-y-auto">{children}</div>;
    }

    return (
        /*
         * Wraps the whole shell so every avatar in it — sidebar, DM list,
         * message rows, the conversation header — reads the same live presence
         * overlay. Scoped to the workspace because that is the channel the
         * updates arrive on and the audience entitled to them.
         */
        <LivePresenceProvider workspaceId={current.workspace.id}>
            {/*
             * Wraps the shell rather than living in a page, because a huddle has
             * to survive navigation: walking from #design to a DM while talking
             * is ordinary, and a connection owned by the channel page would be
             * torn down on that click. See use-huddle.tsx.
             */}
            <HuddleProvider>
                <div className="flex h-dvh overflow-hidden bg-background">
                    {/*
                     * Mounted once, here, so the DM and channel lists and their
                     * badges stay current while the reader is somewhere else in the
                     * workspace. Rendered inside this branch because they need a
                     * signed-in user, and not inside the sidebar because that is
                     * mounted twice on mobile — which would double every refresh.
                     */}
                    {currentUser !== null && (
                        <>
                            <ConversationSync userId={currentUser.id} />
                            <ChannelSync userId={currentUser.id} />
                        </>
                    )}

                    <div className="hidden md:flex">
                        <WorkspaceRail
                            currentWorkspaceSlug={current.workspace.slug}
                        />
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
                                        currentWorkspaceSlug={
                                            current.workspace.slug
                                        }
                                    />
                                    <div className="min-w-0 flex-1">
                                        <WorkspaceSidebar
                                            onNavigate={() =>
                                                setSidebarOpen(false)
                                            }
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
                                <PanelLeft
                                    aria-hidden="true"
                                    className="size-4"
                                />
                                <span className="font-medium">
                                    {current.workspace.name}
                                </span>
                            </button>
                        )}

                        <main className="flex min-h-0 flex-1">{children}</main>
                    </div>
                </div>

                {/*
                 * Outside the flex shell and fixed-positioned, so the call
                 * controls float above whatever page is open rather than being
                 * laid out by it.
                 */}
                <HuddleTray />
            </HuddleProvider>
        </LivePresenceProvider>
    );
}
