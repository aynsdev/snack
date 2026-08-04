import { router, usePage } from '@inertiajs/react';
import { PanelLeft } from 'lucide-react';
import type { ReactNode } from 'react';
import { useEffect, useRef, useState } from 'react';
import { ChannelSync } from '@/components/snack/channel-sync';
import { ConversationSync } from '@/components/snack/conversation-sync';
import { HuddleTray } from '@/components/snack/huddle/huddle-tray';
import { HuddleSync } from '@/components/snack/huddle-sync';
import { NavRail } from '@/components/snack/nav-rail';
import { WorkspaceRail } from '@/components/snack/workspace-rail';
import { WorkspaceSidebar } from '@/components/snack/workspace-sidebar';
import { Sheet, SheetContent, SheetTitle } from '@/components/ui/sheet';
import { useIsActivityView } from '@/hooks/use-active-channel';
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
 * only column that matters on a phone. The drawer takes the whole screen rather
 * than a slice of it: at phone widths a 19rem panel left the sidebar's own three
 * columns fighting over about 12rem, so channel names truncated to nothing while
 * a strip of unreachable conversation sat beside them.
 *
 * The shell is `h-dvh overflow-hidden` and only the message list scrolls: that
 * is what keeps the composer pinned when iOS shows and hides its URL bar.
 */
export default function WorkspaceLayout({ children }: WorkspaceLayoutProps) {
    const current = useCurrentWorkspace();
    const currentUser = useCurrentUser();
    const isMobile = useIsMobile();
    const isActivityView = useIsActivityView();
    const enteredWorkspace = usePage().props.flash.entered_workspace;
    const [sidebarOpen, setSidebarOpen] = useState(false);

    // Close the drawer whenever navigation lands somewhere new; otherwise it
    // stays open on top of the page the user just chose.
    //
    // Subscribing to the router rather than reacting to a `url` change: the
    // latter would set state during render-after-effect and cascade an extra
    // render on every single navigation, drawer or not.
    useEffect(() => router.on('navigate', () => setSidebarOpen(false)), []);

    /*
     * Opening Snack on a phone shows the sidebar, not a room.
     *
     * The server picks a landing channel and redirects into it — see
     * WorkspaceController::show — which is right on a desktop, where the sidebar
     * is beside it. On a phone the room fills the screen, so the app opened onto
     * one arbitrary conversation with no visible way to reach any other; the
     * only clue there was anywhere else to go was a small header button.
     *
     * Gated on the flash rather than on the URL, because the channel page is the
     * same page whether the server chose it or a shared link did, and a link to
     * a particular conversation must open that conversation.
     */
    const entering = useRef(enteredWorkspace);

    useEffect(() => {
        if (entering.current && isMobile) {
            setSidebarOpen(true);
        }

        // Whatever the viewport was on arrival is the answer. Without this a
        // later rotation or resize would reopen the drawer over a room the
        // reader has since chosen for themselves.
        entering.current = false;
    }, [isMobile]);

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
                            <HuddleSync userId={currentUser.id} />
                        </>
                    )}

                    <div className="hidden md:flex">
                        <WorkspaceRail
                            currentWorkspaceSlug={current.workspace.slug}
                        />

                        {/*
                         * Between the workspaces and their contents, because
                         * that is what it selects: which part of *this*
                         * workspace you are in. Every destination in it is
                         * workspace-scoped, so it belongs on the workspace side
                         * of the rail that switches them.
                         */}
                        <NavRail />
                    </div>

                    {/*
                     * Activity gets the full width. It is a reading view over
                     * things that came from all over the workspace, and a list
                     * of rooms beside it is a list of places none of those rows
                     * are in — the rail on the left is enough to leave by.
                     */}
                    {!isActivityView && (
                        <aside className="hidden w-64 shrink-0 border-r border-sidebar-border md:block lg:w-72">
                            <WorkspaceSidebar />
                        </aside>
                    )}

                    {isMobile && (
                        <Sheet open={sidebarOpen} onOpenChange={setSidebarOpen}>
                            {/*
                             * Full width, overriding the sheet's own `w-3/4`
                             * and `sm:max-w-sm` — both of which are sized for a
                             * drawer beside content, and this one is standing in
                             * for the whole navigation column.
                             */}
                            <SheetContent
                                side="left"
                                className="w-full max-w-none p-0 sm:max-w-none"
                            >
                                <SheetTitle className="sr-only">
                                    {current.workspace.name} navigation
                                </SheetTitle>

                                <div className="flex h-full">
                                    <WorkspaceRail
                                        currentWorkspaceSlug={
                                            current.workspace.slug
                                        }
                                    />
                                    <NavRail
                                        onNavigate={() => setSidebarOpen(false)}
                                    />
                                    <div className="min-w-0 flex-1">
                                        <WorkspaceSidebar
                                            inDrawer
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
